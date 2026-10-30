<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Domain\Collections\Dispatch\ManualReminders;
use App\Domain\Collections\Tone\ToneContext;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Invoices\InvoiceIssuer;
use App\Domain\Receivables\Invoices\InvoiceLifecycle;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Money\MoneyParser;
use App\Http\Requests\Payflow\BulkInvoicesRequest;
use App\Http\Requests\Payflow\PauseRequest;
use App\Http\Requests\Payflow\ReasonRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class InvoiceActionsController extends PayflowController
{
    public function send(Request $request, Invoice $invoice, InvoiceIssuer $issuer): RedirectResponse
    {
        Gate::authorize('manage', $invoice);
        $data = $request->validate(['message' => ['nullable', 'string', 'max:1000'], 'cc_me' => ['sometimes', 'boolean']]);
        $issuer->send($invoice, $this->user($request), $data['message'] ?? null, (bool) ($data['cc_me'] ?? false));

        return $this->back('invoice_sent');
    }

    public function markSent(Request $request, Invoice $invoice, InvoiceIssuer $issuer): RedirectResponse
    {
        Gate::authorize('manage', $invoice);
        $issuer->markSent($invoice, $this->user($request));

        return $this->back('invoice_marked_sent');
    }

    public function paymentLink(Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('manage', $invoice);

        if ($lifecycle->recreateLink($invoice) === null) {
            throw new PayflowException('payment_provider', 409);
        }

        return $this->back('link_created');
    }

    public function pause(PauseRequest $request, Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('remind', $invoice);
        $paused = $request->boolean('paused');
        $lifecycle->pause($invoice, $this->user($request), $paused, $request->input('until'), $request->input('reason'));

        return $this->back($paused ? 'invoice_paused' : 'invoice_resumed');
    }

    public function dispute(Request $request, Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('manage', $invoice);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $lifecycle->dispute($invoice, $this->user($request), (string) $data['reason']);

        return $this->back('invoice_disputed');
    }

    public function resolveDispute(Request $request, Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('manage', $invoice);
        $data = $request->validate(['resolution_note' => ['required', 'string', 'min:5', 'max:2000']]);
        $lifecycle->resolveDispute($invoice, $this->user($request), (string) $data['resolution_note']);

        return $this->back('dispute_resolved');
    }

    public function void(ReasonRequest $request, Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('writeOff', $invoice);
        $lifecycle->void($invoice, $this->user($request), $request->reason());

        return $this->back('invoice_voided');
    }

    public function writeOff(ReasonRequest $request, Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('writeOff', $invoice);
        $lifecycle->writeOff($invoice, $this->user($request), $request->reason());

        return $this->back('invoice_written_off');
    }

    public function lateFee(Request $request, Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('applyLateFee', $invoice);
        $data = $request->validate(['amount' => ['nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/']]);
        $amount = isset($data['amount']) && $data['amount'] !== '' ? MoneyParser::toMinor((string) $data['amount']) : null;
        $lifecycle->applyLateFee($invoice, $this->user($request), $amount);

        return $this->back('late_fee_applied');
    }

    public function confirmFirstContact(Request $request, Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('configureReminders', $invoice);
        $lifecycle->confirmFirstContact($invoice, $this->user($request));

        return $this->back('first_contact_confirmed');
    }

    public function acknowledgeReportedPayment(Request $request, Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('recordPayment', $invoice);
        $data = $request->validate(['resume_reminders' => ['sometimes', 'boolean']]);
        $lifecycle->acknowledgeReportedPayment($invoice, $this->user($request), (bool) ($data['resume_reminders'] ?? false));

        return $this->back('reported_payment_acknowledged');
    }

    public function bulk(BulkInvoicesRequest $request, InvoiceIssuer $issuer, InvoiceLifecycle $lifecycle, ManualReminders $reminders): RedirectResponse
    {
        $user = $this->user($request);
        $action = (string) $request->input('action');
        $done = 0;
        $skipped = 0;
        $invoices = Invoice::query()->whereIn('id', $request->ids())->get();
        $skipped += count($request->ids()) - $invoices->count();

        foreach ($invoices as $invoice) {
            $ability = match ($action) {
                'remind', 'pause', 'resume' => 'remind',
                default => 'manage',
            };

            if (! $user->can($ability, $invoice)) {
                $skipped++;

                continue;
            }

            try {
                match ($action) {
                    'remind' => $reminders->send($invoice, $user, ToneContext::suggest($invoice, $this->today())->tone, null, null, false),
                    'pause' => $lifecycle->pause($invoice, $user, true, null, null),
                    'resume' => $lifecycle->pause($invoice, $user, false, null, null),
                    'mark_sent' => $invoice->status === InvoiceStatus::Draft ? $issuer->markSent($invoice, $user) : throw new PayflowException('invalid_transition'),
                    default => throw new PayflowException('invalid_transition'),
                };
                $done++;
            } catch (PayflowException) {
                $skipped++;
            }
        }

        return $this->back('bulk_done', ['done' => $done, 'skipped' => $skipped]);
    }
}
