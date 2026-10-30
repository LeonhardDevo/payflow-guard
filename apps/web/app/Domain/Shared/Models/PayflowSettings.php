<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Guard\Models\ReceivingDetailsVersion;
use App\Domain\Receivables\Enums\LateFeeType;
use App\Domain\Receivables\LateFees\LateFeeTerms;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $invoice_prefix
 * @property int $next_invoice_seq
 * @property string $payout_prefix
 * @property int $next_payout_seq
 * @property int $default_payment_terms_days
 * @property int|null $default_reminder_policy_id
 * @property string $send_window_start
 * @property string $send_window_end
 * @property bool $business_days_only
 * @property int $first_contact_max_per_hour
 * @property string|null $sender_display_name
 * @property string|null $reply_to_email
 * @property string|null $privacy_contact_email
 * @property bool $track_invoice_views
 * @property LateFeeType $late_fee_type
 * @property int|null $late_fee_rate_bp
 * @property int|null $late_fee_fixed_minor
 * @property int $late_fee_grace_days
 * @property int|null $late_fee_cap_bp
 * @property string|null $company_legal_name
 * @property string|null $company_address
 * @property string|null $company_tax_id
 * @property string|null $company_email
 * @property string|null $company_phone
 * @property int|null $active_receiving_details_id
 * @property bool $digest_enabled
 * @property int $digest_hour
 * @property array<string, array<string, int>> $approval_thresholds
 * @property int $cooling_off_hours_medium
 * @property int $cooling_off_hours_high
 * @property bool $allow_telegram_approvals
 * @property int $escalate_pending_after_hours
 * @property int $guard_trust_delay_hours
 * @property bool $require_second_factor
 * @property int $solo_release_delay_hours
 * @property bool $onboarding_dismissed
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Workspace $workspace
 * @property-read ReminderPolicy|null $defaultPolicy
 * @property-read ReceivingDetailsVersion|null $activeReceivingDetails
 */
class PayflowSettings extends Model
{
    use BelongsToWorkspace;

    protected $table = 'payflow_settings';

    protected $guarded = ['id', 'workspace_id', 'next_invoice_seq', 'next_payout_seq'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'invoice_prefix' => 'INV-',
        'next_invoice_seq' => 1,
        'payout_prefix' => 'PO-',
        'next_payout_seq' => 1,
        'default_payment_terms_days' => 14,
        'send_window_start' => '09:00',
        'send_window_end' => '17:00',
        'business_days_only' => true,
        'first_contact_max_per_hour' => 50,
        'track_invoice_views' => true,
        'late_fee_type' => 'none',
        'late_fee_grace_days' => 0,
        'digest_enabled' => true,
        'digest_hour' => 9,
        'approval_thresholds' => '{}',
        'cooling_off_hours_medium' => 24,
        'cooling_off_hours_high' => 48,
        'allow_telegram_approvals' => true,
        'escalate_pending_after_hours' => 24,
        'guard_trust_delay_hours' => 72,
        'require_second_factor' => true,
        'solo_release_delay_hours' => 24,
        'onboarding_dismissed' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'next_invoice_seq' => 'integer',
            'next_payout_seq' => 'integer',
            'default_payment_terms_days' => 'integer',
            'business_days_only' => 'boolean',
            'first_contact_max_per_hour' => 'integer',
            'track_invoice_views' => 'boolean',
            'late_fee_type' => LateFeeType::class,
            'late_fee_rate_bp' => 'integer',
            'late_fee_fixed_minor' => 'integer',
            'late_fee_grace_days' => 'integer',
            'late_fee_cap_bp' => 'integer',
            'digest_enabled' => 'boolean',
            'digest_hour' => 'integer',
            'approval_thresholds' => 'array',
            'cooling_off_hours_medium' => 'integer',
            'cooling_off_hours_high' => 'integer',
            'allow_telegram_approvals' => 'boolean',
            'escalate_pending_after_hours' => 'integer',
            'guard_trust_delay_hours' => 'integer',
            'require_second_factor' => 'boolean',
            'solo_release_delay_hours' => 'integer',
            'onboarding_dismissed' => 'boolean',
        ];
    }

    /** @return BelongsTo<ReminderPolicy, $this> */
    public function defaultPolicy(): BelongsTo
    {
        return $this->belongsTo(ReminderPolicy::class, 'default_reminder_policy_id');
    }

    /** @return BelongsTo<ReceivingDetailsVersion, $this> */
    public function activeReceivingDetails(): BelongsTo
    {
        return $this->belongsTo(ReceivingDetailsVersion::class, 'active_receiving_details_id');
    }

    public function windowStart(): string
    {
        return substr($this->send_window_start, 0, 5);
    }

    public function windowEnd(): string
    {
        return substr($this->send_window_end, 0, 5);
    }

    public function lateFeeTerms(): LateFeeTerms
    {
        return new LateFeeTerms(
            $this->late_fee_type,
            $this->late_fee_rate_bp,
            $this->late_fee_fixed_minor,
            $this->late_fee_grace_days,
            $this->late_fee_cap_bp,
        );
    }
}
