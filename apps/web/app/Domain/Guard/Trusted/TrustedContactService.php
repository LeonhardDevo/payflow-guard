<?php

declare(strict_types=1);

namespace App\Domain\Guard\Trusted;

use App\Audit\Auditor;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Guard\Enums\TrustedConfirmationStatus;
use App\Domain\Guard\Enums\TrustedContactStatus;
use App\Domain\Guard\GuardException;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Notifications\Email\DomainMailer;
use App\Domain\Notifications\Email\EmailSendV2;
use App\Domain\Notifications\Routing\GuardNotifications;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\Clock;
use App\Locale\WithLocale;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

final readonly class TrustedContactService
{
    public const int VERIFICATION_DAYS = 7;

    public function __construct(
        private Clock $clock,
        private Auditor $auditor,
        private GuardAlerts $alerts,
        private GuardNotifications $notifications,
        private DomainMailer $mailer,
    ) {}

    public static function current(Workspace|int $workspace): ?TrustedContact
    {
        return TrustedContact::query()
            ->forWorkspace($workspace instanceof Workspace ? $workspace->id : $workspace)
            ->where('status', '!=', TrustedContactStatus::Revoked->value)
            ->latest('id')
            ->first();
    }

    public function add(Workspace $workspace, User $actor, string $name, string $email, string $locale): TrustedContact
    {
        $email = mb_strtolower(trim($email));
        $isMember = $workspace->members()->whereRaw('lower(users.email) = ?', [$email])->exists();

        if ($isMember) {
            throw new GuardException('trusted_contact_is_member', 422);
        }

        $contact = DB::transaction(function () use ($workspace, $actor, $name, $email, $locale): TrustedContact {
            $existing = self::current($workspace);

            if ($existing !== null) {
                $this->markRevoked($existing, $actor);
            }

            $contact = new TrustedContact;
            $contact->forceFill([
                'workspace_id' => $workspace->id,
                'name' => trim($name),
                'email' => $email,
                'locale' => in_array($locale, ['en', 'ru'], true) ? $locale : 'en',
                'status' => TrustedContactStatus::PendingVerification,
                'created_by' => $actor->id,
            ])->save();

            $this->auditor->record('payflow.trusted_contact.added', $contact, metadata: ['locale' => $contact->locale], actor: $actor, workspace: $workspace, subjectLabel: $contact->name);
            $this->changed($contact, $actor);
            $this->sendVerification($contact, $workspace, $actor);

            return $contact;
        });

        return $contact;
    }

    public function verify(TrustedContact $contact): TrustedContact
    {
        if ($contact->status !== TrustedContactStatus::PendingVerification) {
            return $contact;
        }

        $workspace = Workspace::query()->findOrFail($contact->workspace_id);
        $now = $this->clock->now();
        $contact->forceFill([
            'status' => TrustedContactStatus::Active,
            'verified_at' => $now,
            'active_from' => $now->addHours(WorkspaceProvisioner::settingsFor($workspace)->guard_trust_delay_hours),
        ])->save();

        $this->auditor->record('payflow.trusted_contact.verified', $contact, workspace: $workspace, subjectLabel: $contact->name, system: true);
        $creator = User::query()->find($contact->created_by);

        if ($creator !== null) {
            $this->changed($contact, $creator);
        }

        return $contact;
    }

    public function revoke(TrustedContact $contact, ?User $actor): TrustedContact
    {
        if ($contact->status === TrustedContactStatus::Revoked) {
            return $contact;
        }

        DB::transaction(function () use ($contact, $actor): void {
            $this->markRevoked($contact, $actor);

            $notifyAs = $actor ?? User::query()->find($contact->created_by);

            if ($notifyAs !== null) {
                $this->changed($contact, $notifyAs);
            }
        });

        return $contact;
    }

    public function verificationUrl(TrustedContact $contact, bool $decline = false): string
    {
        return URL::temporarySignedRoute(
            $decline ? 'trusted.verify.decline' : 'trusted.verify',
            $this->clock->now()->addDays(self::VERIFICATION_DAYS),
            ['token' => (string) $contact->id],
        );
    }

    private function markRevoked(TrustedContact $contact, ?User $actor): void
    {
        $contact->forceFill([
            'status' => TrustedContactStatus::Revoked,
            'revoked_at' => $this->clock->now(),
            'revoked_by' => $actor?->id,
        ])->save();

        TrustedConfirmation::query()->forWorkspace($contact->workspace_id)
            ->where('contact_id', $contact->id)
            ->where('status', TrustedConfirmationStatus::Pending->value)
            ->update(['status' => TrustedConfirmationStatus::Canceled->value]);

        $this->auditor->record('payflow.trusted_contact.revoked', $contact, actor: $actor, workspace: $contact->workspace_id, subjectLabel: $contact->name, system: $actor === null);
    }

    private function changed(TrustedContact $contact, User $actor): void
    {
        $alert = $this->alerts->ensure(
            $contact->workspace_id,
            AlertType::TrustedContactChanged,
            AlertSubjectType::TrustedContact,
            $contact->id,
            'tc:'.$contact->id.':'.$contact->status->value,
            ['contact' => $contact->name, 'status' => $contact->status->value],
        );
        $this->notifications->trustedContactChanged($contact, $actor, $alert);
    }

    private function sendVerification(TrustedContact $contact, Workspace $workspace, User $actor): void
    {
        $locale = $contact->locale;
        $verify = $this->verificationUrl($contact);
        $decline = $this->verificationUrl($contact, true);

        WithLocale::run($locale, function () use ($contact, $workspace, $actor, $verify, $decline, $locale): void {
            $lines = [
                (string) __('payflow.trusted.verification.intro', ['owner' => $actor->name, 'workspace' => $workspace->name]),
                (string) __('payflow.trusted.verification.why'),
                (string) __('payflow.trusted.verification.decline_hint'),
            ];
            $verifyLabel = (string) __('payflow.trusted.verification.verify');
            $declineLabel = (string) __('payflow.trusted.verification.decline');
            $html = implode('', array_map(fn (string $line): string => '<p>'.e($line).'</p>', $lines))
                .'<p><a href="'.e($verify).'">'.e($verifyLabel).'</a></p><p><a href="'.e($decline).'">'.e($declineLabel).'</a></p>';
            $text = implode("\n\n", $lines)."\n\n".$verifyLabel.': '.$verify."\n".$declineLabel.': '.$decline;

            $this->mailer->publish(new EmailSendV2(
                to: $contact->email,
                subject: (string) __('payflow.trusted.verification.subject', ['workspace' => $workspace->name]),
                html: $html,
                text: $text,
                reference: ['type' => 'trusted_contact_verification', 'id' => $contact->id],
                workspaceId: $workspace->id,
                toName: $contact->name,
                fromName: (string) config('app.name'),
                locale: $locale,
            ));
        });
    }
}
