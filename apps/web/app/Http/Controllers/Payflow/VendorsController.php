<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Billing\PlanLimits;
use App\Domain\Analytics\GuardSummary;
use App\Domain\Guard\Bank\BankDetails;
use App\Domain\Guard\BankChanges\BankChangeService;
use App\Domain\Guard\Enums\AlertStatus;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\BankChangeSource;
use App\Domain\Guard\Enums\VendorCategory;
use App\Domain\Guard\Enums\VendorStatus;
use App\Domain\Guard\GuardException;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Vendors\VendorService;
use App\Domain\Privacy\UnmaskedBankExport;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Time\Clock;
use App\Enums\WorkspaceRole;
use App\Http\Requests\Payflow\BankChangeRequest;
use App\Http\Requests\Payflow\VendorRequest;
use App\Http\Resources\Payflow\GuardResource;
use App\Http\Resources\Payflow\Paginated;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class VendorsController extends PayflowController
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(VendorStatus::class)],
            'category' => ['nullable', Rule::enum(VendorCategory::class)],
            'attention' => ['nullable', Rule::in(['pending_change', 'on_hold'])],
        ]);
        $pendingIds = VendorBankAccount::query()->forWorkspace($workspace)
            ->where('status', BankAccountStatus::PendingVerification->value)
            ->pluck('vendor_id')
            ->all();
        $query = Vendor::query()->forWorkspace($workspace)->orderBy('name');
        $search = isset($filters['q']) ? '%'.addcslashes((string) $filters['q'], '%_\\').'%' : null;

        if ($search !== null) {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->whereRaw('name ilike ?', [$search])->orWhereRaw('legal_name ilike ?', [$search]);
            });
        }

        if (isset($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (isset($filters['category'])) {
            $query->where('category', (string) $filters['category']);
        }

        if (($filters['attention'] ?? null) === 'on_hold') {
            $query->where('status', VendorStatus::OnHold->value);
        }

        if (($filters['attention'] ?? null) === 'pending_change') {
            $query->whereIn('id', $pendingIds);
        }

        $paginator = $query->paginate(25)->withQueryString();

        return Inertia::render('vendors/Index', [
            'vendors' => Paginated::of($paginator, $paginator->getCollection()->map(fn (Vendor $vendor): array => GuardResource::vendorListItem($vendor))->all()),
            'filters' => [
                'q' => $filters['q'] ?? null,
                'status' => $filters['status'] ?? null,
                'category' => $filters['category'] ?? null,
                'attention' => $filters['attention'] ?? null,
            ],
            'counts' => [
                'all' => Vendor::query()->forWorkspace($workspace)->count(),
                'pending_change' => Vendor::query()->forWorkspace($workspace)->whereIn('id', $pendingIds)->count(),
                'on_hold' => Vendor::query()->forWorkspace($workspace)->where('status', VendorStatus::OnHold->value)->count(),
            ],
            'processing_notice' => $this->role()->allows('guard.configure') && ! Cache::has(self::noticeKey($this->user($request)->id, $workspace->id)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('vendors/Create', $this->formProps(null));
    }

    public function store(VendorRequest $request): RedirectResponse
    {
        $vendor = $this->vendors()->create($this->workspace(), $this->user($request), $request->validated());

        return $this->to('vendors.show', $vendor->id, 'vendor_created');
    }

    public function show(Request $request, Vendor $vendor): Response
    {
        $this->authorize('view', $vendor);
        $user = $this->user($request);
        $role = $this->role();

        return Inertia::render('vendors/Show', [
            'vendor' => GuardResource::vendorDetail($vendor),
            'accounts' => VendorBankAccount::query()->forWorkspace($vendor->workspace_id)
                ->with(['requester', 'verifier', 'rejecter'])
                ->where('vendor_id', $vendor->id)
                ->orderByDesc('version')
                ->get()
                ->map(fn (VendorBankAccount $account): array => GuardResource::bankAccount($account))
                ->all(),
            'payouts' => PayoutRequest::query()->forWorkspace($vendor->workspace_id)
                ->with(['vendor', 'requester'])
                ->where('vendor_id', $vendor->id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn (PayoutRequest $payout): array => GuardResource::payoutListItem($payout, $user, $role))
                ->all(),
            'alerts' => GuardAlert::query()->forWorkspace($vendor->workspace_id)
                ->with('acknowledger')
                ->where('status', '!=', AlertStatus::Resolved->value)
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $inner) => $inner->where('subject_type', AlertSubjectType::Vendor->value)->where('subject_id', $vendor->id))
                    ->orWhere(fn (Builder $inner) => $inner->where('subject_type', AlertSubjectType::VendorBankAccount->value)
                        ->whereIn('subject_id', VendorBankAccount::query()->forWorkspace($vendor->workspace_id)->where('vendor_id', $vendor->id)->select('id')))
                    ->orWhere(fn (Builder $inner) => $inner->where('subject_type', AlertSubjectType::PayoutRequest->value)
                        ->whereIn('subject_id', PayoutRequest::query()->forWorkspace($vendor->workspace_id)->where('vendor_id', $vendor->id)->select('id'))))
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (GuardAlert $alert): array => GuardSummary::alertItem($alert))
                ->all(),
            'can' => $this->pageCan([
                'edit' => $user->can('update', $vendor),
                'hold' => $user->can('update', $vendor),
                'request_change' => $user->can('requestChange', $vendor),
                'reveal' => $user->can('reveal', $vendor),
                'create_payout' => $role->allows('payouts.create') && $vendor->status === VendorStatus::Active,
                'anonymise' => $user->can('anonymise', $vendor),
            ]),
        ]);
    }

    public function edit(Vendor $vendor): Response
    {
        $this->authorize('update', $vendor);

        return Inertia::render('vendors/Edit', $this->formProps($vendor));
    }

    public function update(VendorRequest $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('update', $vendor);
        $data = $request->validated();
        unset($data['bank']);
        $this->vendors()->update($vendor, $this->user($request), $data);

        return $this->to('vendors.show', $vendor->id, 'vendor_updated');
    }

    public function hold(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('update', $vendor);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        $this->vendors()->hold($vendor, $this->user($request), (string) $data['reason']);

        return $this->back('vendor_held');
    }

    public function releaseHold(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('update', $vendor);
        $this->vendors()->releaseHold($vendor, $this->user($request));

        return $this->back('vendor_hold_released');
    }

    public function destroy(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('update', $vendor);
        $this->vendors()->archive($vendor, $this->user($request));

        return $this->to('vendors.index', [], 'vendor_archived');
    }

    public function anonymise(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('anonymise', $vendor);
        $data = $request->validate(['confirm_name' => ['required', 'string']]);

        if (mb_strtolower(trim((string) $data['confirm_name'])) !== mb_strtolower($vendor->name)) {
            throw new GuardException('confirm_name_mismatch', 422);
        }

        $this->vendors()->anonymise($vendor, $this->user($request));

        return $this->to('vendors.show', $vendor->id, 'vendor_anonymised');
    }

    public function createBankChange(Vendor $vendor): Response
    {
        $this->authorize('requestChange', $vendor);
        $current = $vendor->current_bank_account_id !== null ? VendorBankAccount::query()->forWorkspace($vendor->workspace_id)
            ->with(['requester', 'verifier', 'rejecter'])->find($vendor->current_bank_account_id) : null;

        return Inertia::render('vendors/BankChangeCreate', [
            'vendor' => GuardResource::vendorDetail($vendor),
            'current' => $current !== null ? GuardResource::bankAccount($current) : null,
            'countries' => ClientsController::countries(),
            'currencies' => self::currencies(),
            'sources' => array_map(fn (string $source): array => ['value' => $source, 'label' => (string) __('payflow.sources.'.$source)], BankChangeRequest::SOURCES),
        ]);
    }

    public function storeBankChange(BankChangeRequest $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('requestChange', $vendor);
        $data = $request->validated();
        $account = $this->bankChanges()->request(
            $vendor,
            $this->user($request),
            BankDetails::fromInput($data),
            BankChangeSource::from((string) $data['source']),
            isset($data['requester_contact']) ? (string) $data['requester_contact'] : null,
            isset($data['change_request_text']) ? (string) $data['change_request_text'] : null,
            isset($data['received_at']) ? CarbonImmutable::parse((string) $data['received_at'])->utc() : null,
        );

        return $this->to('guard.bank-changes.show', $account->id, 'bank_change_requested');
    }

    public function reveal(Request $request, Vendor $vendor, VendorBankAccount $account): JsonResponse
    {
        $this->authorize('reveal', $vendor);
        abort_unless($account->vendor_id === $vendor->id, 404);

        return response()->json($this->bankChanges()->reveal($account, $this->user($request)))
            ->header('Cache-Control', 'no-store');
    }

    public function exportUnmasked(Request $request, UnmaskedBankExport $export): JsonResponse
    {
        abort_unless($this->role() === WorkspaceRole::Owner, 403);
        $workspace = $this->workspace();
        $data = $export->build($workspace, $this->user($request), (string) Iso::dateTime(app(Clock::class)->now()));

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="payflow-bank-details-'.$workspace->id.'.json"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function dismissNotice(Request $request): RedirectResponse
    {
        Cache::forever(self::noticeKey($this->user($request)->id, $this->workspace()->id), true);

        return redirect()->back(303);
    }

    public static function noticeKey(int $userId, int $workspaceId): string
    {
        return 'pf-vendor-notice:'.$workspaceId.':'.$userId;
    }

    /** @return array<string, mixed> */
    private function formProps(?Vendor $vendor): array
    {
        $workspace = $this->workspace();
        $limits = app(PlanLimits::class);

        return [
            'vendor' => $vendor !== null ? GuardResource::vendorDetail($vendor) : null,
            'categories' => array_map(fn (VendorCategory $category): array => ['value' => $category->value, 'label' => (string) __('payflow.vendor_categories.'.$category->value)], VendorCategory::cases()),
            'countries' => ClientsController::countries(),
            'currencies' => self::currencies(),
            'limits' => ['vendors' => ['used' => $limits->used($workspace, 'vendors'), 'limit' => $limits->limit($workspace, 'vendors')]],
        ];
    }

    private function vendors(): VendorService
    {
        return app(VendorService::class);
    }

    private function bankChanges(): BankChangeService
    {
        return app(BankChangeService::class);
    }
}
