<?php

declare(strict_types=1);

namespace App\Domain\Guard\Vendors;

use App\Audit\Auditor;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Bank\BankDetails;
use App\Domain\Guard\BankChanges\BankChangeService;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Guard\Enums\BankChangeSource;
use App\Domain\Guard\Enums\PayoutHoldReason;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Enums\VendorCategory;
use App\Domain\Guard\Enums\VendorStatus;
use App\Domain\Guard\GuardException;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Payouts\PayoutService;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class VendorService
{
    public const array FIELDS = ['name', 'legal_name', 'category', 'email', 'phone', 'country', 'default_currency', 'tax_id', 'notes'];

    public function __construct(
        private Clock $clock,
        private Auditor $auditor,
        private GuardAlerts $alerts,
        private PayoutService $payouts,
        private BankChangeService $bankChanges,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(Workspace $workspace, User $actor, array $data): Vendor
    {
        $this->assertUniqueName($workspace->id, (string) $data['name'], null);

        $vendor = DB::transaction(function () use ($workspace, $actor, $data): Vendor {
            $vendor = new Vendor;
            $vendor->forceFill([
                'workspace_id' => $workspace->id,
                ...$this->attributes($data),
                'status' => VendorStatus::Active,
                'phone_verified_at' => null,
                'created_by' => $actor->id,
            ])->save();

            $this->auditor->record('payflow.vendor.created', $vendor, metadata: ['category' => $vendor->category->value], actor: $actor, workspace: $workspace, subjectLabel: $vendor->name);

            return $vendor->refresh();
        });

        if (is_array($data['bank'] ?? null) && $data['bank'] !== []) {
            $this->bankChanges->request($vendor, $actor, BankDetails::fromInput($data['bank']), BankChangeSource::Onboarding);
        }

        return $vendor->refresh();
    }

    /** @param  array<string, mixed>  $data */
    public function update(Vendor $vendor, User $actor, array $data): Vendor
    {
        $this->assertUniqueName($vendor->workspace_id, (string) $data['name'], $vendor->id);

        return DB::transaction(function () use ($vendor, $actor, $data): Vendor {
            $before = $vendor->only(self::FIELDS);
            $before['category'] = $vendor->category->value;
            $phoneBefore = $vendor->phone;
            $attributes = $this->attributes($data);

            if (! array_key_exists('tax_id', $data)) {
                unset($attributes['tax_id']);
            }

            $vendor->forceFill($attributes);
            $phoneChanged = self::phoneKey($phoneBefore) !== self::phoneKey($vendor->phone);

            if ($phoneChanged) {
                $vendor->forceFill(['phone_verified_at' => null, 'phone_changed_at' => $this->clock->now()]);
            }

            $vendor->save();
            $after = $vendor->only(self::FIELDS);
            $after['category'] = $vendor->category->value;
            $changes = Auditor::diff($before, $after);

            if (isset($changes['tax_id'])) {
                $changes['tax_id'] = ['***', '***'];
            }

            if ($changes !== []) {
                $this->auditor->record('payflow.vendor.updated', $vendor, $changes, actor: $actor, workspace: $vendor->workspace_id, subjectLabel: $vendor->name);
            }

            if ($phoneChanged) {
                $this->auditor->record('payflow.vendor.phone_changed', $vendor, metadata: [
                    'from_last3' => self::last3($phoneBefore),
                    'to_last3' => self::last3($vendor->phone),
                ], actor: $actor, workspace: $vendor->workspace_id, subjectLabel: $vendor->name);
                $date = WorkspaceClock::todayIn(Workspace::query()->findOrFail($vendor->workspace_id)->timezone, $this->clock->now())->toDateString();
                $this->alerts->ensure($vendor->workspace_id, AlertType::VendorPhoneChanged, AlertSubjectType::Vendor, $vendor->id, 'vphone:'.$vendor->id.':'.$date, [
                    'vendor' => $vendor->name,
                    'last3' => (string) self::last3($vendor->phone),
                ]);
            }

            return $vendor;
        });
    }

    public function hold(Vendor $vendor, User $actor, string $reason): Vendor
    {
        if ($vendor->status === VendorStatus::Archived) {
            throw new GuardException('vendor_archived');
        }

        return DB::transaction(function () use ($vendor, $actor, $reason): Vendor {
            $vendor->forceFill(['status' => VendorStatus::OnHold, 'hold_reason' => mb_substr(trim($reason), 0, 255)])->save();
            $held = $this->payouts->holdVendorPayouts($vendor, PayoutHoldReason::VendorOnHold, $actor);
            $this->auditor->record('payflow.vendor.held', $vendor, metadata: ['reason' => trim($reason), 'payouts_held' => $held], actor: $actor, workspace: $vendor->workspace_id, subjectLabel: $vendor->name);

            return $vendor;
        });
    }

    public function releaseHold(Vendor $vendor, User $actor): Vendor
    {
        if ($vendor->status !== VendorStatus::OnHold) {
            throw new GuardException('vendor_not_on_hold');
        }

        $vendor->forceFill(['status' => VendorStatus::Active, 'hold_reason' => null])->save();
        $this->auditor->record('payflow.vendor.hold_released', $vendor, actor: $actor, workspace: $vendor->workspace_id, subjectLabel: $vendor->name);

        return $vendor;
    }

    public function archive(Vendor $vendor, User $actor): void
    {
        $open = PayoutRequest::query()->forWorkspace($vendor->workspace_id)
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', [PayoutStatus::PendingApproval->value, PayoutStatus::Approved->value, PayoutStatus::OnHold->value])
            ->exists();

        if ($open) {
            throw new GuardException('has_open_items');
        }

        DB::transaction(function () use ($vendor, $actor): void {
            $vendor->forceFill(['status' => VendorStatus::Archived])->save();
            $vendor->delete();
            $this->auditor->record('payflow.vendor.archived', $vendor, actor: $actor, workspace: $vendor->workspace_id, subjectLabel: $vendor->name);
        });
    }

    public function anonymise(Vendor $vendor, User $actor): Vendor
    {
        $open = PayoutRequest::query()->forWorkspace($vendor->workspace_id)
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', [PayoutStatus::PendingApproval->value, PayoutStatus::Approved->value, PayoutStatus::OnHold->value])
            ->exists();

        if ($open) {
            throw new GuardException('has_open_items');
        }

        return DB::transaction(function () use ($vendor, $actor): Vendor {
            $vendor->forceFill([
                'email' => null,
                'phone' => null,
                'phone_verified_at' => null,
                'tax_id' => null,
                'notes' => null,
                'anonymised_at' => $this->clock->now(),
            ])->save();

            $unreleased = PayoutRequest::query()->forWorkspace($vendor->workspace_id)
                ->where('vendor_id', $vendor->id)
                ->whereIn('status', [PayoutStatus::Draft->value])
                ->pluck('vendor_bank_account_id')
                ->filter()
                ->all();

            VendorBankAccount::query()->forWorkspace($vendor->workspace_id)->where('vendor_id', $vendor->id)->get()
                ->each(function (VendorBankAccount $account) use ($unreleased): void {
                    $keep = in_array($account->id, $unreleased, true);
                    $account->forceFill([
                        'account_holder_name' => 'Anonymised',
                        'change_request_text' => null,
                        'requester_contact' => null,
                        'iban' => $keep || $account->iban === null ? $account->iban : $account->fingerprint,
                        'account_number' => $keep || $account->account_number === null ? $account->account_number : $account->fingerprint,
                        'routing_number' => $keep ? $account->routing_number : null,
                        'swift_bic' => $keep ? $account->swift_bic : null,
                    ])->save();
                });

            $this->auditor->record('payflow.vendor.anonymised', $vendor, actor: $actor, workspace: $vendor->workspace_id, subjectLabel: $vendor->name);

            return $vendor;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $string = fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        $country = $string('country');

        return [
            'name' => trim((string) $data['name']),
            'legal_name' => $string('legal_name'),
            'category' => VendorCategory::from((string) ($data['category'] ?? VendorCategory::Contractor->value)),
            'email' => $string('email') !== null ? mb_strtolower((string) $string('email')) : null,
            'phone' => $string('phone'),
            'country' => $country !== null ? strtoupper($country) : null,
            'default_currency' => strtoupper((string) $data['default_currency']),
            'tax_id' => $string('tax_id'),
            'notes' => $string('notes'),
        ];
    }

    private function assertUniqueName(int $workspaceId, string $name, ?int $exceptId): void
    {
        $exists = Vendor::query()->forWorkspace($workspaceId)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw new GuardException('vendor_name_taken', 422);
        }
    }

    private static function phoneKey(?string $phone): string
    {
        return (string) preg_replace('/\D/', '', (string) $phone);
    }

    private static function last3(?string $phone): ?string
    {
        $digits = self::phoneKey($phone);

        return $digits === '' ? null : substr($digits, -3);
    }
}
