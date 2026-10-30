<?php

declare(strict_types=1);

namespace App\Legal;

use Illuminate\Contracts\Config\Repository as Config;

final readonly class PlaceholderResolver
{
    public const string PATTERN = '/\{\{\s*([a-z][a-z0-9_\-]*(?:\.[a-z][a-z0-9_\-]*)*)\s*\}\}/i';

    private const array COMPANY_FIELDS = ['name', 'registration', 'address', 'email', 'privacy_email', 'dpo', 'eu_representative', 'governing_law', 'support_hours'];

    public function __construct(private Config $config, private AiProviderDisclosure $aiProviders) {}

    /** @return list<array<string, mixed>> */
    public function enabledSubprocessors(): array
    {
        $ai = null;
        $enabled = [];

        foreach ((array) $this->config->get('legal.subprocessors', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (is_string($entry['ai_provider'] ?? null)) {
                $ai ??= $this->aiProviders->enabled();
                $entry['enabled'] = in_array($entry['ai_provider'], $ai, true);
            }

            if ((bool) ($entry['enabled'] ?? false)) {
                $enabled[] = $entry;
            }
        }

        return $enabled;
    }

    /** @throws LegalDocumentException */
    public function resolve(string $text, string $locale, string $file): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            fn (array $match): string => $this->value(strtolower($match[1]), $locale) ?? throw LegalDocumentException::unknownPlaceholder($match[1], $file),
            $text,
        );
    }

    /** @return list<string> placeholders used in the text that cannot be resolved */
    public function unknown(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique(array_filter(
            $matches[1],
            fn (string $name): bool => $this->value(strtolower($name), 'en') === null,
        )));
    }

    private function value(string $name, string $locale): ?string
    {
        if ($name === 'subprocessors_table') {
            return $this->subprocessorsTable($locale);
        }

        $parts = explode('.', $name, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$group, $field] = $parts;

        return match ($group) {
            'company' => in_array($field, self::COMPANY_FIELDS, true)
                ? $this->scalar($this->config->get("legal.company.{$field}"))
                : null,
            'product' => match ($field) {
                'name' => $this->scalar($this->config->get('app.name')),
                'url' => rtrim((string) $this->config->get('app.url'), '/'),
                'support_email' => $this->scalar($this->config->get('product.support_email')),
                default => null,
            },
            'retention' => $this->retention($field),
            default => null,
        };
    }

    private function retention(string $key): ?string
    {
        $value = $this->config->get("retention.defaults.{$key}") ?? $this->config->get("retention.{$key}");

        return is_int($value) || is_string($value) ? (string) $value : null;
    }

    private function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function subprocessorsTable(string $locale): string
    {
        $headers = [
            __('legal.subprocessors.name', [], $locale),
            __('legal.subprocessors.purpose', [], $locale),
            __('legal.subprocessors.data', [], $locale),
            __('legal.subprocessors.location', [], $locale),
            __('legal.subprocessors.address', [], $locale),
        ];

        $rows = [];

        foreach ($this->enabledSubprocessors() as $entry) {
            $rows[] = [
                (string) ($entry['name'] ?? ''),
                $this->localized($entry['purpose'] ?? '', $locale),
                $this->localized($entry['data'] ?? '', $locale),
                (string) ($entry['location'] ?? ''),
                (string) ($entry['address'] ?? ''),
            ];
        }

        $line = static fn (array $cells): string => '| '.implode(' | ', array_map(
            static fn (string $cell): string => str_replace(['|', "\n"], ['\|', ' '], $cell),
            $cells,
        )).' |';

        return implode("\n", [
            $line($headers),
            '| --- | --- | --- | --- | --- |',
            ...array_map($line, $rows),
        ]);
    }

    private function localized(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? reset($value) ?: '');
        }

        return (string) $value;
    }
}
