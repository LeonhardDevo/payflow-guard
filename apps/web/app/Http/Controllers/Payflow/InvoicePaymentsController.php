<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Recording\PaymentRecorder;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Money\MoneyParser;
use App\Http\Requests\Payflow\RecordPaymentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class InvoicePaymentsController extends PayflowController
{
    public function store(RecordPaymentRequest $request, Invoice $invoice, PaymentRecorder $recorder): RedirectResponse
    {
        Gate::authorize('recordPayment', $invoice);

        $recorder->recordManual(
            $invoice,
            $this->user($request),
            MoneyParser::toMinor((string) $request->input('amount')),
            (string) $request->input('paid_on'),
            PaymentMethod::from((string) $request->input('method')),
            $request->filled('reference') ? (string) $request->input('reference') : null,
            $request->filled('note') ? (string) $request->input('note') : null,
            $request->boolean('send_receipt', true),
            $request->boolean('allow_overpayment'),
        );

        return $this->back('payment_recorded');
    }

    public function reverse(Request $request, Payment $payment, PaymentRecorder $recorder): RedirectResponse
    {
        Gate::authorize('reverse', $payment);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $recorder->reverse($payment, $this->user($request), trim((string) $data['reason']));

        return $this->back('payment_reversed');
    }
}
