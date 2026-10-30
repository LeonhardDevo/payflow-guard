<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Invoices;

use App\Audit\Auditor;
use App\Domain\Collections\Email\ClientEmailComposer;
use App\Domain\Collections\Enums\TemplateKey;
use App\Domain\Collections\Templates\DefaultTemplates;
use App\Domain\Notifications\Email\DomainMailer;
use App\Domain\Payments\Links\PaymentLinkService;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class InvoiceIssuer
{
    public function __construct(
        private InvoiceTimeline $timeline,
        private InvoiceSettlement $settlement,
        private PaymentLinkService $links,
        private ClientEmailComposer $composer,
        private DomainMailer $mailer,
        private Auditor $auditor,
        private Clock $clock,
    ) {}

    public function send(Invoice $invoice, User $actor, ?string $message = null, bool $ccMe = false): Invoice
    {
        $client = $invoice->client;

        if ($client->email === null || $client->email === '') {
            throw new PayflowException('no_recipient', 422);
        }

        return DB::transaction(function () use ($invoice, $actor, $message, $ccMe): Invoice {
            if ($invoice->status === InvoiceStatus::Draft) {
                $this->issue($invoice, $actor, $this->clock->now(), InvoiceEventType::Sent);
            } else {
                InvoiceStateMachine::assertIn($invoice->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid]);
                $this->timeline->record($invoice, InvoiceEventType::Sent, ['resent' => true], $actor);
            }

            $this->email($invoice, $actor, $message, $ccMe);
            $this->auditor->record('payflow.invoice.sent', $invoice, metadata: ['to' => $invoice->client->email], actor: $actor, workspace: $invoice->workspace_id, subjectLabel: $invoice->label());

            return $invoice->refresh();
        });
    }

    public function markSent(Invoice $invoice, ?User $actor, ?CarbonImmutable $sentAt = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor, $sentAt): Invoice {
            $this->issue($invoice, $actor, $sentAt ?? $this->clock->now(), InvoiceEventType::MarkedSent);

            return $invoice->refresh();
        });
    }

    public function issue(Invoice $invoice, ?User $actor, CarbonImmutable $sentAt, InvoiceEventType $sentEvent): void
    {
        InvoiceStateMachine::assert($invoice->status, InvoiceStatus::Open);

        if ($invoice->total_minor <= 0) {
            throw new PayflowException('empty_invoice', 422);
        }

        $invoice->forceFill([
            'number' => InvoiceNumbering::assign($invoice),
            'status' => InvoiceStatus::Open,
            'sent_at' => $sentAt,
        ])->save();

        $this->timeline->record($invoice, InvoiceEventType::Issued, ['number' => (string) $invoice->number], $actor, at: $sentAt);
        $this->timeline->record($invoice, $sentEvent, [], $actor, at: $sentAt);
        $this->auditor->record(
            'payflow.invoice.issued',
            $invoice,
            metadata: ['number' => $invoice->number, 'total_minor' => $invoice->total_minor, 'currency' => $invoice->currency, 'mode' => $sentEvent->value],
            actor: $actor,
            workspace: $invoice->workspace_id,
            subjectLabel: $invoice->label(),
            system: $actor === null,
        );

        $this->settlement->settle($invoice, $actor, false);
        $this->links->ensureActive($invoice);
    }

    private function email(Invoice $invoice, User $actor, ?string $message, bool $ccMe): void
    {
        $today = WorkspaceClock::todayIn($invoice->workspace->timezone, $this->clock->now());
        $variables = $this->composer->variables($invoice, $today);
        $template = DefaultTemplates::effective($invoice->workspace_id, TemplateKey::InvoiceIssued, $invoice->locale);
        $body = $message !== null && trim($message) !== ''
            ? str_replace(['{{', '}}'], ['{ {', '} }'], trim($message))."\n\n".$template['body']
            : null;
        $email = $this->composer->compose($invoice, TemplateKey::InvoiceIssued, $variables, null, $body);
        $cc = $invoice->client->cc_emails;

        if ($ccMe && ! in_array($actor->email, $cc, true)) {
            $cc[] = $actor->email;
        }

        $this->mailer->publish($this->composer->envelope(
            $invoice,
            $email,
            ['type' => 'invoice', 'id' => $invoice->id],
            false,
            [ClientEmailComposer::pdfAttachment($invoice)],
            null,
            array_slice($cc, 0, 5),
        ));
    }
}
