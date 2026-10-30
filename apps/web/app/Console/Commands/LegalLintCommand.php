<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Legal\AiProviderDisclosure;
use App\Legal\LegalDocumentException;
use App\Legal\LegalRepository;
use App\Legal\PlaceholderResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('legal:lint')]
#[Description('Validate legal documents (front-matter, placeholders, rendering)')]
final class LegalLintCommand extends Command
{
    public function handle(LegalRepository $legal, AiProviderDisclosure $aiProviders, PlaceholderResolver $placeholders): int
    {
        $errors = 0;
        $files = $legal->files();

        foreach ($files as $file) {
            $name = basename(dirname($file)).'/'.basename($file);

            try {
                $document = $legal->parseFile($file);
            } catch (LegalDocumentException $exception) {
                $errors++;
                $this->error('  ERR '.$exception->getMessage());

                continue;
            }

            if (str_contains($document->html, '<!--') || str_contains($document->html, '&lt;!--')) {
                $errors++;
                $this->error(sprintf('  ERR %s: an HTML comment survives rendering', $name));

                continue;
            }

            $this->line(sprintf('  ok  %s (v%s, %s)', $name, $document->version, $document->effectiveDate));
        }

        if ($files === []) {
            $this->warn('No legal documents found in '.$legal->path());
        }

        $listed = [];

        foreach ((array) config('legal.subprocessors', []) as $entry) {
            if (is_array($entry) && is_string($entry['ai_provider'] ?? null)) {
                $listed[] = $entry['ai_provider'];
            }
        }

        $discovered = $aiProviders->discovered();

        if ($discovered === null) {
            $this->warn('  ai service not reachable: configured AI providers were not checked against legal.subprocessors');
        }

        foreach (array_diff([...($discovered ?? []), ...(array) config('legal.llm_providers', [])], $listed) as $missing) {
            $errors++;
            $this->error(sprintf('  ERR AI provider "%s" is configured but missing from legal.subprocessors', (string) $missing));
        }

        if ((bool) config('legal.reviewed')) {
            foreach ((array) config('legal.company', []) as $key => $value) {
                if (self::isPlaceholder((string) $value)) {
                    $errors++;
                    $this->error(sprintf('  ERR legal.company.%s is still a placeholder while LEGAL_REVIEWED=true', $key));
                }
            }

            foreach ($placeholders->enabledSubprocessors() as $entry) {
                foreach (['name', 'address', 'location'] as $field) {
                    if (self::isPlaceholder((string) ($entry[$field] ?? ''))) {
                        $errors++;
                        $this->error(sprintf('  ERR sub-processor %s: %s is still a placeholder while LEGAL_REVIEWED=true', (string) ($entry['name'] ?? '?'), $field));
                    }
                }
            }
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private static function isPlaceholder(string $value): bool
    {
        return $value === '' || preg_match('/^\[.*\]$/', $value) === 1 || str_contains($value, 'example.com');
    }
}
