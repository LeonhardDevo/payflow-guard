<?php

declare(strict_types=1);

namespace App\Domain\Guard\Escalation;

use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Enums\TrustedConfirmationStatus;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Receiving\ReceivingDetailsService;
use App\Domain\Guard\Trusted\TrustedConfirmations;
use App\Domain\Notifications\Routing\GuardNotifications;
use App\Domain\Shared\Models\PayflowSettings;
use App\Domain\Shared\Time\Clock;
use Carbon\CarbonImmutable;

final readonly class GuardEscalations
{
    public const int CONFIRMATION_REMINDER_HOURS = 12;

    public function __construct(
        private Clock $clock,
        private GuardAlerts $alerts,
        private GuardNotifications $notifications,
        private TrustedConfirmations $confirmations,
        private ReceivingDetailsService $receiving,
    ) {}

    /** @return array{escalated: int, confirmations_reminded: int, confirmations_expired: int, receiving_details_activated: int} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= $this->clock->now();

        return [
            'escalated' => $this->escalateStale($now),
            'confirmations_reminded' => $this->remindConfirmations($now),
            'confirmations_expired' => $this->expireConfirmations($now),
            'receiving_details_activated' => $this->receiving->activateDue($now),
        ];
    }

    private function escalateStale(CarbonImmutable $now): int
    {
        $hoursByWorkspace = PayflowSettings::query()->withoutGlobalScopes()->pluck('escalate_pending_after_hours', 'workspace_id')->all();
        $count = 0;

        PayoutRequest::query()->withoutGlobalScopes()
            ->with('vendor')
            ->where('status', PayoutStatus::PendingApproval->value)
            ->whereNotNull('submitted_at')
            ->where(fn ($query) => $query->whereNull('last_escalated_at')->orWhere('last_escalated_at', '<', $now->subHours(24)))
            ->orderBy('id')
            ->chunkById(200, function ($payouts) use ($now, $hoursByWorkspace, &$count): void {
                foreach ($payouts as $payout) {
                    $hours = (int) ($hoursByWorkspace[$payout->workspace_id] ?? 24);

                    if ($payout->submitted_at === null || $payout->submitted_at->greaterThanOrEqualTo($now->subHours($hours))) {
                        continue;
                    }

                    $waited = (int) floor($payout->submitted_at->diffInMinutes($now) / 60);
                    $this->alerts->ensure($payout->workspace_id, AlertType::PayoutStaleApproval, AlertSubjectType::PayoutRequest, $payout->id, 'po-stale:'.$payout->id, [
                        'number' => $payout->number,
                        'vendor' => $payout->vendor->name,
                        'hours' => $waited,
                    ]);
                    $payout->forceFill(['last_escalated_at' => $now])->save();
                    $this->notifications->payoutStale($payout, $waited);
                    $count++;
                }
            });

        return $count;
    }

    private function remindConfirmations(CarbonImmutable $now): int
    {
        $due = TrustedConfirmation::query()->withoutGlobalScopes()
            ->with('contact')
            ->where('status', TrustedConfirmationStatus::Pending->value)
            ->whereNull('reminded_at')
            ->where('created_at', '<=', $now->subHours(self::CONFIRMATION_REMINDER_HOURS))
            ->where('expires_at', '>', $now)
            ->get();

        foreach ($due as $confirmation) {
            $this->confirmations->remind($confirmation);
        }

        return $due->count();
    }

    private function expireConfirmations(CarbonImmutable $now): int
    {
        $expired = TrustedConfirmation::query()->withoutGlobalScopes()
            ->with('contact')
            ->where('status', TrustedConfirmationStatus::Pending->value)
            ->where('expires_at', '<=', $now)
            ->get();

        foreach ($expired as $confirmation) {
            $confirmation->forceFill(['status' => TrustedConfirmationStatus::Expired])->save();
            $this->notifications->trustedConfirmationExpired($confirmation);
        }

        return $expired->count();
    }
}
