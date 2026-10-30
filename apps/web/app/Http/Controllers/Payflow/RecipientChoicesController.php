<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Domain\Collections\Choices\RecipientChoices;
use App\Domain\Collections\Email\ClientEmailComposer;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Http\Controllers\Controller;
use App\Http\Resources\Payflow\InvoiceResource;
use App\Support\Flash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class RecipientChoicesController extends Controller
{
    public function show(Request $request, string $token, Clock $clock): Response
    {
        $invoice = PublicInvoiceController::find($token);
        $locale = PublicInvoiceController::locale($request, $invoice);
        $today = WorkspaceClock::todayIn($invoice->workspace->timezone, $clock->now())->toDateString();
        $settings = WorkspaceProvisioner::settingsFor($invoice->workspace_id);
        $public = InvoiceResource::publicInvoice($invoice, $today);

        return PublicInvoiceController::noindex(Inertia::render('public/ReminderChoices', [
            'invoice' => ['number' => $public['number'], 'balance_due' => $public['balance_due'], 'due_date' => $public['due_date'], 'locale' => $public['locale']],
            'company_name' => ClientEmailComposer::companyName($invoice->workspace, $settings),
            'client_name' => $invoice->client->name,
            'opted_out' => $invoice->client->isOptedOut(),
            'reported_paid_at' => Iso::dateTime($invoice->client_reported_paid_at),
            'legal' => PublicInvoiceController::legal($invoice, $locale),
        ])->toResponse($request));
    }

    public function paid(Request $request, string $token, RecipientChoices $choices, Clock $clock): RedirectResponse
    {
        $invoice = PublicInvoiceController::find($token);
        $locale = PublicInvoiceController::locale($request, $invoice);
        $today = WorkspaceClock::todayIn($invoice->workspace->timezone, $clock->now())->toDateString();
        $data = $request->validate([
            'paid_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'reference' => ['nullable', 'string', 'max:140'],
        ]);

        $choices->reportPaid($invoice, $data['paid_on'] ?? null, isset($data['reference']) ? trim((string) $data['reference']) : null);
        Flash::success('payflow.flash.recipient_reported_paid');

        return $this->redirect($invoice, $locale);
    }

    public function stop(Request $request, string $token, RecipientChoices $choices): RedirectResponse
    {
        $invoice = PublicInvoiceController::find($token);
        $locale = PublicInvoiceController::locale($request, $invoice);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $choices->stop($invoice, isset($data['note']) ? trim((string) $data['note']) : null);
        Flash::success('payflow.flash.recipient_opted_out');

        return $this->redirect($invoice, $locale);
    }

    public function oneClick(string $token, RecipientChoices $choices): HttpResponse
    {
        $invoice = PublicInvoiceController::find($token);
        $choices->stop($invoice, null);

        return response('', 200, ['X-Robots-Tag' => 'noindex, nofollow']);
    }

    private function redirect(Invoice $invoice, string $locale): RedirectResponse
    {
        return redirect()->route('public.invoices.reminders', ['token' => $invoice->public_token, 'lang' => $locale], 303);
    }
}
