<?php

declare(strict_types=1);

namespace App\Legal;

use App\Locale\Locales;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class LegalRepository
{
    public const string TEMPLATE_NOTICE = '<!-- template-notice -->';

    private const string KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    private const string VERSION_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/';

    /** @var array<string, LegalDocument> */
    private array $rendered = [];

    public function __construct(
        private readonly Config $config,
        private readonly PlaceholderResolver $placeholders,
    ) {}

    public function path(): string
    {
        return rtrim((string) $this->config->get('legal.path'), '/\\');
    }

    public function reviewed(): bool
    {
        return (bool) $this->config->get('legal.reviewed', false);
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_values(array_filter(
            (array) $this->config->get('legal.documents', []),
            fn (mixed $key): bool => is_string($key) && $this->exists($key),
        ));

        foreach ($this->localeDirectories() as $directory) {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*.md') ?: [] as $file) {
                $name = basename($file, '.md');

                if (! str_contains($name, '@') && preg_match(self::KEY_PATTERN, $name) === 1 && ! in_array($name, $keys, true)) {
                    $keys[] = $name;
                }
            }
        }

        return $keys;
    }

    public function exists(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1 && $this->locateFile($key, null, Locales::fallback()) !== null;
    }

    /** @throws LegalDocumentException */
    public function current(string $key, string $locale): ?LegalDocument
    {
        return $this->load($key, null, $locale);
    }

    /** @throws LegalDocumentException */
    public function metadata(string $key, string $locale): ?LegalDocument
    {
        return $this->load($key, null, $locale, render: false);
    }

    /** @throws LegalDocumentException */
    public function version(string $key, string $version, string $locale): ?LegalDocument
    {
        $current = $this->current($key, $locale);

        if ($current !== null && $current->version === $version) {
            return $current;
        }

        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            return null;
        }

        return $this->load($key, $version, $locale);
    }

    /**
     * @return list<array{version: string, effective_date: string}>
     *
     * @throws LegalDocumentException
     */
    public function versions(string $key, string $locale): array
    {
        $current = $this->current($key, $locale);

        if ($current === null) {
            return [];
        }

        $archived = [];
        $directory = $this->path().DIRECTORY_SEPARATOR.$current->locale;

        foreach (glob($directory.DIRECTORY_SEPARATOR.$key.'@*.md') ?: [] as $file) {
            $version = substr(basename($file, '.md'), strlen($key) + 1);

            if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
                continue;
            }

            $document = $this->load($key, $version, $current->locale);

            if ($document !== null && $document->version !== $current->version) {
                $archived[$document->version] = ['version' => $document->version, 'effective_date' => $document->effectiveDate];
            }
        }

        $archived = array_values($archived);
        usort($archived, fn (array $a, array $b): int => [$b['effective_date'], $b['version']] <=> [$a['effective_date'], $a['version']]);

        return [['version' => $current->version, 'effective_date' => $current->effectiveDate], ...$archived];
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = [];

        foreach ($this->localeDirectories() as $directory) {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*.md') ?: [] as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @throws LegalDocumentException */
    public function parseFile(string $file): LegalDocument
    {
        $name = basename($file, '.md');

        return $this->parse(explode('@', $name, 2)[0], $file);
    }

    /** @throws LegalDocumentException */
    private function load(string $key, ?string $version, string $locale, bool $render = true): ?LegalDocument
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return null;
        }

        $file = $this->locateFile($key, $version, $locale);

        if ($file === null) {
            return null;
        }

        $cacheKey = $file.'|'.(string) @filemtime($file).'|'.($render ? 'html' : 'meta');

        return $this->rendered[$cacheKey] ??= $this->parse($key, $file, $render);
    }

    private function locateFile(string $key, ?string $version, string $locale): ?string
    {
        $name = $version === null ? $key : $key.'@'.$version;
        $candidates = array_values(array_unique([$locale, Locales::fallback(), ...Locales::supported()]));

        foreach ($candidates as $candidate) {
            $file = $this->path().DIRECTORY_SEPARATOR.$candidate.DIRECTORY_SEPARATOR.$name.'.md';

            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function localeDirectories(): array
    {
        return array_values(array_filter(
            array_map(fn (string $locale): string => $this->path().DIRECTORY_SEPARATOR.$locale, Locales::supported()),
            is_dir(...),
        ));
    }

    /** @throws LegalDocumentException */
    private function parse(string $key, string $file, bool $render = true): LegalDocument
    {
        $locale = basename(dirname($file));
        $raw = str_replace("\r\n", "\n", (string) file_get_contents($file));
        $raw = (string) preg_replace('/^\x{FEFF}/u', '', $raw);
        $templateNotice = false;

        if (str_starts_with(ltrim($raw), self::TEMPLATE_NOTICE)) {
            $templateNotice = true;
            $raw = ltrim(substr(ltrim($raw), strlen(self::TEMPLATE_NOTICE)), "\n");
        }

        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $raw, $match) !== 1) {
            throw LegalDocumentException::invalidFrontMatter($file, 'missing --- block');
        }

        try {
            $meta = Yaml::parse($match[1], Yaml::PARSE_DATETIME);
        } catch (ParseException $exception) {
            throw LegalDocumentException::invalidFrontMatter($file, $exception->getMessage());
        }

        if (! is_array($meta)) {
            throw LegalDocumentException::invalidFrontMatter($file, 'front-matter must be a mapping');
        }

        foreach (['title', 'version', 'effective_date'] as $required) {
            if (! isset($meta[$required]) || $meta[$required] === '') {
                throw LegalDocumentException::invalidFrontMatter($file, "missing `{$required}`");
            }
        }

        $effective = $meta['effective_date'];
        $effectiveDate = $effective instanceof \DateTimeInterface ? $effective->format('Y-m-d') : (string) $effective;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate) !== 1) {
            throw LegalDocumentException::invalidFrontMatter($file, '`effective_date` must be YYYY-MM-DD');
        }

        $version = (string) $meta['version'];

        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw LegalDocumentException::invalidFrontMatter($file, '`version` may only contain letters, digits, ".", "_" and "-"');
        }

        $body = $match[2];

        if (str_contains($body, self::TEMPLATE_NOTICE)) {
            $templateNotice = true;
            $body = ltrim(str_replace(self::TEMPLATE_NOTICE, '', $body), "\n");
        }

        $summary = $meta['summary_of_changes'] ?? null;
        [$html, $toc] = $render ? $this->render($this->placeholders->resolve($body, $locale, $file)) : ['', []];

        return new LegalDocument(
            key: $key,
            locale: $locale,
            title: $this->placeholders->resolve((string) $meta['title'], $locale, $file),
            version: $version,
            effectiveDate: $effectiveDate,
            summaryOfChanges: is_scalar($summary) && (string) $summary !== ''
                ? $this->placeholders->resolve((string) $summary, $locale, $file)
                : null,
            requiresAcceptance: filter_var($meta['requires_acceptance'] ?? false, FILTER_VALIDATE_BOOLEAN),
            templateNotice: $templateNotice,
            html: $html,
            toc: $toc,
        );
    }

    /** @return array{0: string, 1: list<array{id: string, text: string, level: int}>} */
    private function render(string $markdown): array
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        $document = (new MarkdownParser($environment))->parse($markdown);
        $toc = [];
        $used = [];

        foreach ($document->iterator() as $node) {
            if (! $node instanceof Heading) {
                continue;
            }

            $text = trim(StringContainerHelper::getChildText($node));
            $base = Str::slug($text) ?: 'section';
            $id = $base;

            for ($i = 2; isset($used[$id]); $i++) {
                $id = $base.'-'.$i;
            }

            $used[$id] = true;
            $node->data->set('attributes/id', $id);

            if (in_array($node->getLevel(), [2, 3], true)) {
                $toc[] = ['id' => $id, 'text' => $text, 'level' => $node->getLevel()];
            }
        }

        return [trim((string) (new HtmlRenderer($environment))->renderDocument($document)), $toc];
    }
}
