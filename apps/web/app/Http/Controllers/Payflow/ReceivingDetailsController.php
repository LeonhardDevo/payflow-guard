<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Domain\Guard\Models\ReceivingDetailsVersion;
use App\Domain\Guard\Receiving\ReceivingDetailsService;
use App\Domain\Guard\StepUp\StepUpGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ReceivingDetailsController extends PayflowController
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['details' => ['required', 'string', 'min:1', 'max:2000']]);
        $this->service()->request($this->workspace(), $this->user($request), (string) $data['details']);

        return $this->back('receiving_details_requested');
    }

    public function confirm(Request $request, ReceivingDetailsVersion $version): RedirectResponse
    {
        $this->authorizeDecision();
        StepUpGate::require($request);
        $this->service()->confirm($version, $this->user($request));

        return $this->back('receiving_details_confirmed');
    }

    public function reject(Request $request, ReceivingDetailsVersion $version): RedirectResponse
    {
        $this->authorizeDecision();
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->service()->reject($version, $this->user($request), (string) $data['reason']);

        return $this->back('receiving_details_rejected');
    }

    public function cancel(Request $request, ReceivingDetailsVersion $version): RedirectResponse
    {
        abort_unless($this->role()->allows('guard.configure'), 403);
        $this->service()->cancel($version, $this->user($request));

        return $this->back('receiving_details_canceled');
    }

    private function authorizeDecision(): void
    {
        $role = $this->role();
        abort_unless($role->allows('guard.configure') || $role->allows('payouts.approve'), 403);
    }

    private function service(): ReceivingDetailsService
    {
        return app(ReceivingDetailsService::class);
    }
}
