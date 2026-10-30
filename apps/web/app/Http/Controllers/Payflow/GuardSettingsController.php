<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Domain\Guard\Eligibility\EligibleMember;
use App\Domain\Guard\Eligibility\GuardEligibility;
use App\Domain\Guard\Settings\GuardSettingsService;
use App\Domain\Guard\Trusted\TrustedContactService;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Http\Requests\Payflow\GuardSettingsRequest;
use App\Http\Resources\Payflow\GuardResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class GuardSettingsController extends PayflowController
{
    public function show(Request $request, GuardEligibility $eligibility): Response
    {
        $workspace = $this->workspace();

        return Inertia::render('settings/Guard', [
            'settings' => GuardResource::guardSettings(WorkspaceProvisioner::settingsFor($workspace)),
            'eligible_approvers' => array_map(
                fn (EligibleMember $member): array => GuardResource::eligibleApprover($member),
                $eligibility->members($workspace, 'payouts.approve'),
            ),
            'trusted_contact' => GuardResource::trustedContact(TrustedContactService::current($workspace)),
            'currencies' => self::currencies(),
            'can_edit' => $this->user($request)->can('guard.configure'),
        ]);
    }

    public function update(GuardSettingsRequest $request, GuardSettingsService $service): RedirectResponse
    {
        $service->update($this->workspace(), $this->user($request), $request->payload());

        return $this->back('guard_settings_saved');
    }

    public function storeTrustedContact(Request $request, TrustedContactService $contacts): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'locale' => ['required', Rule::in(['en', 'ru'])],
        ]);
        $contacts->add($this->workspace(), $this->user($request), (string) $data['name'], (string) $data['email'], (string) $data['locale']);

        return $this->back('trusted_contact_added');
    }

    public function destroyTrustedContact(Request $request, TrustedContactService $contacts): RedirectResponse
    {
        $contact = TrustedContactService::current($this->workspace());
        abort_if($contact === null, 404);
        $contacts->revoke($contact, $this->user($request));

        return $this->back('trusted_contact_revoked');
    }
}
