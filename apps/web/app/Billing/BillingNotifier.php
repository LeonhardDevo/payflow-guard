<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Mail\PaymentFailedMail;
use App\Billing\Mail\TrialEndingMail;
use App\Enums\WorkspaceRole;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

final readonly class BillingNotifier
{
    /** @param  array<string, mixed>  $invoice */
    public function paymentFailed(Workspace $workspace, Subscription $subscription, array $invoice): void
    {
        $amount = is_numeric($invoice['amount_due'] ?? null) ? (int) $invoice['amount_due'] : null;
        $currency = is_string($invoice['currency'] ?? null) ? strtoupper($invoice['currency']) : ($subscription->currency ?? 'USD');
        $hostedUrl = Arr::get($invoice, 'hosted_invoice_url');
        $nextAttempt = is_numeric($invoice['next_payment_attempt'] ?? null)
            ? Carbon::createFromTimestampUTC((int) $invoice['next_payment_attempt'])
            : null;

        foreach ($this->recipients($workspace) as $user) {
            Mail::to($user)->send(new PaymentFailedMail(
                workspace: $workspace,
                subscription: $subscription,
                amount: $amount,
                currency: $currency,
                invoiceUrl: is_string($hostedUrl) && str_starts_with($hostedUrl, 'https://') ? $hostedUrl : null,
                nextAttemptAt: $nextAttempt,
            ));
        }
    }

    public function trialEnding(Workspace $workspace, Subscription $subscription): void
    {
        foreach ($this->recipients($workspace) as $user) {
            Mail::to($user)->send(new TrialEndingMail($workspace, $subscription));
        }
    }

    /** @return Collection<int, User> */
    public function recipients(Workspace $workspace): Collection
    {
        return $workspace->members()
            ->get()
            ->filter(function (User $user): bool {
                $value = $user->getRelationValue('membership')?->getAttribute('role');
                $role = $value instanceof WorkspaceRole ? $value : WorkspaceRole::tryFrom((string) $value);

                return $role !== null && $role->allows('billing.manage');
            })
            ->values();
    }
}
