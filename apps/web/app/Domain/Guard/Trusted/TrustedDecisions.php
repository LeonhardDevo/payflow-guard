<?php

declare(strict_types=1);

namespace App\Domain\Guard\Trusted;

use App\Audit\Auditor;
use App\Domain\Guard\Enums\TrustedConfirmationStatus;
use App\Domain\Guard\Enums\TrustedContactStatus;
use App\Domain\Guard\Enums\TrustedSubjectType;
use App\Domain\Guard\GuardException;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\ReceivingDetailsVersion;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Payouts\PayoutService;
use App\Domain\Guard\Receiving\ReceivingDetailsService;
use App\Domain\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final readonly class TrustedDecisions
{
    public function __construct(
        private Clock $clock,
        private Auditor $auditor,
        private PayoutService $payouts,
        private ReceivingDetailsService $receiving,
    ) {}

    public function state(TrustedConfirmation $confirmation): string
    {
        if ($confirmation->status === TrustedConfirmationStatus::Pending && $confirmation->expires_at->lessThanOrEqualTo($this->clock->now())) {
            return TrustedConfirmationStatus::Expired->value;
        }

        if ($confirmation->status === TrustedConfirmationStatus::Pending && $confirmation->contact->status !== TrustedContactStatus::Active) {
            return TrustedConfirmationStatus::Canceled->value;
        }

        return $confirmation->status->value;
    }

    public function decide(TrustedConfirmation $confirmation, bool $confirm, ?string $comment, ?string $ip, ?string $userAgent): TrustedConfirmation
    {
        if ($this->state($confirmation) !== TrustedConfirmationStatus::Pending->value) {
            throw new GuardException('confirmation_not_pending', 410);
        }

        $comment = $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 500) : null;

        return DB::transaction(function () use ($confirmation, $confirm, $comment, $ip, $userAgent): TrustedConfirmation {
            $locked = TrustedConfirmation::query()->forWorkspace($confirmation->workspace_id)->lockForUpdate()->findOrFail($confirmation->id);

            if ($locked->status !== TrustedConfirmationStatus::Pending) {
                throw new GuardException('confirmation_not_pending', 410);
            }

            $locked->forceFill([
                'status' => $confirm ? TrustedConfirmationStatus::Confirmed : TrustedConfirmationStatus::Declined,
                'decided_at' => $this->clock->now(),
                'comment' => $comment,
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            ])->save();
            $locked->setRelation('contact', $confirmation->contact);

            match ($locked->subject_type) {
                TrustedSubjectType::PayoutRequest => $this->payouts->trustedDecision(
                    PayoutRequest::query()->forWorkspace($locked->workspace_id)->findOrFail($locked->subject_id),
                    $locked,
                    $confirm,
                    $comment,
                    $ip,
                    $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                ),
                TrustedSubjectType::ReceivingDetailsVersion => $this->receiving->trustedDecision(
                    ReceivingDetailsVersion::query()->forWorkspace($locked->workspace_id)->findOrFail($locked->subject_id),
                    $locked,
                    $confirm,
                    $comment,
                ),
                TrustedSubjectType::VendorBankAccount => null,
            };

            $this->auditor->record($confirm ? 'payflow.trusted_contact.confirmed' : 'payflow.trusted_contact.declined', $locked, metadata: [
                'subject_type' => $locked->subject_type->value,
                'subject_id' => $locked->subject_id,
                'contact' => $confirmation->contact->name,
            ], workspace: $locked->workspace_id, subjectLabel: $confirmation->contact->name, system: true);

            return $locked;
        });
    }
}
