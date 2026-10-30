<?php

declare(strict_types=1);

namespace App\Domain\Guard\Trusted;

use App\Audit\Auditor;
use App\Domain\Guard\Enums\TrustedConfirmationStatus;
use App\Domain\Guard\Enums\TrustedSubjectType;
use App\Domain\Guard\GuardException;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Notifications\Email\DomainMailer;
use App\Domain\Notifications\Email\EmailSendV2;
use App\Domain\Shared\Money\MoneyFormatter;
use App\Domain\Shared\Support\Tokens;
use App\Domain\Shared\Time\Clock;
use App\Locale\WithLocale;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class TrustedConfirmations
{
    public const int MAX_RESENDS = 3;

    public function __construct(
        private Clock $clock,
        private DomainMailer $mailer,
        private Auditor $auditor,
    ) {}

    /** @param  array<string, string|int|float|null>  $snapshot */
    public function create(TrustedContact $contact, TrustedSubjectType $subjectType, int $subjectId, array $snapshot, User $creator): TrustedConfirmation
    {
        return DB::transaction(function () use ($contact, $subjectType, $subjectId, $snapshot, $creator): TrustedConfirmation {
            TrustedConfirmation::query()->forWorkspace($contact->workspace_id)
                ->where('subject_type', $subjectType->value)
                ->where('subject_id', $subjectId)
                ->where('status', TrustedConfirmationStatus::Pending->value)
                ->update(['status' => TrustedConfirmationStatus::Canceled->value]);

            $token = Tokens::base62(48);
            $confirmation = new TrustedConfirmation;
            $confirmation->forceFill([
                'workspace_id' => $contact->workspace_id,
                'contact_id' => $contact->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'token_hash' => TrustedConfirmation::hashToken($token),
                'snapshot' => $snapshot,
                'status' => TrustedConfirmationStatus::Pending,
                'expires_at' => $this->clock->now()->addHours((int) config('payflow.trusted_confirmation_ttl_hours', 24)),
                'created_by' => $creator->id,
                'created_at' => $this->clock->now(),
            ])->save();

            $this->send($confirmation, $contact, $token);
            $this->auditor->record('payflow.trusted_contact.confirmation_sent', $confirmation, metadata: [
                'subject_type' => $subjectType->value,
                'subject_id' => $subjectId,
            ], actor: $creator, workspace: $contact->workspace_id, subjectLabel: $contact->name);

            return $confirmation;
        });
    }

    public function resend(TrustedConfirmation $confirmation, User $actor): TrustedConfirmation
    {
        if ($confirmation->status !== TrustedConfirmationStatus::Pending) {
            throw new GuardException('confirmation_not_pending');
        }

        if ($confirmation->resend_count >= self::MAX_RESENDS) {
            throw new GuardException('confirmation_resend_limit', 429);
        }

        $token = Tokens::base62(48);
        $confirmation->forceFill([
            'token_hash' => TrustedConfirmation::hashToken($token),
            'resend_count' => $confirmation->resend_count + 1,
            'expires_at' => $this->clock->now()->addHours((int) config('payflow.trusted_confirmation_ttl_hours', 24)),
        ])->save();
        $this->send($confirmation, $confirmation->contact, $token);
        $this->auditor->record('payflow.trusted_contact.confirmation_sent', $confirmation, metadata: ['resend' => $confirmation->resend_count], actor: $actor, workspace: $confirmation->workspace_id);

        return $confirmation;
    }

    public function remind(TrustedConfirmation $confirmation): void
    {
        $token = Tokens::base62(48);
        $confirmation->forceFill([
            'token_hash' => TrustedConfirmation::hashToken($token),
            'reminded_at' => $this->clock->now(),
        ])->save();
        $this->send($confirmation, $confirmation->contact, $token, reminder: true);
    }

    public static function findByToken(string $token): ?TrustedConfirmation
    {
        return TrustedConfirmation::query()->withoutGlobalScopes()->with('contact')->where('token_hash', TrustedConfirmation::hashToken($token))->first();
    }

    private function send(TrustedConfirmation $confirmation, TrustedContact $contact, string $token, bool $reminder = false): void
    {
        $workspace = Workspace::query()->findOrFail($confirmation->workspace_id);
        $locale = in_array($contact->locale, ['en', 'ru'], true) ? $contact->locale : 'en';

        WithLocale::run($locale, function () use ($confirmation, $contact, $token, $reminder, $workspace, $locale): void {
            $snapshot = $confirmation->snapshot ?? [];
            $url = route('trusted.show', ['token' => $token]);
            $subjectKey = $confirmation->subject_type->value;
            $subject = (string) __('payflow.trusted.email.subject.'.$subjectKey, ['workspace' => $workspace->name]);

            if ($reminder) {
                $subject = (string) __('payflow.trusted.email.reminder_prefix').' '.$subject;
            }

            $lines = [(string) __('payflow.trusted.email.intro.'.$subjectKey, ['workspace' => $workspace->name, 'requester' => (string) ($snapshot['requester'] ?? '')])];

            if (isset($snapshot['amount_minor'], $snapshot['currency'])) {
                $lines[] = (string) __('payflow.trusted.email.amount', ['amount' => MoneyFormatter::format((int) $snapshot['amount_minor'], (string) $snapshot['currency'], $locale)]);
            }

            if (isset($snapshot['vendor'])) {
                $lines[] = (string) __('payflow.trusted.email.vendor', ['vendor' => (string) $snapshot['vendor']]);
            }

            if (isset($snapshot['last4'])) {
                $lines[] = (string) __('payflow.trusted.email.last4', ['last4' => (string) $snapshot['last4']]);
            }

            if (isset($snapshot['risk_band'])) {
                $lines[] = (string) __('payflow.trusted.email.risk', [
                    'band' => (string) __('payflow.risk_bands.'.$snapshot['risk_band']),
                    'score' => (int) ($snapshot['risk_score'] ?? 0),
                ]);
            }

            $lines[] = (string) __('payflow.trusted.email.expires', ['hours' => (int) config('payflow.trusted_confirmation_ttl_hours', 24)]);
            $action = (string) __('payflow.trusted.email.action');
            $disclaimer = (string) __('payflow.trusted.email.disclaimer', ['url' => route('legal', ['doc' => 'payment-guard-disclaimer', 'lang' => $locale])]);
            $html = implode('', array_map(fn (string $line): string => '<p>'.e($line).'</p>', $lines))
                .'<p><a href="'.e($url).'">'.e($action).'</a></p><p>'.e($disclaimer).'</p>';
            $text = implode("\n", $lines)."\n\n".$action.': '.$url."\n\n".$disclaimer;

            $this->mailer->publish(new EmailSendV2(
                to: $contact->email,
                subject: $subject,
                html: $html,
                text: $text,
                reference: ['type' => 'trusted_confirmation', 'id' => $confirmation->id],
                workspaceId: $workspace->id,
                toName: $contact->name,
                fromName: (string) config('app.name'),
                locale: $locale,
            ));
        });
    }
}
