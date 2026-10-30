<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Invoices;

use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Domain\Collections\Dispatch\ReminderCanceller;
use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Enums\InvoiceSource;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Support\Tokens;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class InvoiceDrafts
{
    public function __construct(
        private PlanLimits $limits,
        private InvoiceTimeline $timeline,
        private InvoiceSettlement $settlement,
        private ReminderCanceller $reminders,
        private Auditor $auditor,
    ) {}

    /**
     * @param  array{client_id: int, number?: string|null, currency: string, locale: string, issue_date: string, due_date: string, po_number?: string|null, notes?: string|null, terms?: string|null, items: list<array{description: string, quantity: string, unit_price_minor: int, tax_rate_bp: int}>}  $data
     */
    public function create(Workspace $workspace, ?User $actor, array $data, InvoiceSource $source = InvoiceSource::Manual, ?string $publicToken = null): Invoice
    {
        $this->limits->ensure($workspace, 'invoices_per_month');
        $client = $this->client($workspace, $data['client_id']);
        $number = self::normalizeNumber($data['number'] ?? null);

        if ($number !== null && InvoiceNumbering::isTaken($workspace->id, $number)) {
            throw new PayflowException('number_taken', 422);
        }

        return DB::transaction(function () use ($workspace, $actor, $data, $client, $number, $source, $publicToken): Invoice {
            $invoice = new Invoice;
            $invoice->forceFill([
                'workspace_id' => $workspace->id,
                'client_id' => $client->id,
                'number' => $number,
                'status' => InvoiceStatus::Draft,
                'currency' => strtoupper($data['currency']),
                'locale' => $data['locale'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'po_number' => $data['po_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'public_token' => $publicToken ?? Tokens::base62(40),
                'source' => $source,
                'created_by' => $actor?->id,
            ])->save();

            InvoiceItems::replace($invoice, $data['items']);
            $this->timeline->record($invoice, InvoiceEventType::Created, ['source' => $source->value], $actor);
            $this->auditor->record(
                'payflow.invoice.created',
                $invoice,
                metadata: ['client_id' => $client->id, 'total_minor' => $invoice->total_minor, 'currency' => $invoice->currency],
                actor: $actor,
                workspace: $workspace,
                subjectLabel: $invoice->label(),
                system: $actor === null,
            );

            return $invoice->refresh();
        });
    }

    /**
     * @param  array{client_id: int, number?: string|null, currency: string, locale: string, issue_date: string, due_date: string, po_number?: string|null, notes?: string|null, terms?: string|null, items: list<array{description: string, quantity: string, unit_price_minor: int, tax_rate_bp: int}>}  $data
     */
    public function updateDraft(Invoice $invoice, User $actor, array $data): Invoice
    {
        InvoiceStateMachine::assertIn($invoice->status, [InvoiceStatus::Draft]);
        $client = $this->client($invoice->workspace, $data['client_id']);
        $number = self::normalizeNumber($data['number'] ?? null);

        if ($number !== null && InvoiceNumbering::isTaken($invoice->workspace_id, $number, $invoice->id)) {
            throw new PayflowException('number_taken', 422);
        }

        return DB::transaction(function () use ($invoice, $actor, $data, $client, $number): Invoice {
            $before = $invoice->only(['client_id', 'number', 'currency', 'locale', 'issue_date', 'due_date', 'po_number', 'total_minor']);
            $invoice->forceFill([
                'client_id' => $client->id,
                'number' => $number,
                'currency' => strtoupper($data['currency']),
                'locale' => $data['locale'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'po_number' => $data['po_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
            ])->save();

            InvoiceItems::replace($invoice, $data['items']);
            $invoice->refresh();
            $this->record($invoice, $actor, $before);

            return $invoice;
        });
    }

    /** @param  array{notes?: string|null, terms?: string|null, po_number?: string|null, due_date: string}  $data */
    public function updateOpen(Invoice $invoice, User $actor, array $data): Invoice
    {
        InvoiceStateMachine::assertIn($invoice->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid]);

        if ($data['due_date'] < $invoice->issueDate()) {
            throw new PayflowException('invalid_transition', 422);
        }

        return DB::transaction(function () use ($invoice, $actor, $data): Invoice {
            $before = $invoice->only(['notes', 'terms', 'po_number', 'due_date']);
            $previousDue = $invoice->dueDate();
            $invoice->forceFill([
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'po_number' => $data['po_number'] ?? null,
                'due_date' => $data['due_date'],
            ])->save();
            $invoice->refresh();

            if ($invoice->dueDate() !== $previousDue) {
                $this->reminders->forInvoice($invoice, ReminderSkipReason::PolicyChanged);
                $this->settlement->refreshRisk($invoice);
            }

            $this->record($invoice, $actor, $before);

            return $invoice;
        });
    }

    public function delete(Invoice $invoice, User $actor): void
    {
        InvoiceStateMachine::assertIn($invoice->status, [InvoiceStatus::Draft]);

        DB::transaction(function () use ($invoice, $actor): void {
            $invoice->delete();
            $this->auditor->record('payflow.invoice.draft_deleted', $invoice, actor: $actor, workspace: $invoice->workspace_id, subjectLabel: $invoice->label());
        });
    }

    public static function normalizeNumber(?string $number): ?string
    {
        $number = $number !== null ? trim($number) : null;

        return $number === null || $number === '' ? null : $number;
    }

    /** @param  array<string, mixed>  $before */
    private function record(Invoice $invoice, User $actor, array $before): void
    {
        $changes = Auditor::diff(self::scalar($before), self::scalar($invoice->only(array_keys($before))));

        if ($changes === []) {
            return;
        }

        $this->timeline->record($invoice, InvoiceEventType::Updated, ['fields' => implode(',', array_keys($changes))], $actor);
        $this->auditor->record('payflow.invoice.updated', $invoice, $changes, actor: $actor, workspace: $invoice->workspace_id, subjectLabel: $invoice->label());
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function scalar(array $values): array
    {
        return array_map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value, $values);
    }

    private function client(Workspace $workspace, int $clientId): Client
    {
        $client = Client::query()->forWorkspace($workspace)->whereKey($clientId)->first();

        if ($client === null) {
            throw new PayflowException('client_not_found', 422);
        }

        return $client;
    }
}
