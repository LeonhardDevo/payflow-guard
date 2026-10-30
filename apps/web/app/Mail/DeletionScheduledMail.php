<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\DeletionRequest;
use App\Outbox\OutboxMailTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\App;

final class DeletionScheduledMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly DeletionRequest $request,
        public readonly string $recipientName,
        public readonly ?string $workspaceName,
    ) {}

    public function envelope(): Envelope
    {
        $key = $this->workspaceName !== null ? 'emails.privacy.workspace_deletion.subject' : 'emails.privacy.account_deletion.subject';

        return new Envelope(subject: (string) __($key, ['app' => config('app.name'), 'workspace' => (string) $this->workspaceName]));
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            OutboxMailTransport::HEADER_REFERENCE_TYPE => 'deletion_request',
            OutboxMailTransport::HEADER_REFERENCE_ID => (string) $this->request->id,
            OutboxMailTransport::HEADER_LOCALE => App::getLocale(),
        ]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.privacy.deletion-scheduled',
            with: [
                'url' => route('settings.privacy'),
                'name' => $this->recipientName,
                'workspaceName' => $this->workspaceName,
                'scheduledFor' => $this->request->scheduled_for
                    ->settings(['locale' => App::getLocale()])
                    ->isoFormat('LL'),
            ],
        );
    }
}
