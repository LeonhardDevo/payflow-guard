<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Stripe\StripeCatalogSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;

#[Signature('billing:sync-stripe {--dry-run : Show what would change without writing to Stripe}')]
#[Description('Create or update Stripe Products, Prices (by lookup_key) and the Customer Portal configuration')]
final class BillingSyncStripeCommand extends Command
{
    public function handle(StripeCatalogSync $sync): int
    {
        $secret = config('billing.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            $this->error('STRIPE_SECRET is not set.');

            return self::FAILURE;
        }

        try {
            $report = $sync->run((bool) $this->option('dry-run'));
        } catch (ApiErrorException $exception) {
            $this->error('Stripe API error: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Kind', 'Key', 'Action', 'Stripe id'],
            array_map(fn (array $row): array => [$row['kind'], $row['key'], $row['action'], $row['id'] ?? '-'], $report),
        );

        $this->info($this->option('dry-run') ? 'Dry run: nothing was written.' : 'Stripe catalog is in sync.');

        return self::SUCCESS;
    }
}
