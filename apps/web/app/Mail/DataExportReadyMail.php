<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\DataExport;
use App\Outbox\OutboxMailTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\App;

final class DataExportReadyMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly DataExport $export,
        public readonly ?string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('emails.privacy.export_ready.subject', ['app' => config('app.name')]));
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            OutboxMailTransport::HEADER_REFERENCE_TYPE => 'data_export',
            OutboxMailTransport::HEADER_REFERENCE_ID => (string) $this->export->id,
            OutboxMailTransport::HEADER_LOCALE => App::getLocale(),
            ...($this->export->workspace_id !== null ? [OutboxMailTransport::HEADER_WORKSPACE => (string) $this->export->workspace_id] : []),
        ]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.privacy.export-ready',
            with: [
                'url' => $this->url ?? route('settings.privacy'),
                'workspaceName' => $this->export->workspace?->name,
                'expiresAt' => $this->export->expires_at
                    ?->settings(['locale' => App::getLocale()])
                    ->isoFormat('LLL').' UTC',
            ],
        );
    }
}
