<?php

declare(strict_types=1);

namespace App\Http\Resources\Payflow;

use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Receivables\Clients\ClientTerms;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Support\Collection;

final class ClientResource
{
    /** @return array{id: int, name: string, late_payer_class: string} */
    public static function ref(Client $client): array
    {
        return ['id' => $client->id, 'name' => $client->name, 'late_payer_class' => $client->late_payer_class->value];
    }

    /** @return array<string, mixed> */
    public static function option(Client $client): array
    {
        return [
            ...self::ref($client),
            'currency' => $client->currency,
            'locale' => $client->locale,
            'email' => $client->email,
            'payment_terms_days' => ClientTerms::paymentTermsDays($client),
        ];
    }

    /**
     * @param  Collection<int, Client>  $clients
     * @return array<int, int>
     */
    public static function openCounts(Collection $clients): array
    {
        if ($clients->isEmpty()) {
            return [];
        }

        return Invoice::query()->withoutGlobalScopes()
            ->whereIn('client_id', $clients->map(fn (Client $client): int => $client->id)->all())
            ->whereIn('status', Invoice::OUTSTANDING_STATUSES)
            ->whereNull('deleted_at')
            ->selectRaw('client_id, count(*) as total')
            ->groupBy('client_id')
            ->pluck('total', 'client_id')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
    }

    /** @return array<string, mixed> */
    public static function listItem(Client $client, ?int $openInvoices = null): array
    {
        return [
            ...self::ref($client),
            'legal_name' => $client->legal_name,
            'email' => $client->email,
            'country' => $client->country,
            'currency' => $client->currency,
            'locale' => $client->locale,
            'owner' => UserRef::of($client->owner),
            'avg_days_late' => $client->avgDaysLate(),
            'on_time_rate' => $client->onTimeRate(),
            'paid_invoices_count' => $client->paid_invoices_count,
            'open_balance' => MoneyData::of($client->open_balance_minor, $client->currency),
            'overdue_balance' => MoneyData::of($client->overdue_balance_minor, $client->currency),
            'open_invoices_count' => $openInvoices ?? (self::openCounts(collect([$client]))[$client->id] ?? 0),
            'reminders_paused' => $client->reminders_paused,
            'reminders_pause_source' => $client->reminders_pause_source?->value,
            'tags' => $client->tags,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Client $client, Workspace $workspace): array
    {
        $policy = ClientTerms::policy($client);
        $terms = ClientTerms::lateFeeTerms($client);
        $document = $client->contract_document_id !== null ? Document::query()->forWorkspace($workspace)->find($client->contract_document_id) : null;

        return [
            ...self::listItem($client),
            'contact_name' => $client->contact_name,
            'cc_emails' => $client->cc_emails,
            'phone' => $client->phone,
            'tax_id' => $client->tax_id,
            'address' => $client->address,
            'timezone' => $client->timezone,
            'effective_timezone' => WorkspaceClock::zone($client->timezone ?? $workspace->timezone),
            'payment_terms_days' => $client->payment_terms_days,
            'effective_payment_terms_days' => ClientTerms::paymentTermsDays($client),
            'reminder_policy' => $policy instanceof ReminderPolicy
                ? ['id' => $policy->id, 'name' => $policy->name, 'inherited' => $client->reminder_policy_id === null]
                : ['id' => 0, 'name' => '', 'inherited' => true],
            'tone_ceiling' => $client->tone_ceiling->value,
            'reminders_paused_until' => Iso::date($client->reminders_paused_until),
            'pause_reason' => $client->pause_reason,
            'opted_out_at' => $client->isOptedOut() ? Iso::dateTime($client->opted_out_at) : null,
            'late_fee' => [...$terms->toContract($client->currency), 'inherited' => $client->late_fee_type === null],
            'contract_document' => $document !== null ? ['id' => $document->id, 'title' => $document->title, 'status' => $document->status->value] : null,
            'notes' => $client->notes,
            'late_invoices_count' => $client->late_invoices_count,
            'stats_computed_at' => Iso::dateTime($client->stats_computed_at),
            'anonymised_at' => Iso::dateTime($client->anonymised_at),
            'created_at' => Iso::dateTime($client->created_at),
        ];
    }
}
