<?php

declare(strict_types=1);

namespace App\Domain\Guard\Models;

use App\Domain\Guard\Enums\VendorCategory;
use App\Domain\Guard\Enums\VendorStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $legal_name
 * @property VendorCategory $category
 * @property string|null $email
 * @property string|null $email_domain
 * @property string|null $phone
 * @property CarbonInterface|null $phone_verified_at
 * @property CarbonInterface|null $phone_changed_at
 * @property string|null $country
 * @property string $default_currency
 * @property string|null $tax_id
 * @property VendorStatus $status
 * @property string|null $hold_reason
 * @property int|null $current_bank_account_id
 * @property string|null $notes
 * @property CarbonInterface|null $anonymised_at
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read VendorBankAccount|null $currentBankAccount
 * @property-read User|null $creator
 */
class Vendor extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $guarded = ['id', 'workspace_id', 'email_domain'];

    protected $hidden = ['tax_id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => 'contractor',
        'status' => 'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => VendorCategory::class,
            'status' => VendorStatus::class,
            'phone_verified_at' => 'datetime',
            'phone_changed_at' => 'datetime',
            'tax_id' => 'encrypted',
            'anonymised_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<VendorBankAccount, $this> */
    public function currentBankAccount(): BelongsTo
    {
        return $this->belongsTo(VendorBankAccount::class, 'current_bank_account_id');
    }

    /** @return HasMany<VendorBankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(VendorBankAccount::class)->orderByDesc('version');
    }

    /** @return HasMany<PayoutRequest, $this> */
    public function payouts(): HasMany
    {
        return $this->hasMany(PayoutRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
