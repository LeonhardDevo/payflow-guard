<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use App\Outbox\OutboxMailTransport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

final class EscalationRequested extends InAppNotification
{
    public function __construct(
        public readonly User $requester,
        public readonly Workspace $workspace,
        public readonly string $question,
        public readonly bool $viaMail = true,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->viaMail ? ['database', 'mail'] : ['database'];
    }

    public function inApp(object $notifiable): InAppMessage
    {
        return new InAppMessage(
            kind: 'ai',
            titleKey: 'notifications.in_app.escalation.title',
            titleParams: ['name' => $this->requester->name],
            bodyKey: $this->question !== '' ? 'notifications.in_app.escalation.body' : 'notifications.in_app.escalation.body_empty',
            bodyParams: ['question' => Str::limit($this->question, 160)],
            href: route('assistant.history', ['status' => 'refused'], absolute: false),
            workspaceId: $this->workspace->id,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspaceId = $this->workspace->id;
        $locale = App::getLocale();

        $message = (new MailMessage)
            ->markdown('mail.notification')
            ->subject((string) __('emails.escalation.subject', ['workspace' => $this->workspace->name]))
            ->line((string) __('emails.escalation.intro', [
                'name' => $this->requester->name,
                'workspace' => $this->workspace->name,
            ]));

        if ($this->question !== '') {
            $message->line('> '.$this->question);
        }

        return $message
            ->action((string) __('emails.escalation.action'), route('assistant.history', ['status' => 'refused']))
            ->withSymfonyMessage(function (Email $email) use ($workspaceId, $locale): void {
                $headers = $email->getHeaders();
                $headers->addTextHeader(OutboxMailTransport::HEADER_REFERENCE_TYPE, 'escalation');
                $headers->addTextHeader(OutboxMailTransport::HEADER_REFERENCE_ID, $this->requester->id.'-'.now()->timestamp);
                $headers->addTextHeader(OutboxMailTransport::HEADER_WORKSPACE, (string) $workspaceId);
                $headers->addTextHeader(OutboxMailTransport::HEADER_LOCALE, $locale);
            });
    }
}
