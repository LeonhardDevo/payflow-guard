<?php

declare(strict_types=1);

namespace App\Domain\Collections\Models;

use App\Domain\Collections\Enums\ReminderKind;
use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $invoice_id
 * @property int $client_id
 * @property int|null $policy_step_id
 * @property ReminderKind $kind
 * @property int|null $step_offset_days
 * @property bool $is_first_contact
 * @property Tone $tone
 * @property list<array{code: string, params: array<string, string|int|float>}> $tone_reasons
 * @property string $locale
 * @property CarbonInterface $scheduled_for
 * @property ReminderStatus $status
 * @property ReminderSkipReason|null $skip_reason
 * @property string|null $recipient_email
 * @property list<string> $cc_emails
 * @property string|null $subject
 * @property string|null $body_html
 * @property string|null $body_text
 * @property int|null $late_fee_quoted_minor
 * @property string|null $outbox_message_id
 * @property string|null $provider_message_id
 * @property int $delivery_attempts
 * @property CarbonInterface|null $next_retry_at
 * @property CarbonInterface|null $queued_at
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $failed_at
 * @property string|null $error
 * @property int $attempts
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Invoice $invoice
 * @property-read Client $client
 * @property-read ReminderPolicyStep|null $step
 * @property-read User|null $creator
 */
class Reminder extends Model
{
    use BelongsToWorkspace;

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'tone_reasons' => '[]',
        'cc_emails' => '[]',
        'status' => 'scheduled',
        'is_first_contact' => false,
        'delivery_attempts' => 0,
        'attempts' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ReminderKind::class,
            'step_offset_days' => 'integer',
            'is_first_contact' => 'boolean',
            'tone' => Tone::class,
            'tone_reasons' => 'array',
            'scheduled_for' => 'datetime',
            'status' => ReminderStatus::class,
            'skip_reason' => ReminderSkipReason::class,
            'cc_emails' => 'array',
            'late_fee_quoted_minor' => 'integer',
            'delivery_attempts' => 'integer',
            'next_retry_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** @return BelongsTo<ReminderPolicyStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(ReminderPolicyStep::class, 'policy_step_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
