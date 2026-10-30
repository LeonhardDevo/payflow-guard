<?php

declare(strict_types=1);

namespace Database\Seeders\Payflow;

use App\Domain\Receivables\Models\Client;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class PayflowDemoSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(20261022);
        $context = new SeedContext;

        try {
            $northstar = app(NorthstarSeeder::class)->run($context);

            if ($northstar instanceof Workspace) {
                app(NorthstarGuardSeeder::class)->run($context, $northstar);
            }

            app(E2eLabSeeder::class)->run($context);
        } finally {
            $context->release();
        }
    }

    public static function northstar(): ?Workspace
    {
        return Workspace::query()->where('slug', 'demo-studio')->first();
    }

    public static function alreadySeeded(Workspace $workspace): bool
    {
        return Client::query()->forWorkspace($workspace)->where('name', 'Helios Media Group')->exists();
    }
}
