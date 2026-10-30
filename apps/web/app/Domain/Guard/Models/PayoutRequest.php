<?php

declare(strict_types=1);

namespace App\Domain\Guard\Models;

use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Guard\Enums\PayoutHoldReason;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $number
 * @property int $vendor_id
 * @property int|null $vendor_bank_account_id
 * @property string|null $bank_fingerprint
 * @property int $amount_minor
 * @property string $currency
 * @property string $description
 * @property string|null $vendor_invoice_number
 * @property CarbonInterface|null $due_date
 * @property PayoutStatus $status
 * @property PayoutHoldReason|null $hold_reason
 * @property int|null $risk_score
 * @property RiskBand|null $risk_band
 * @property list<array{code: string, points: int, params: array<string, string|int>}>|null $risk_signals
 * @property int $required_approvals
 * @property int $approvals_count
 * @property int $approval_round
 * @property bool $auto_approved
 * @property array<string, int>|null $auto_approve_basis
 * @property bool $release_requires_other_user
 * @property CarbonInterface|null $release_not_before
 * @property int|null $split_group_total_minor
 * @property bool $telegram_allowed
 * @property int $requested_by
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $approved_at
 * @property int|null $released_by
 * @property CarbonInterface|null $released_at
 * @property string|null $paid_reference
 * @property CarbonInterface|null $paid_on
 * @property CarbonInterface|null $rejected_at
 * @property CarbonInterface|null $canceled_at
 * @property int|null $canceled_by
 * @property string|null $cancel_reason
 * @property CarbonInterface|null $last_escalated_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Vendor $vendor
 * @property-read VendorBankAccount|null $bankAccount
 * @property-read User $requester
 */
class PayoutRequest extends Model
{
    use BelongsToWorkspace;

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'required_approvals' => 1,
        'approvals_count' => 0,
        'approval_round' => 1,
        'auto_approved' => false,
        'release_requires_other_user' => false,
        'telegram_allowed' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'due_date' => 'date',
            'status' => PayoutStatus::class,
            'hold_reason' => PayoutHoldReason::class,
            'risk_score' => 'integer',
            'risk_band' => RiskBand::class,
            'risk_signals' => 'array',
            'required_approvals' => 'integer',
            'approvals_count' => 'integer',
            'approval_round' => 'integer',
            'auto_approved' => 'boolean',
            'auto_approve_basis' => 'array',
            'release_requires_other_user' => 'boolean',
            'release_not_before' => 'datetime',
            'split_group_total_minor' => 'integer',
            'telegram_allowed' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'released_at' => 'datetime',
            'paid_on' => 'date',
            'rejected_at' => 'datetime',
            'canceled_at' => 'datetime',
            'last_escalated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    /** @return BelongsTo<VendorBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(VendorBankAccount::class, 'vendor_bank_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<PayoutApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(PayoutApproval::class);
    }
}
