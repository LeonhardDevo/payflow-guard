<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Receivables\Invoices\InvoiceDisplayStatusResolver;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Money\MoneyData;
use App\Http\Resources\Payflow\ClientResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SearchController extends PayflowController
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $empty = ['invoices' => [], 'clients' => [], 'vendors' => [], 'payouts' => []];

        if (mb_strlen($q) < 2) {
            return response()->json($empty);
        }

        $role = $this->role();
        $today = $this->today();
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';

        return response()->json([
            'invoices' => $role->allows('invoices.view') ? Invoice::query()->with('client')
                ->where(fn ($query) => $query->whereRaw('lower(coalesce(number, \'\')) like ?', [$like])
                    ->orWhereHas('client', fn ($client) => $client->whereRaw('lower(name) like ?', [$like])))
                ->orderByDesc('issue_date')
                ->limit(5)
                ->get()
                ->map(fn (Invoice $invoice): array => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'client' => ClientResource::ref($invoice->client),
                    'balance_due' => MoneyData::of($invoice->balanceDue(), $invoice->currency),
                    'display_status' => InvoiceDisplayStatusResolver::for($invoice, $today)->value,
                ])->values()->all() : [],
            'clients' => $role->allows('clients.view') ? Client::query()
                ->where(fn ($query) => $query->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(coalesce(legal_name, \'\')) like ?', [$like]))
                ->orderBy('name')
                ->limit(5)
                ->get()
                ->map(fn (Client $client): array => ClientResource::ref($client))->values()->all() : [],
            'vendors' => $role->allows('vendors.view') ? Vendor::query()
                ->where(fn ($query) => $query->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(coalesce(legal_name, \'\')) like ?', [$like]))
                ->orderBy('name')
                ->limit(5)
                ->get()
                ->map(fn (Vendor $vendor): array => ['id' => $vendor->id, 'name' => $vendor->name, 'status' => $vendor->status->value])->values()->all() : [],
            'payouts' => $role->allows('payouts.view') ? PayoutRequest::query()->with('vendor')
                ->whereRaw('lower(number) like ?', [$like])
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(fn (PayoutRequest $payout): array => [
                    'id' => $payout->id,
                    'number' => $payout->number,
                    'vendor' => ['id' => $payout->vendor->id, 'name' => $payout->vendor->name, 'status' => $payout->vendor->status->value],
                ])->values()->all() : [],
        ]);
    }
}
