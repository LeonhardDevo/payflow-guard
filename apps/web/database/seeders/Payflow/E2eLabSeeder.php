<?php

declare(strict_types=1);

namespace Database\Seeders\Payflow;

use App\Domain\Guard\Enums\TrustedContactStatus;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Receivables\Models\Client;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Support\Tokens;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Legal\LegalAcceptances;
use App\Models\PaymentAccount;
use App\Models\Subscription;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceService;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;

final class E2eLabSeeder
{
    public const array THRESHOLDS = [
        'USD' => ['auto_approve_below_minor' => 50000, 'dual_approval_from_minor' => 1000000, 'auto_approve_cumulative_minor' => 100000],
        'EUR' => ['auto_approve_below_minor' => 50000, 'dual_approval_from_minor' => 1000000, 'auto_approve_cumulative_minor' => 100000],
    ];

    private SeedContext $context;

    public function run(SeedContext $context): void
    {
        $this->context = $context;

        if (! Workspace::query()->where('name', 'E2E Lab')->exists()) {
            $this->lab();
        }

        if (! Workspace::query()->where('name', 'E2E Solo')->exists()) {
            $this->solo();
        }
    }

    /** @return array<string, string> */
    public static function totpSecrets(): array
    {
        $path = __DIR__.DIRECTORY_SEPARATOR.'e2e-totp.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        return is_array($data) ? array_filter($data, fn (mixed $value, mixed $key): bool => is_string($value) && is_string($key), ARRAY_FILTER_USE_BOTH) : [];
    }

    private function lab(): void
    {
        $owner = $this->user('e2e@payflow.test', 'E2E Owner', 'en');
        $workspace = $this->workspace($owner, 'E2E Lab', 'acct_fake_e2elab000001');
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $settings->forceFill([
            'send_window_start' => '00:00',
            'send_window_end' => '23:59',
            'business_days_only' => false,
            'require_second_factor' => true,
            'sender_display_name' => 'E2E Lab — Billing',
            'reply_to_email' => 'billing@e2e.example',
            'privacy_contact_email' => 'privacy@e2e.example',
            'approval_thresholds' => self::THRESHOLDS,
        ])->save();

        $this->member($workspace, $this->user('e2e-approver@payflow.test', 'E2E Approver', 'ru'), WorkspaceRole::Approver, -10);
        $this->member($workspace, $this->user('e2e-admin@payflow.test', 'E2E Admin', 'en'), WorkspaceRole::Admin, -10);
        $this->member($workspace, $this->user('e2e-finance@payflow.test', 'E2E Finance', 'en'), WorkspaceRole::Finance, -10);
        $this->member($workspace, $this->user('e2e-new-approver@payflow.test', 'E2E New Approver', 'en'), WorkspaceRole::Approver, 2);

        foreach (['e2e@payflow.test' => 700000100, 'e2e-approver@payflow.test' => 700000101] as $email => $telegramId) {
            $user = User::query()->where('email', $email)->firstOrFail();
            TelegramAccount::query()->updateOrCreate(['user_id' => $user->id], [
                'workspace_id' => $workspace->id,
                'telegram_user_id' => $telegramId,
                'chat_id' => $telegramId,
                'language_code' => $user->locale,
                'linked_at' => $this->context->at(-10),
            ]);
        }

        foreach ([['QA Client', 'qa-client@e2e.example', 'en'], ['Тестовый клиент', 'ru-client@e2e.example', 'ru']] as [$name, $email, $locale]) {
            $client = new Client;
            $client->forceFill([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'email' => $email,
                'locale' => $locale,
                'currency' => 'USD',
                'created_by' => $owner->id,
            ])->save();
        }
    }

    private function solo(): void
    {
        $owner = $this->user('solo@payflow.test', 'Solo Owner', 'en');
        $workspace = $this->workspace($owner, 'E2E Solo', 'acct_fake_e2esolo00001');
        WorkspaceProvisioner::settingsFor($workspace)->forceFill(['approval_thresholds' => self::THRESHOLDS, 'require_second_factor' => true])->save();

        $contact = new TrustedContact;
        $contact->forceFill([
            'workspace_id' => $workspace->id,
            'name' => 'Pat Reviewer',
            'email' => 'trusted@e2e.example',
            'locale' => 'en',
            'status' => TrustedContactStatus::Active,
            'verified_at' => $this->context->at(-10),
            'active_from' => $this->context->at(-7),
            'created_by' => $owner->id,
        ])->save();
    }

    private function workspace(User $owner, string $name, string $accountId): Workspace
    {
        $workspace = app(WorkspaceService::class)->create($owner, [
            'name' => $name,
            'timezone' => SeedContext::TIMEZONE,
            'currency' => 'USD',
            'country' => 'LV',
            'locale' => 'en',
        ]);

        WorkspaceProvisioner::provision($workspace);
        DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $owner->id)->update(['guard_eligible_from' => $workspace->created_at]);

        Subscription::query()->updateOrCreate(['workspace_id' => $workspace->id], [
            'gateway' => 'fake',
            'plan' => 'pro',
            'status' => SubscriptionStatus::Active,
            'interval' => 'month',
            'currency' => 'USD',
            'stripe_customer_id' => 'cus_fake_'.$workspace->id,
            'stripe_subscription_id' => 'sub_fake_'.$workspace->id,
            'current_period_end' => $this->context->realNow->addMonth(),
            'cancel_at_period_end' => false,
        ]);

        PaymentAccount::query()->updateOrCreate(['workspace_id' => $workspace->id, 'driver' => 'fake'], [
            'credentials' => [
                'external_account_id' => $accountId,
                'endpoint_key' => Tokens::seeded($name.'-endpoint', 32),
                'webhook_secret' => 'whsec_fake_'.Tokens::seeded($name.'-secret', 32),
            ],
            'connected_at' => $this->context->at(-30),
        ]);

        $acceptances = app(LegalAcceptances::class);

        if ($acceptances->pending($owner, $workspace, $owner->locale) !== []) {
            $acceptances->acceptWorkspace($owner, $workspace, $owner->locale, null);
        }

        return $workspace;
    }

    private function member(Workspace $workspace, User $user, WorkspaceRole $role, int $eligibleOffset): void
    {
        if (! $user->belongsToWorkspace($workspace)) {
            $workspace->addMember($user, $role);
        }

        DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $user->id)->update([
            'role' => $role->value,
            'guard_eligible_from' => $this->context->at($eligibleOffset),
            'created_at' => $eligibleOffset > 0 ? $this->context->at(-1) : $this->context->at(-40),
        ]);
        $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
    }

    private function user(string $email, string $name, string $locale): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $secret = self::totpSecrets()[$email] ?? null;
            $user->forceFill([
                'name' => $name,
                'password' => 'password',
                'locale' => $locale,
                'email_verified_at' => $this->context->at(-60),
                'two_factor_secret' => $secret !== null ? Fortify::currentEncrypter()->encrypt($secret) : null,
                'two_factor_recovery_codes' => $secret !== null ? Fortify::currentEncrypter()->encrypt((string) json_encode(['recovery-'.Tokens::seeded($email, 10)])) : null,
                'two_factor_confirmed_at' => $secret !== null ? $this->context->at(-60) : null,
            ])->save();

            $acceptances = app(LegalAcceptances::class);

            if ($acceptances->pending($user, null, $locale) !== []) {
                $acceptances->acceptRegistration($user, $locale, null);
            }
        }

        return $user;
    }
}
