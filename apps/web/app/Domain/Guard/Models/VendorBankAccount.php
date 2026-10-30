<?php

declare(strict_types=1);

namespace App\Domain\Guard\Models;

use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\BankChangeSource;
use App\Domain\Guard\Enums\BankScheme;
use App\Domain\Guard\Enums\VerificationMethod;
use App\Domain\Guard\Enums\VerificationMode;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $vendor_id
 * @property int $version
 * @property BankScheme $scheme
 * @property string $account_holder_name
 * @property string|null $iban
 * @property string|null $account_number
 * @property string|null $routing_number
 * @property string|null $swift_bic
 * @property string|null $bank_name
 * @property string $bank_country
 * @property string $currency
 * @property string $last4
 * @property string $fingerprint
 * @property BankAccountStatus $status
 * @property BankChangeSource $source
 * @property int $requested_by_user_id
 * @property CarbonInterface $requested_at
 * @property string|null $requester_contact
 * @property string|null $requester_email_domain
 * @property string|null $change_request_text
 * @property int $risk_score
 * @property RiskBand $risk_band
 * @property list<array{code: string, points: int, params: array<string, string|int>}> $risk_signals
 * @property VerificationMethod|null $verification_method
 * @property array<string, bool>|null $verification_checklist
 * @property VerificationMode $verification_mode
 * @property int|null $trusted_confirmation_id
 * @property int|null $verified_by_user_id
 * @property CarbonInterface|null $verified_at
 * @property string|null $verification_note
 * @property CarbonInterface|null $cooling_off_until
 * @property int|null $rejected_by_user_id
 * @property CarbonInterface|null $rejected_at
 * @property string|null $rejection_reason
 * @property CarbonInterface|null $superseded_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Vendor $vendor
 * @property-read User $requester
 * @property-read User|null $verifier
 * @property-read User|null $rejecter
 */
class VendorBankAccount extends Model
{
    use BelongsToWorkspace;

    protected $guarded = ['id'];

    protected $hidden = ['account_holder_name', 'iban', 'account_number', 'routing_number', 'swift_bic', 'requester_contact', 'change_request_text'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'risk_signals' => '[]',
        'verification_mode' => 'standard',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'scheme' => BankScheme::class,
            'account_holder_name' => 'encrypted',
            'iban' => 'encrypted',
            'account_number' => 'encrypted',
            'routing_number' => 'encrypted',
            'swift_bic' => 'encrypted',
            'status' => BankAccountStatus::class,
            'source' => BankChangeSource::class,
            'requested_at' => 'datetime',
            'requester_contact' => 'encrypted',
            'change_request_text' => 'encrypted',
            'risk_score' => 'integer',
            'risk_band' => RiskBand::class,
            'risk_signals' => 'array',
            'verification_method' => VerificationMethod::class,
            'verification_checklist' => 'array',
            'verification_mode' => VerificationMode::class,
            'verified_at' => 'datetime',
            'cooling_off_until' => 'datetime',
            'rejected_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function fingerprint8(): string
    {
        return substr($this->fingerprint, 0, 8);
    }
}
