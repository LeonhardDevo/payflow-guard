<?php

declare(strict_types=1);

namespace App\Billing\Mail;

use App\Models\Subscription;
use App\Models\Workspace;
use App\Outbox\OutboxMailTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\App;

final class TrialEndingMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly Subscription $subscription,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('emails.billing.trial_ending.subject', [
            'app' => config('app.name'),
            'workspace' => $this->workspace->name,
        ]));
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            OutboxMailTransport::HEADER_REFERENCE_TYPE => 'subscription',
            OutboxMailTransport::HEADER_REFERENCE_ID => (string) $this->subscription->id,
            OutboxMailTransport::HEADER_LOCALE => App::getLocale(),
            OutboxMailTransport::HEADER_WORKSPACE => (string) $this->workspace->id,
        ]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.billing.trial-ending',
            with: [
                'workspaceName' => $this->workspace->name,
                'planName' => (string) __("billing.plans.{$this->subscription->plan}.name"),
                'endsAt' => $this->subscription->trial_ends_at?->copy()->settings(['locale' => App::getLocale()])->isoFormat('LL'),
                'cancelAtPeriodEnd' => $this->subscription->cancel_at_period_end,
                'url' => route('settings.billing'),
            ],
        );
    }
}
