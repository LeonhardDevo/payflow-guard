<?php

declare(strict_types=1);

namespace App\Legal;

final readonly class LegalDocument
{
    /** @param  list<array{id: string, text: string, level: int}>  $toc */
    public function __construct(
        public string $key,
        public string $locale,
        public string $title,
        public string $version,
        public string $effectiveDate,
        public ?string $summaryOfChanges,
        public bool $requiresAcceptance,
        public bool $templateNotice,
        public string $html,
        public array $toc,
    ) {}

    public function url(): string
    {
        return route('legal', ['doc' => $this->key]);
    }

    /** @return array{key: string, title: string, version: string, effective_date: string} */
    public function summary(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'version' => $this->version,
            'effective_date' => $this->effectiveDate,
        ];
    }

    /** @return array{key: string, title: string, version: string, effective_date: string, html: string, toc: list<array{id: string, text: string, level: int}>} */
    public function toPage(): array
    {
        return [...$this->summary(), 'html' => $this->html, 'toc' => $this->toc];
    }
}
