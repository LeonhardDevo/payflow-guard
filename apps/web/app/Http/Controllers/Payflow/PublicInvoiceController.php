<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Billing\BillingException;
use App\Billing\Payments\FakePaymentGateway;
use App\Billing\Payments\PaymentLinks;
use App\Billing\Payments\PaymentStatus as LinkStatus;
use App\Domain\Collections\Email\ClientEmailComposer;
use App\Domain\Guard\Models\ReceivingDetailsVersion;
use App\Domain\Payments\Links\PaymentAccountState;
use App\Domain\Payments\Links\PaymentLinkService;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Receivables\Enums\EventActorType;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Invoices\InvoiceDisplayStatusResolver;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Pdf\InvoicePdf;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Http\Controllers\Controller;
use App\Http\Resources\Payflow\InvoiceResource;
use App\Models\User;
use App\Support\Flash;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class PublicInvoiceController extends Controller
{
    public static function find(string $token): Invoice
    {
        $invoice = Invoice::query()->withoutGlobalScopes()
            ->where('public_token', $token)
            ->whereNull('deleted_at')
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->first();
        abort_if($invoice === null, 404);

        return $invoice;
    }

    public static function locale(Request $request, Invoice $invoice): string
    {
        $lang = $request->query('lang');
        $locale = is_string($lang) && in_array($lang, ['en', 'ru'], true) ? $lang : $invoice->locale;
        App::setLocale($locale);

        return $locale;
    }

    /** @return array{privacy_contact: string, reminder_recipients_url: string, choices_url: string, terms_url: string, privacy_url: string} */
    public static function legal(Invoice $invoice, string $locale): array
    {
        $settings = WorkspaceProvisioner::settingsFor($invoice->workspace_id);

        return [
            'privacy_contact' => ClientEmailComposer::privacyContact($invoice->workspace, $settings),
            'reminder_recipients_url' => ClientEmailComposer::legalUrl('reminder-recipients', $locale),
            'choices_url' => route('public.invoices.reminders', ['token' => $invoice->public_token, 'lang' => $locale]),
            'terms_url' => ClientEmailComposer::legalUrl('terms', $locale),
            'privacy_url' => ClientEmailComposer::legalUrl('privacy', $locale),
        ];
    }

    public static function noindex(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    public function show(Request $request, string $token, PaymentLinkService $links, PaymentAccountState $accounts, InvoiceTimeline $timeline, Clock $clock): Response
    {
        $invoice = self::find($token);
        $locale = self::locale($request, $invoice);
        $workspace = $invoice->workspace;
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $now = $clock->now();
        $today = WorkspaceClock::todayIn($workspace->timezone, $now)->toDateString();
        $this->recordView($request, $invoice, $settings->track_invoice_views, $timeline, $now);

        $reason = $links->payableReason($invoice);
        $account = $accounts->forCurrency($invoice->workspace_id, $invoice->currency) ?? $accounts->live($invoice->workspace_id);
        $details = $settings->active_receiving_details_id !== null ? ReceivingDetailsVersion::query()->forWorkspace($workspace)->find($settings->active_receiving_details_id) : null;
        $client = $invoice->client;

        return self::noindex(Inertia::render('public/Invoice', [
            'invoice' => InvoiceResource::publicInvoice($invoice, $today),
            'company' => [
                'name' => ClientEmailComposer::companyName($workspace, $settings),
                'legal_name' => $settings->company_legal_name,
                'address' => $settings->company_address,
                'tax_id' => $settings->company_tax_id,
                'email' => $settings->company_email,
                'phone' => $settings->company_phone,
                'receiving_bank_details' => $details?->details,
            ],
            'client' => ['name' => $client->name, 'legal_name' => $client->legal_name, 'address' => $client->address, 'tax_id' => $client->tax_id],
            'payable' => $reason === null,
            'payable_reason' => $reason?->value,
            'pay_label' => $reason === null && $account !== null ? match ($account->driver) {
                'stripe' => 'card',
                'yookassa' => 'yookassa',
                default => 'demo',
            } : null,
            'pdf_url' => route('public.invoices.pdf', ['token' => $invoice->public_token]),
            'provider_mode' => $account !== null ? PaymentAccountState::mode($account) : null,
            'legal' => self::legal($invoice, $locale),
            'locale' => $locale,
        ])->toResponse($request));
    }

    private function recordView(Request $request, Invoice $invoice, bool $track, InvoiceTimeline $timeline, CarbonImmutable $now): void
    {
        if (! $track || $request->boolean('preview')) {
            return;
        }

        $user = $request->user();

        if ($user instanceof User && $user->belongsToWorkspace($invoice->workspace_id)) {
            return;
        }

        DB::transaction(function () use ($invoice, $timeline, $now): void {
            $locked = Invoice::query()->withoutGlobalScopes()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $first = $locked->first_viewed_at === null;
            $locked->forceFill([
                'first_viewed_at' => $locked->first_viewed_at ?? $now,
                'last_viewed_at' => $now,
                'view_count' => $locked->view_count + 1,
            ])->saveQuietly();

            if ($first) {
                $timeline->record($locked, InvoiceEventType::Viewed, [], actorType: EventActorType::Client, at: $now);
            }
        });
    }

    public function pdf(string $token): HttpResponse
    {
        $invoice = self::find($token);

        return response(InvoicePdf::render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.ClientEmailComposer::fileName($invoice).'"',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function pay(Request $request, string $token, PaymentLinkService $links): Response|RedirectResponse
    {
        $invoice = self::find($token);
        self::locale($request, $invoice);
        $link = $links->ensureActive($invoice, true);

        if ($link === null) {
            Flash::error('payflow.errors.not_payable');

            return redirect()->route('public.invoices.show', ['token' => $token], 303);
        }

        return Inertia::location($link->url);
    }

    public function thanks(Request $request, string $token, PaymentLinks $paymentLinks, Clock $clock): Response
    {
        $invoice = self::find($token);
        $locale = self::locale($request, $invoice);
        $fake = $request->query('fake_payment');

        if (is_string($fake) && config('payflow.allow_fake_payments') === true) {
            $this->settleFake($invoice, $fake, $paymentLinks);
            $invoice->refresh();
        }

        $today = WorkspaceClock::todayIn($invoice->workspace->timezone, $clock->now())->toDateString();
        $settings = WorkspaceProvisioner::settingsFor($invoice->workspace_id);
        $public = InvoiceResource::publicInvoice($invoice, $today);

        return self::noindex(Inertia::render('public/PaymentThanks', [
            'invoice' => [
                'number' => $public['number'],
                'status' => $public['status'],
                'display_status' => $public['display_status'],
                'total' => $public['total'],
                'balance_due' => $public['balance_due'],
                'locale' => $public['locale'],
            ],
            'status_url' => route('public.invoices.status', ['token' => $token]),
            'company_name' => ClientEmailComposer::companyName($invoice->workspace, $settings),
            'legal' => self::legal($invoice, $locale),
        ])->toResponse($request));
    }

    public static function settleFake(Invoice $invoice, string $providerId, PaymentLinks $paymentLinks): void
    {
        $link = InvoicePaymentLink::query()
            ->where('invoice_id', $invoice->id)
            ->where('driver', 'fake')
            ->where('provider_id', $providerId)
            ->where('active', true)
            ->first();

        if ($link === null || $link->status !== LinkStatus::Pending) {
            return;
        }

        try {
            FakePaymentGateway::settle($providerId, LinkStatus::Succeeded);
            $paymentLinks->refresh($link, 'fake.checkout');
        } catch (BillingException) {
            return;
        }
    }

    public function status(Request $request, string $token, Clock $clock): JsonResponse
    {
        $invoice = self::find($token);
        $today = WorkspaceClock::todayIn($invoice->workspace->timezone, $clock->now())->toDateString();

        return response()->json([
            'status' => $invoice->status->value,
            'display_status' => InvoiceDisplayStatusResolver::for($invoice, $today)->value,
            'balance_due' => MoneyData::of($invoice->balanceDue(), $invoice->currency),
        ], 200, ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store']);
    }
}
