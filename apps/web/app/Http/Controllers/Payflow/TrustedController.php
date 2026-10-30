<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Domain\Guard\Enums\TrustedContactStatus;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Guard\Trusted\TrustedConfirmations;
use App\Domain\Guard\Trusted\TrustedContactService;
use App\Domain\Guard\Trusted\TrustedDecisions;
use App\Domain\Shared\Support\Iso;
use App\Http\Controllers\Controller;
use App\Locale\WithLocale;
use App\Models\Workspace;
use App\Support\Flash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class TrustedController extends Controller
{
    public function show(Request $request, string $token, TrustedDecisions $decisions): SymfonyResponse
    {
        $confirmation = TrustedConfirmations::findByToken($token);
        abort_if($confirmation === null, 404);
        $locale = self::locale($request, $confirmation->contact->locale);

        return self::noindex($request, Inertia::render('trusted/Confirm', [
            'confirmation' => WithLocale::run($locale, fn (): array => self::present($confirmation, $decisions->state($confirmation))),
            'disclaimer_url' => route('legal', ['doc' => 'payment-guard-disclaimer', 'lang' => $locale]),
            'locale' => $locale,
        ]));
    }

    public function decide(Request $request, string $token, TrustedDecisions $decisions): RedirectResponse
    {
        $confirmation = TrustedConfirmations::findByToken($token);
        abort_if($confirmation === null, 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['confirm', 'decline'])],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $locale = self::locale($request, $confirmation->contact->locale);

        WithLocale::run($locale, function () use ($decisions, $confirmation, $data, $request): void {
            $decisions->decide($confirmation, $data['decision'] === 'confirm', isset($data['comment']) ? (string) $data['comment'] : null, $request->ip(), $request->userAgent());
            Flash::success($data['decision'] === 'confirm' ? 'payflow.flash.trusted_confirmed' : 'payflow.flash.trusted_declined');
        });

        return redirect()->route('trusted.show', ['token' => $token], 303);
    }

    public function verify(Request $request, string $token, TrustedContactService $contacts): SymfonyResponse
    {
        $contact = self::contact($token);
        $state = 'invalid';

        if ($contact !== null && $contact->status === TrustedContactStatus::PendingVerification) {
            $contact = $contacts->verify($contact);
            $state = 'verified';
        } elseif ($contact !== null && $contact->status === TrustedContactStatus::Active) {
            $state = 'verified';
        } elseif ($contact !== null && $contact->status === TrustedContactStatus::Revoked) {
            $state = 'declined';
        }

        return $this->verifiedPage($request, $contact, $token, $state);
    }

    public function declinePrompt(Request $request, string $token): SymfonyResponse
    {
        $contact = self::contact($token);
        $state = $contact === null ? 'invalid' : ($contact->status === TrustedContactStatus::Revoked ? 'declined' : 'decline_prompt');

        return $this->verifiedPage($request, $contact, $token, $state);
    }

    public function decline(Request $request, string $token, TrustedContactService $contacts): SymfonyResponse
    {
        $contact = self::contact($token);

        if ($contact !== null && $contact->status !== TrustedContactStatus::Revoked) {
            $contacts->revoke($contact, null);
        }

        return $this->verifiedPage($request, $contact, $token, $contact !== null ? 'declined' : 'invalid');
    }

    private function verifiedPage(Request $request, ?TrustedContact $contact, string $token, string $state): SymfonyResponse
    {
        $locale = self::locale($request, $contact !== null ? $contact->locale : 'en');
        $workspace = $contact !== null ? Workspace::query()->find($contact->workspace_id) : null;

        return self::noindex($request, Inertia::render('trusted/Verified', [
            'state' => $state,
            'workspace' => $workspace?->name,
            'contact_name' => $contact?->name,
            'active_from' => Iso::dateTime($contact?->active_from),
            'decline_url' => in_array($state, ['verified', 'decline_prompt'], true) && $contact !== null ? app(TrustedContactService::class)->verificationUrl($contact, true) : null,
            'disclaimer_url' => route('legal', ['doc' => 'payment-guard-disclaimer', 'lang' => $locale]),
            'locale' => $locale,
        ]));
    }

    private static function noindex(Request $request, Response $page): SymfonyResponse
    {
        $response = $page->toResponse($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** @return array<string, mixed> */
    private static function present(TrustedConfirmation $confirmation, string $state): array
    {
        $snapshot = $confirmation->snapshot ?? [];
        $workspace = Workspace::query()->findOrFail($confirmation->workspace_id);
        $signals = array_values(array_filter(explode(',', (string) ($snapshot['signals'] ?? ''))));
        $public = array_intersect_key($snapshot, array_flip(['amount_minor', 'currency', 'vendor', 'number', 'description', 'last4', 'bank_country', 'risk_band', 'risk_score', 'version']));

        return [
            'workspace' => $workspace->name,
            'requester' => (string) ($snapshot['requester'] ?? ''),
            'subject' => $confirmation->subject_type->value,
            'snapshot' => array_map(fn (mixed $value): string|int => is_int($value) ? $value : (string) $value, $public),
            'signals' => array_map(fn (string $code): string => (string) __('payflow.signals_plain.'.$code), $signals),
            'expires_at' => Iso::dateTime($confirmation->expires_at),
            'status' => $state,
        ];
    }

    private static function contact(string $token): ?TrustedContact
    {
        return ctype_digit($token) ? TrustedContact::query()->withoutGlobalScopes()->find((int) $token) : null;
    }

    private static function locale(Request $request, string $fallback): string
    {
        $lang = $request->query('lang');

        return is_string($lang) && in_array($lang, ['en', 'ru'], true) ? $lang : (in_array($fallback, ['en', 'ru'], true) ? $fallback : 'en');
    }
}
