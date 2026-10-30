<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Outbox\OutboxMailTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\App;

final class WorkspaceInvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly WorkspaceInvitation $invitation,
        public readonly string $token,
        public readonly Workspace $workspace,
        public readonly User $inviter,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) __('emails.invitation.subject', [
                'inviter' => $this->inviter->name,
                'workspace' => $this->workspace->name,
                'app' => config('app.name'),
            ]),
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            OutboxMailTransport::HEADER_REFERENCE_TYPE => 'invitation',
            OutboxMailTransport::HEADER_REFERENCE_ID => (string) $this->invitation->id,
            OutboxMailTransport::HEADER_WORKSPACE => (string) $this->workspace->id,
            OutboxMailTransport::HEADER_LOCALE => App::getLocale(),
        ]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invitation',
            with: [
                'url' => route('invitations.show', ['token' => $this->token]),
                'workspaceName' => $this->workspace->name,
                'inviterName' => $this->inviter->name,
                'roleLabel' => $this->invitation->role->label(),
                'expiresAt' => $this->invitation->expires_at
                    ->setTimezone($this->workspace->timezone)
                    ->settings(['locale' => App::getLocale()])
                    ->isoFormat('LL'),
            ],
        );
    }
}
