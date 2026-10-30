<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev\Payflow;

use App\Billing\Payments\PaymentLinks;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payflow\PublicInvoiceController;
use App\Knowledge\DocumentService;
use App\Models\AiQuery;
use App\Models\Document;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Database\Seeders\Payflow\PayflowDemoSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

final class PayflowDevController extends Controller
{
    public const array DOMAIN_TABLES = [
        'trusted_confirmations', 'payout_approvals', 'payout_requests', 'vendor_bank_accounts', 'vendors', 'trusted_contacts', 'guard_alerts',
        'receiving_details_versions', 'reminders', 'invoice_events', 'payments', 'payment_links', 'invoice_items', 'invoices', 'imports',
        'clients', 'message_templates', 'reminder_policy_steps', 'reminder_policies', 'member_notification_settings', 'digest_runs', 'payflow_settings',
    ];

    public const array SEEDED_WORKSPACES = ['demo-studio', 'E2E Lab', 'E2E Solo'];

    private static function ensureAllowed(): void
    {
        abort_unless(config('payflow.dev_routes') === true && ! app()->isProduction(), 404);
    }

    public function resetDemo(Outbox $outbox): JsonResponse
    {
        self::ensureAllowed();
        $northstar = PayflowDemoSeeder::northstar();

        if ($northstar !== null) {
            foreach (Document::query()->forWorkspace($northstar)->get() as $document) {
                Storage::disk($document->disk ?: DocumentService::disk())->delete($document->path);
                $outbox->publish(Topics::DOCUMENT_DELETED, ['document_id' => $document->id, 'workspace_id' => $document->workspace_id], $document->workspace_id);
            }

            Document::query()->forWorkspace($northstar)->delete();
            AiQuery::query()->where('workspace_id', $northstar->id)->delete();
        }

        DB::statement('TRUNCATE TABLE '.implode(', ', self::DOMAIN_TABLES).' RESTART IDENTITY CASCADE');
        Workspace::query()->whereIn('name', ['E2E Lab', 'E2E Solo'])->get()->each(fn (Workspace $workspace): ?bool => $workspace->delete());
        Artisan::call('db:seed', ['--class' => PayflowDemoSeeder::class, '--force' => true]);

        return response()->json([
            'ok' => true,
            'workspaces' => [
                'northstar' => PayflowDemoSeeder::northstar()?->id,
                'e2e_lab' => Workspace::query()->where('name', 'E2E Lab')->value('id'),
            ],
        ]);
    }

    public function totp(Request $request, Google2FA $google2fa): JsonResponse
    {
        self::ensureAllowed();
        $user = User::query()->where('email', (string) $request->query('email'))->first();

        if ($user === null || $user->two_factor_secret === null || ! $this->isSeeded($user)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $secret = (string) Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        return response()->json(['code' => $google2fa->getCurrentOtp($secret)]);
    }

    public function paymentEndpoint(Request $request): JsonResponse
    {
        self::ensureAllowed();
        $workspace = match ((string) $request->query('workspace', 'northstar')) {
            'e2e_lab' => Workspace::query()->where('name', 'E2E Lab')->first(),
            default => PayflowDemoSeeder::northstar(),
        };
        $account = $workspace !== null ? PaymentAccount::query()->where('workspace_id', $workspace->id)->where('driver', 'fake')->whereNotNull('connected_at')->first() : null;

        if ($account === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'payment_account_id' => $account->id,
            'endpoint_key' => $account->credential('endpoint_key'),
            'webhook_secret' => $account->credential('webhook_secret'),
        ]);
    }

    public function fakePay(string $token, PaymentLinks $links): JsonResponse
    {
        self::ensureAllowed();
        $invoice = PublicInvoiceController::find($token);
        $link = DB::table('payment_links')->where('invoice_id', $invoice->id)->where('active', true)->where('driver', 'fake')->value('provider_id');

        if (! is_string($link)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        PublicInvoiceController::settleFake($invoice, $link, $links);

        return response()->json(['status' => $invoice->refresh()->status->value]);
    }

    private function isSeeded(User $user): bool
    {
        return $user->workspaces()->where(fn ($query) => $query->whereIn('workspaces.slug', self::SEEDED_WORKSPACES)->orWhereIn('workspaces.name', self::SEEDED_WORKSPACES))->exists();
    }
}
