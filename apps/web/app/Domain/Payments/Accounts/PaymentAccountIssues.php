<?php

declare(strict_types=1);

namespace App\Domain\Payments\Accounts;

use App\Audit\Auditor;
use App\Billing\BillingException;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Notifications\Routing\ReceivablesNotifications;
use App\Models\PaymentAccount;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Stripe\Exception\ApiErrorException;
use Throwable;

final readonly class PaymentAccountIssues
{
    public function __construct(
        private GuardAlerts $alerts,
        private ReceivablesNotifications $notifications,
        private Auditor $auditor,
    ) {}

    public static function restrictionReason(Throwable $exception): ?string
    {
        $status = null;

        for ($current = $exception; $current !== null && $status === null; $current = $current->getPrevious()) {
            $status = match (true) {
                $current instanceof ApiErrorException => $current->getHttpStatus(),
                $current instanceof RequestException => $current->response->status(),
                default => null,
            };
        }

        return match ($status) {
            401 => 'unauthorized',
            403 => 'forbidden',
            default => null,
        };
    }

    public function failed(PaymentAccount $account, BillingException $exception): bool
    {
        $reason = self::restrictionReason($exception);

        if ($reason === null) {
            return false;
        }

        $this->restrict($account, $reason);

        return true;
    }

    public function restrict(PaymentAccount $account, string $reason): GuardAlert
    {
        $alert = $this->alerts->ensure($account->workspace_id, AlertType::PaymentAccountRestricted, AlertSubjectType::PaymentAccount, $account->id, 'par:'.$account->id, [
            'provider' => $account->driver,
            'reason' => $reason,
        ]);

        if ($alert->wasRecentlyCreated) {
            $this->auditor->record('payflow.payment_account.restricted', $account, metadata: ['driver' => $account->driver, 'reason' => $reason], workspace: $account->workspace_id, subjectLabel: $account->driver, system: true);
            $this->notifications->paymentAccountRestricted($account->workspace_id, $account->id, $account->driver, $reason, $alert->id);
        }

        return $alert;
    }

    public function providerError(PaymentAccount|int $account, string $errorCode, ?string $invoiceNumber = null, ?string $driver = null): GuardAlert
    {
        $model = $account instanceof PaymentAccount ? $account : PaymentAccount::query()->findOrFail($account);
        $code = in_array($errorCode, ReceivablesNotifications::PROVIDER_ERRORS, true) ? $errorCode : 'unknown';
        $provider = $driver ?? $model->driver;
        $alert = $this->alerts->ensure($model->workspace_id, AlertType::PaymentProviderError, AlertSubjectType::PaymentAccount, $model->id, 'ppe:'.$model->id, array_filter([
            'provider' => $provider,
            'error' => $code,
            'number' => $invoiceNumber,
        ], fn (?string $value): bool => $value !== null));

        if ($alert->wasRecentlyCreated) {
            $this->notifications->paymentProviderError($model->workspace_id, $model->id, $provider, $code, $alert->id);
        }

        return $alert;
    }

    public function cleared(PaymentAccount $account, ?User $actor): void
    {
        $this->alerts->resolve($account->workspace_id, 'par:'.$account->id, $actor);
        $this->alerts->resolve($account->workspace_id, 'ppe:'.$account->id, $actor);
    }
}
