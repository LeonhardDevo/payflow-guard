<?php

declare(strict_types=1);

namespace Tests\Feature\Payflow\Support;

use App\Domain\Receivables\Enums\InvoiceSource;
use App\Domain\Receivables\Invoices\InvoiceDrafts;
use App\Domain\Receivables\Invoices\InvoiceIssuer;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\FrozenClock;
use App\Enums\SubscriptionStatus;
use App\Models\OutboxMessage;
use App\Models\PaymentAccount;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

final class Payflow
{
    public static function fixture(string $name): mixed
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/payflow/'.$name), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $payload */
    public static function emailPayloadErrors(array $payload): ?string
    {
        $schema = (string) file_get_contents(base_path('../../contracts/outbox/email-send.v2.schema.json'));
        $validator = new Validator;
        $validator->setStopAtFirstError(true);
        $result = $validator->validate(json_decode((string) json_encode($payload)), $schema);

        return $result->isValid() ? null : (string) json_encode((new ErrorFormatter)->format($result->error()));
    }

    /** @return list<array<string, mixed>> */
    public static function emails(): array
    {
        return array_values(OutboxMessage::query()->where('topic', 'email.send')->orderBy('created_at')->get()
            ->map(fn (OutboxMessage $message): array => (array) $message->payload)->all());
    }

    public static function freeze(string $instant): FrozenClock
    {
        $clock = new FrozenClock($instant);
        app()->instance(Clock::class, $clock);
        Carbon::setTestNow(CarbonImmutable::parse($instant));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($instant));

        return $clock;
    }

    public static function pro(Workspace $workspace): Workspace
    {
        Subscription::query()->updateOrCreate(['workspace_id' => $workspace->id], [
            'gateway' => 'fake',
            'plan' => 'pro',
            'status' => SubscriptionStatus::Active,
            'interval' => 'month',
            'currency' => 'USD',
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
        ]);
        $workspace->unsetRelation('subscription');

        return $workspace;
    }

    public static function fakeAccount(Workspace $workspace): PaymentAccount
    {
        return PaymentAccount::query()->create([
            'workspace_id' => $workspace->id,
            'driver' => 'fake',
            'credentials' => ['external_account_id' => 'acct_fake_test', 'endpoint_key' => 'ek', 'webhook_secret' => 'whsec_fake_test'],
            'connected_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function client(Workspace $workspace, array $attributes = []): Client
    {
        WorkspaceProvisioner::settingsFor($workspace);
        $client = new Client;
        $client->forceFill([
            'workspace_id' => $workspace->id,
            'name' => 'Client '.fake()->unique()->numerify('####'),
            'email' => 'ap'.fake()->unique()->numerify('####').'@client.example',
            'locale' => 'en',
            'currency' => 'USD',
            ...$attributes,
        ])->save();

        return $client->refresh();
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3?: int}>  $items
     */
    public static function invoice(Workspace $workspace, User $actor, Client $client, string $issue, string $due, array $items = [['Services', '1', 100000]], bool $issueIt = true, ?string $number = null): Invoice
    {
        return app(CurrentWorkspace::class)->runAs($workspace, function () use ($workspace, $actor, $client, $issue, $due, $items, $issueIt, $number): Invoice {
            $invoice = app(InvoiceDrafts::class)->create($workspace, $actor, [
                'client_id' => $client->id,
                'number' => $number,
                'currency' => $client->currency,
                'locale' => $client->locale,
                'issue_date' => $issue,
                'due_date' => $due,
                'items' => array_map(fn (array $item): array => [
                    'description' => $item[0],
                    'quantity' => $item[1],
                    'unit_price_minor' => $item[2],
                    'tax_rate_bp' => $item[3] ?? 0,
                ], $items),
            ], InvoiceSource::Manual);

            if ($issueIt) {
                app(InvoiceIssuer::class)->markSent($invoice, $actor, CarbonImmutable::parse($issue.' 09:00', 'UTC'));
            }

            return $invoice->refresh();
        });
    }
}
