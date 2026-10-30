<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Privacy\PrivacyPurge;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('privacy:purge')]
#[Description('Execute due account/workspace deletions and enforce data retention')]
final class PrivacyPurgeCommand extends Command
{
    public function handle(PrivacyPurge $purge): int
    {
        $result = $purge->run();

        if ($result['skipped']) {
            $this->warn('Another purge is running; skipped.');

            return self::SUCCESS;
        }

        $this->table(['category', 'count'], [
            ...array_map(fn (string $key, int $count): array => ["deleted {$key}", $count], array_keys($result['deletions']), $result['deletions']),
            ...array_map(fn (string $key, int $count): array => [$key, $count], array_keys($result['retention']), $result['retention']),
        ]);

        return self::SUCCESS;
    }
}
