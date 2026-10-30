<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Clients;

use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Domain\Collections\Dispatch\ReminderCanceller;
use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Receivables\Enums\ClientPauseSource;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Time\Clock;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class ClientService
{
    public const array FIELDS = [
        'name', 'legal_name', 'contact_name', 'email', 'cc_emails', 'phone', 'country', 'timezone', 'tax_id', 'address', 'locale', 'currency',
        'payment_terms_days', 'reminder_policy_id', 'tone_ceiling', 'late_fee_type', 'late_fee_rate_bp', 'late_fee_fixed_minor',
        'late_fee_grace_days', 'late_fee_cap_bp', 'contract_document_id', 'owner_user_id', 'tags', 'notes', 'external_ref',
    ];

    public function __construct(
        private PlanLimits $limits,
        private ReminderCanceller $reminders,
        private InvoiceTimeline $timeline,
        private GuardAlerts $alerts,
        private Auditor $auditor,
        private Clock $clock,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(Workspace $workspace, ?User $actor, array $data): Client
    {
        $this->limits->ensure($workspace, 'clients');
        $this->assertUniqueName($workspace->id, (string) $data['name']);

        return DB::transaction(function () use ($workspace, $actor, $data): Client {
            $client = new Client;
            $client->forceFill([
                ...array_intersect_key($data, array_flip(self::FIELDS)),
                'workspace_id' => $workspace->id,
                'locale' => $data['locale'] ?? $workspace->locale,
                'currency' => strtoupper((string) ($data['currency'] ?? $workspace->currency)),
                'created_by' => $actor?->id,
            ])->save();

            $this->auditor->record('payflow.client.created', $client, actor: $actor, workspace: $workspace, subjectLabel: $client->name, system: $actor === null);

            return $client->refresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Client $client, User $actor, array $data): Client
    {
        if (isset($data['name'])) {
            $this->assertUniqueName($client->workspace_id, (string) $data['name'], $client->id);
        }

        return DB::transaction(function () use ($client, $actor, $data): Client {
            $fields = array_intersect_key($data, array_flip(self::FIELDS));
            $before = $client->only(array_keys($fields));
            $policyBefore = $client->reminder_policy_id;
            $client->forceFill($fields);

            if (isset($fields['currency'])) {
                $client->currency = strtoupper((string) $fields['currency']);
            }

            $client->save();
            $client->refresh();

            if ($client->reminder_policy_id !== $policyBefore) {
                $this->reminders->forClient($client, ReminderSkipReason::PolicyChanged);
            }

            $changes = Auditor::diff(self::comparable($before), self::comparable($client->only(array_keys($fields))));

            if ($changes !== []) {
                $this->auditor->record('payflow.client.updated', $client, $changes, actor: $actor, workspace: $client->workspace_id, subjectLabel: $client->name);
            }

            return $client;
        });
    }

    public function delete(Client $client, User $actor): void
    {
        $open = Invoice::query()->forWorkspace($client->workspace_id)
            ->where('client_id', $client->id)
            ->whereIn('status', [...Invoice::OUTSTANDING_STATUSES, 'draft'])
            ->exists();

        if ($open) {
            throw new PayflowException('client_has_open_invoices', 409);
        }

        DB::transaction(function () use ($client, $actor): void {
            $client->delete();
            $this->auditor->record('payflow.client.deleted', $client, actor: $actor, workspace: $client->workspace_id, subjectLabel: $client->name);
        });
    }

    public function pause(Client $client, User $actor, bool $paused, ?string $until, ?string $reason, bool $acknowledgeOptOut = false): Client
    {
        if (! $paused && $client->isOptedOut() && ! $acknowledgeOptOut) {
            throw new PayflowException('opt_out_acknowledgement', 422);
        }

        return DB::transaction(function () use ($client, $actor, $paused, $until, $reason): Client {
            if ($paused) {
                $client->forceFill([
                    'reminders_paused' => true,
                    'reminders_paused_until' => $until,
                    'reminders_pause_source' => ClientPauseSource::Manual,
                    'pause_reason' => $reason,
                ])->save();
                $this->reminders->forClient($client, ReminderSkipReason::ClientPaused);
                $this->eachOpenInvoice($client, fn (Invoice $invoice) => $this->timeline->record($invoice, InvoiceEventType::RemindersPaused, ['scope' => 'client', 'until' => $until], $actor));
                $this->auditor->record('payflow.client.reminders_paused', $client, metadata: ['until' => $until, 'reason' => $reason], actor: $actor, workspace: $client->workspace_id, subjectLabel: $client->name);

                return $client->refresh();
            }

            $afterOptOut = $client->isOptedOut();
            $client->forceFill([
                'reminders_paused' => false,
                'reminders_paused_until' => null,
                'reminders_pause_source' => null,
                'pause_reason' => null,
                'opted_out_at' => null,
            ])->save();
            $this->alerts->resolve($client->workspace_id, 'opt:'.$client->id, $actor);
            $this->eachOpenInvoice($client, fn (Invoice $invoice) => $this->timeline->record($invoice, InvoiceEventType::RemindersResumed, ['scope' => 'client', 'after_opt_out' => $afterOptOut], $actor));
            $this->auditor->record('payflow.client.reminders_resumed', $client, metadata: ['after_opt_out' => $afterOptOut], actor: $actor, workspace: $client->workspace_id, subjectLabel: $client->name);

            return $client->refresh();
        });
    }

    public function anonymise(Client $client, User $actor, bool $naturalPerson): Client
    {
        $open = Invoice::query()->forWorkspace($client->workspace_id)
            ->where('client_id', $client->id)
            ->whereIn('status', Invoice::OUTSTANDING_STATUSES)
            ->exists();

        if ($open) {
            throw new PayflowException('has_open_items', 409);
        }

        return DB::transaction(function () use ($client, $actor, $naturalPerson): Client {
            $label = (string) trans('payflow.clients.anonymised_name', ['id' => $client->id], 'en');
            $client->forceFill([
                'contact_name' => null,
                'email' => null,
                'cc_emails' => [],
                'phone' => null,
                'address' => null,
                'tax_id' => null,
                'notes' => null,
                'reminders_paused' => true,
                'reminders_pause_source' => ClientPauseSource::Manual,
                'reminders_paused_until' => null,
                'anonymised_at' => $this->clock->now(),
                ...($naturalPerson ? ['name' => $label, 'legal_name' => null] : []),
            ])->save();

            $this->reminders->forClient($client, ReminderSkipReason::ClientPaused);
            Reminder::query()->forWorkspace($client->workspace_id)->where('client_id', $client->id)->update([
                'recipient_email' => null,
                'cc_emails' => '[]',
                'body_html' => null,
                'body_text' => null,
            ]);

            $this->auditor->record('payflow.client.anonymised', $client, metadata: ['natural_person' => $naturalPerson], actor: $actor, workspace: $client->workspace_id, subjectLabel: $client->name);

            return $client->refresh();
        });
    }

    /** @param  \Closure(Invoice): mixed  $callback */
    private function eachOpenInvoice(Client $client, \Closure $callback): void
    {
        Invoice::query()->forWorkspace($client->workspace_id)
            ->where('client_id', $client->id)
            ->whereIn('status', Invoice::OUTSTANDING_STATUSES)
            ->get()
            ->each($callback);
    }

    private function assertUniqueName(int $workspaceId, string $name, ?int $exceptId = null): void
    {
        $exists = Client::query()->forWorkspace($workspaceId)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw new PayflowException('client_name_taken', 422);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function comparable(array $values): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_array($value) => json_encode($value),
            default => $value,
        }, $values);
    }
}
