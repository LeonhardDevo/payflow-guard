<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Models;

use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Receivables\Enums\ClientPauseSource;
use App\Domain\Receivables\Enums\LateFeeType;
use App\Domain\Receivables\Enums\LatePayerClass;
use App\Domain\Receivables\Enums\ToneCeiling;
use App\Domain\Receivables\LateFees\LateFeeTerms;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Document;
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
 * @property string|null $contact_name
 * @property string|null $email
 * @property list<string> $cc_emails
 * @property string|null $phone
 * @property string|null $country
 * @property string|null $timezone
 * @property string|null $tax_id
 * @property string|null $address
 * @property string $locale
 * @property string $currency
 * @property int|null $payment_terms_days
 * @property int|null $reminder_policy_id
 * @property ToneCeiling $tone_ceiling
 * @property bool $reminders_paused
 * @property CarbonInterface|null $reminders_paused_until
 * @property ClientPauseSource|null $reminders_pause_source
 * @property string|null $pause_reason
 * @property CarbonInterface|null $opted_out_at
 * @property LateFeeType|null $late_fee_type
 * @property int|null $late_fee_rate_bp
 * @property int|null $late_fee_fixed_minor
 * @property int|null $late_fee_grace_days
 * @property int|null $late_fee_cap_bp
 * @property int|null $contract_document_id
 * @property int|null $owner_user_id
 * @property list<string> $tags
 * @property string|null $notes
 * @property string|null $external_ref
 * @property int $paid_invoices_count
 * @property int $late_invoices_count
 * @property string $avg_days_late
 * @property string|null $on_time_rate
 * @property LatePayerClass $late_payer_class
 * @property int $open_balance_minor
 * @property int $overdue_balance_minor
 * @property CarbonInterface|null $stats_computed_at
 * @property CarbonInterface|null $anonymised_at
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read User|null $owner
 * @property-read ReminderPolicy|null $reminderPolicy
 * @property-read Document|null $contractDocument
 */
class Client extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $guarded = ['id', 'workspace_id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cc_emails' => '[]',
        'tags' => '[]',
        'tone_ceiling' => 'none',
        'reminders_paused' => false,
        'late_payer_class' => 'new',
        'paid_invoices_count' => 0,
        'late_invoices_count' => 0,
        'avg_days_late' => 0,
        'open_balance_minor' => 0,
        'overdue_balance_minor' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cc_emails' => 'array',
            'tags' => 'array',
            'payment_terms_days' => 'integer',
            'tone_ceiling' => ToneCeiling::class,
            'reminders_paused' => 'boolean',
            'reminders_paused_until' => 'date',
            'reminders_pause_source' => ClientPauseSource::class,
            'opted_out_at' => 'datetime',
            'late_fee_type' => LateFeeType::class,
            'late_fee_rate_bp' => 'integer',
            'late_fee_fixed_minor' => 'integer',
            'late_fee_grace_days' => 'integer',
            'late_fee_cap_bp' => 'integer',
            'paid_invoices_count' => 'integer',
            'late_invoices_count' => 'integer',
            'late_payer_class' => LatePayerClass::class,
            'open_balance_minor' => 'integer',
            'overdue_balance_minor' => 'integer',
            'stats_computed_at' => 'datetime',
            'anonymised_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<ReminderPolicy, $this> */
    public function reminderPolicy(): BelongsTo
    {
        return $this->belongsTo(ReminderPolicy::class, 'reminder_policy_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function contractDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'contract_document_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function lateFeeOverride(): ?LateFeeTerms
    {
        if ($this->late_fee_type === null) {
            return null;
        }

        return new LateFeeTerms(
            $this->late_fee_type,
            $this->late_fee_rate_bp,
            $this->late_fee_fixed_minor,
            $this->late_fee_grace_days ?? 0,
            $this->late_fee_cap_bp,
        );
    }

    public function avgDaysLate(): float
    {
        return (float) $this->avg_days_late;
    }

    public function onTimeRate(): ?float
    {
        return $this->on_time_rate === null ? null : (float) $this->on_time_rate;
    }

    public function isOptedOut(): bool
    {
        return $this->reminders_paused && $this->reminders_pause_source === ClientPauseSource::RecipientOptOut;
    }
}
