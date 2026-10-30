<?php

declare(strict_types=1);

namespace App\Domain\Guard\Models;

use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Guard\Enums\ApprovalChannel;
use App\Domain\Guard\Enums\ApprovalDecision;
use App\Domain\Guard\Enums\StepUpMethod;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $payout_request_id
 * @property int|null $user_id
 * @property ApprovalDecision $decision
 * @property ApprovalChannel $channel
 * @property int|null $trusted_confirmation_id
 * @property string|null $comment
 * @property int $amount_minor
 * @property string $currency
 * @property string $bank_fingerprint
 * @property RiskBand $risk_band
 * @property int $approval_round
 * @property StepUpMethod|null $step_up_method
 * @property CarbonInterface|null $invalidated_at
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonInterface|null $created_at
 * @property-read User|null $user
 * @property-read PayoutRequest $payout
 */
class PayoutApproval extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decision' => ApprovalDecision::class,
            'channel' => ApprovalChannel::class,
            'amount_minor' => 'integer',
            'risk_band' => RiskBand::class,
            'approval_round' => 'integer',
            'step_up_method' => StepUpMethod::class,
            'invalidated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PayoutRequest, $this> */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(PayoutRequest::class, 'payout_request_id');
    }
}
