<?php

declare(strict_types=1);

namespace App\Http\Resources\Payflow;

use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;

final class PaymentResource
{
    /** @return array<string, mixed> */
    public static function row(Payment $payment, bool $canReverse): array
    {
        return [
            'id' => $payment->id,
            'amount' => MoneyData::of($payment->amount_minor, $payment->currency),
            'refunded' => MoneyData::of($payment->refunded_minor, $payment->currency),
            'method' => $payment->method->value,
            'status' => $payment->status->value,
            'provider' => $payment->provider->value,
            'paid_at' => Iso::dateTime($payment->paid_at),
            'reference' => $payment->reference,
            'note' => $payment->note,
            'recorded_by' => UserRef::of($payment->recorder),
            'can_reverse' => $canReverse && $payment->provider === PaymentProvider::Manual && $payment->status === PaymentStatus::Succeeded,
        ];
    }
}
