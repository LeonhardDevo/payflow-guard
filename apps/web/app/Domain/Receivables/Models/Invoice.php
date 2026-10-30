<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Models;

use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Imports\Models\Import;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Enums\InvoicePauseSource;
use App\Domain\Receivables\Enums\InvoiceSource;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $client_id
 * @property string|null $number
 * @property InvoiceStatus $status
 * @property string $currency
 * @property string $locale
 * @property CarbonInterface $issue_date
 * @property CarbonInterface $due_date
 * @property string|null $po_number
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property int $amount_paid_minor
 * @property int $balance_due_minor
 * @property int $late_fee_applied_minor
 * @property string|null $notes
 * @property string|null $terms
 * @property string $public_token
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $first_viewed_at
 * @property CarbonInterface|null $last_viewed_at
 * @property int $view_count
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $voided_at
 * @property string|null $void_reason
 * @property CarbonInterface|null $written_off_at
 * @property string|null $write_off_reason
 * @property CarbonInterface|null $disputed_at
 * @property string|null $dispute_reason
 * @property CarbonInterface|null $dispute_resolved_at
 * @property InvoiceStatus|null $status_before_dispute
 * @property bool $reminders_paused
 * @property CarbonInterface|null $reminders_paused_until
 * @property InvoicePauseSource|null $reminders_pause_source
 * @property CarbonInterface|null $first_contact_confirmed_at
 * @property int|null $first_contact_confirmed_by
 * @property CarbonInterface|null $client_reported_paid_at
 * @property string|null $client_reported_reference
 * @property int $reminders_sent_count
 * @property CarbonInterface|null $last_reminder_at
 * @property int|null $risk_score
 * @property RiskBand|null $risk_band
 * @property list<array<string, mixed>>|null $risk_factors
 * @property CarbonInterface|null $expected_paid_on
 * @property CarbonInterface|null $risk_computed_at
 * @property InvoiceSource $source
 * @property int|null $import_id
 * @property string|null $external_ref
 * @property bool $paid_at_estimated
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Client $client
 * @property-read Workspace $workspace
 * @property-read User|null $creator
 * @property-read Collection<int, InvoiceItem> $items
 * @property-read Collection<int, Payment> $payments
 */
class Invoice extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    public const array OPEN_STATUSES = ['open', 'partially_paid'];

    public const array OUTSTANDING_STATUSES = ['open', 'partially_paid', 'disputed'];

    protected $guarded = ['id', 'workspace_id', 'balance_due_minor'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'subtotal_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 0,
        'amount_paid_minor' => 0,
        'late_fee_applied_minor' => 0,
        'view_count' => 0,
        'reminders_paused' => false,
        'reminders_sent_count' => 0,
        'source' => 'manual',
        'paid_at_estimated' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'balance_due_minor' => 'integer',
            'late_fee_applied_minor' => 'integer',
            'sent_at' => 'datetime',
            'first_viewed_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'written_off_at' => 'datetime',
            'disputed_at' => 'datetime',
            'dispute_resolved_at' => 'datetime',
            'status_before_dispute' => InvoiceStatus::class,
            'reminders_paused' => 'boolean',
            'reminders_paused_until' => 'date',
            'reminders_pause_source' => InvoicePauseSource::class,
            'first_contact_confirmed_at' => 'datetime',
            'client_reported_paid_at' => 'datetime',
            'reminders_sent_count' => 'integer',
            'last_reminder_at' => 'datetime',
            'risk_score' => 'integer',
            'risk_band' => RiskBand::class,
            'risk_factors' => 'array',
            'expected_paid_on' => 'date',
            'risk_computed_at' => 'datetime',
            'source' => InvoiceSource::class,
            'paid_at_estimated' => 'boolean',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Import, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Reminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /** @return HasMany<InvoiceEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(InvoiceEvent::class);
    }

    /** @return HasMany<InvoicePaymentLink, $this> */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(InvoicePaymentLink::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status->value, self::OPEN_STATUSES, true);
    }

    public function isOutstanding(): bool
    {
        return in_array($this->status->value, self::OUTSTANDING_STATUSES, true);
    }

    public function balanceDue(): int
    {
        return $this->total_minor - $this->amount_paid_minor;
    }

    public function dueDate(): string
    {
        return $this->due_date->toDateString();
    }

    public function issueDate(): string
    {
        return $this->issue_date->toDateString();
    }

    public function label(): string
    {
        return $this->number ?? ('#'.$this->id);
    }
}
