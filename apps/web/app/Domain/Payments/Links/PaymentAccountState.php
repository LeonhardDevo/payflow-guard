<?php

declare(strict_types=1);

namespace App\Domain\Payments\Links;

use App\Billing\Markets\MarketResolver;
use App\Domain\Guard\Enums\AlertStatus;
use App\Domain\Guard\Models\GuardAlert;
use App\Models\PaymentAccount;
use App\Models\Workspace;

final readonly class PaymentAccountState
{
    public function __construct(private MarketResolver $markets) {}

    public function live(Workspace|int $workspace): ?PaymentAccount
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return PaymentAccount::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('connected_at')
            ->orderByDesc('connected_at')
            ->first();
    }

    public function forCurrency(Workspace|int $workspace, string $currency): ?PaymentAccount
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return PaymentAccount::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('connected_at')
            ->get()
            ->first(fn (PaymentAccount $account): bool => in_array(strtoupper($currency), $this->supportedCurrencies($account), true));
    }

    /** @return list<string> */
    public function supportedCurrencies(PaymentAccount $account): array
    {
        if ($account->driver === 'fake' && $this->markets->forcedGateway() !== 'fake') {
            return [];
        }

        return $this->markets->currenciesFor($account->driver);
    }

    public function isRestricted(PaymentAccount $account): bool
    {
        return GuardAlert::query()
            ->forWorkspace($account->workspace_id)
            ->where('dedupe_key', 'par:'.$account->id)
            ->where('status', '!=', AlertStatus::Resolved->value)
            ->exists();
    }

    public function status(?PaymentAccount $account): string
    {
        if ($account === null || $account->connected_at === null) {
            return 'disconnected';
        }

        return $this->isRestricted($account) ? 'restricted' : 'active';
    }

    public static function mode(PaymentAccount $account): string
    {
        $secret = $account->credential('restricted_key') ?? $account->credential('secret_key') ?? '';

        return str_contains($secret, '_live_') || str_starts_with($secret, 'live_') ? 'live' : 'test';
    }

    public static function keyHint(PaymentAccount $account): ?string
    {
        $secret = $account->credential('restricted_key') ?? $account->credential('secret_key');

        return $secret !== null ? '…'.substr($secret, -4) : null;
    }

    /** @return array{driver: string, mode: string, status: string, charges_enabled: bool}|null */
    public function shared(Workspace $workspace): ?array
    {
        $account = $this->live($workspace);

        if ($account === null) {
            return null;
        }

        $status = $this->status($account);

        return [
            'driver' => $account->driver,
            'mode' => self::mode($account),
            'status' => $status,
            'charges_enabled' => $status === 'active' && $this->supportedCurrencies($account) !== [],
        ];
    }
}
