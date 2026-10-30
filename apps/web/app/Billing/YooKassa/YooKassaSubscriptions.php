<?php

declare(strict_types=1);

namespace App\Billing\YooKassa;

use App\Audit\Auditor;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class YooKassaSubscriptions
{
    private const array AUDITED = ['plan', 'status', 'interval', 'cancel_at_period_end', 'scheduled_plan', 'scheduled_interval'];

    public function __construct(private Auditor $auditor) {}

    /** @param  array<string, mixed>  $attributes */
    public function apply(Workspace $workspace, array $attributes, string $source, ?User $actor = null): Subscription
    {
        return DB::transaction(function () use ($workspace, $attributes, $source, $actor): Subscription {
            $subscription = Subscription::query()->where('workspace_id', $workspace->id)->lockForUpdate()->first();
            $before = $subscription?->only(self::AUDITED) ?? [];

            $subscription ??= new Subscription(['workspace_id' => $workspace->id]);
            $subscription->fill($attributes);
            $subscription->save();

            $changes = Auditor::diff($before, $subscription->only(self::AUDITED));

            if ($changes !== []) {
                $this->auditor->record(
                    'billing.subscription_changed',
                    $subscription,
                    $changes,
                    ['source' => $source, 'gateway' => 'yookassa'],
                    actor: $actor,
                    workspace: $workspace,
                    subjectLabel: (string) __("billing.plans.{$subscription->plan}.name"),
                    system: $actor === null,
                );
            }

            $workspace->unsetRelation('subscription');

            return $subscription;
        });
    }
}
