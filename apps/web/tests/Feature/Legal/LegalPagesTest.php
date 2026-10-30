<?php

declare(strict_types=1);

use App\Legal\AiProviderDisclosure;
use App\Legal\LegalDocumentException;
use App\Legal\LegalRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

function legalSandbox(): string
{
    $path = storage_path('framework/testing/legal-'.bin2hex(random_bytes(4)));
    File::copyDirectory(base_path('tests/Fixtures/legal'), $path);
    config(['legal.path' => $path]);
    app()->forgetInstance(LegalRepository::class);

    return $path;
}

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/legal-*')) ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

it('lists the available documents in configured order', function (): void {
    $this->get(route('legal.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('marketing/LegalIndex', false)
            ->where('docs.0.key', 'terms')
            ->where('docs.0.version', '2.0')
            ->where('docs.0.effective_date', '2026-10-01')
            ->where('docs.1.key', 'privacy')
            ->where('reviewed', false)
            ->etc());
});

it('renders a document with resolved placeholders, heading ids and a toc', function (): void {
    config(['legal.company.name' => 'Acme Fixture GmbH', 'app.name' => 'FixtureApp', 'product.support_email' => 'help@fixture.test']);

    $this->get(route('legal', 'terms'))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $page->component('marketing/Legal', false)
                ->where('doc.key', 'terms')
                ->where('doc.title', 'Fixture Terms of FixtureApp')
                ->where('doc.version', '2.0')
                ->where('doc.effective_date', '2026-10-01')
                ->where('doc.toc', [
                    ['id' => 'parties', 'text' => 'Parties', 'level' => 2],
                    ['id' => 'accounts', 'text' => 'Accounts', 'level' => 3],
                    ['id' => 'parties-2', 'text' => 'Parties', 'level' => 2],
                ])
                ->where('versions', [
                    ['version' => '2.0', 'effective_date' => '2026-10-01'],
                    ['version' => '1.0', 'effective_date' => '2026-01-01'],
                ])
                ->where('reviewed', false);

            $html = $page->toArray()['props']['doc']['html'];

            expect($html)->toContain('<h2 id="parties">')
                ->and($html)->toContain('Acme Fixture GmbH')
                ->and($html)->toContain('help@fixture.test')
                ->and($html)->not->toContain('{{')
                ->and($html)->not->toContain('<script>')
                ->and($html)->toContain('&lt;script&gt;')
                ->and($html)->not->toContain('template-notice');
        });
});

it('renders a previous version on request and 404s unknown versions', function (): void {
    $this->get(route('legal', ['doc' => 'terms', 'version' => '1.0']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('doc.version', '1.0')->where('doc.title', 'Fixture Terms (old)')->etc());

    $this->get(route('legal', ['doc' => 'terms', 'version' => '9.9']))->assertNotFound();
    $this->get(route('legal', ['doc' => 'terms', 'version' => '../../etc']))->assertNotFound();
});

it('serves the locale version and falls back for documents of one language', function (): void {
    $this->withCookie('locale', 'ru')->withHeader('Accept-Language', 'ru')
        ->get(route('legal', 'terms'))
        ->assertInertia(fn ($page) => $page->where('doc.title', fn (string $title) => str_starts_with($title, 'Тестовые условия'))->etc());

    $this->withHeader('Accept-Language', 'en')
        ->get(route('legal', 'consent-ru'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('doc.key', 'consent-ru')->etc());

    $this->withHeader('Accept-Language', 'ru')
        ->get(route('legal', 'dpa'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('doc.title', 'Fixture DPA')->etc());
});

it('renders the sub-processor table from config, omitting disabled entries', function (): void {
    config(['legal.subprocessors' => [
        ['name' => 'Enabled Host', 'purpose' => ['en' => 'Hosting', 'ru' => 'Хостинг'], 'data' => 'All', 'location' => 'EU', 'enabled' => true],
        ['name' => 'Disabled LLM', 'purpose' => 'AI', 'data' => 'Text', 'location' => 'US', 'enabled' => false],
    ]]);

    $html = $this->get(route('legal', 'subprocessors'))->assertOk()->viewData('page')['props']['doc']['html'];

    expect($html)->toContain('<table>')
        ->and($html)->toContain('Enabled Host')
        ->and($html)->toContain('Hosting')
        ->and($html)->not->toContain('Disabled LLM');
});

it('lists every AI provider the ai service reports as configured, with its address', function (): void {
    config(['legal.ai_discovery' => true, 'legal.llm_providers' => []]);
    Cache::forget(AiProviderDisclosure::CACHE_KEY);
    Http::fake(['*/v1/admin/ai/providers*' => Http::response([
        ['id' => 'mock', 'configured' => true],
        ['id' => 'deepseek', 'configured' => true],
        ['id' => 'nvidia', 'configured' => true],
        ['id' => 'openai', 'configured' => false],
    ])]);

    $html = $this->get(route('legal', 'subprocessors'))->assertOk()->viewData('page')['props']['doc']['html'];

    expect($html)->toContain('Hangzhou DeepSeek Artificial Intelligence Co., Ltd.')
        ->and($html)->toContain('NVIDIA Corporation')
        ->and($html)->toContain('2788 San Tomas Expressway')
        ->and($html)->not->toContain('OpenAI OpCo');

    config(['legal.subprocessors' => array_values(array_filter(
        (array) config('legal.subprocessors'),
        fn (array $entry): bool => ($entry['ai_provider'] ?? null) !== 'nvidia',
    ))]);
    $this->artisan('legal:lint')->assertFailed();
});

it('fails lint when an enabled sub-processor is a placeholder while marked reviewed', function (): void {
    config([
        'legal.reviewed' => true,
        'legal.company' => array_map(fn (): string => 'Acme Ltd', (array) config('legal.company')),
        'legal.subprocessors' => [['name' => 'Host', 'address' => '[Registered address]', 'location' => 'EU', 'purpose' => 'x', 'data' => 'y', 'enabled' => true]],
    ]);
    $this->artisan('legal:lint')->assertFailed();

    config(['legal.subprocessors' => [['name' => 'Host', 'address' => '1 Main St', 'location' => 'EU', 'purpose' => 'x', 'data' => 'y', 'enabled' => true]]]);
    $this->artisan('legal:lint')->assertSuccessful();
});

it('exposes the reviewed flag', function (): void {
    config(['legal.reviewed' => true]);

    $this->get(route('legal', 'privacy'))->assertInertia(fn ($page) => $page->where('reviewed', true)->etc());
});

it('answers 404 for keys without a document', function (): void {
    $this->get('/legal/cookies')->assertNotFound();
    $this->get('/legal/Not_A_Key')->assertNotFound();
});

it('fails loudly on unknown placeholders and broken front-matter', function (): void {
    $path = legalSandbox();
    File::put($path.'/en/privacy.md', "---\ntitle: X\nversion: \"1.1\"\neffective_date: 2026-10-02\n---\nHello {{company.ceo_name}}");

    expect(fn () => app(LegalRepository::class)->current('privacy', 'en'))
        ->toThrow(LegalDocumentException::class, 'company.ceo_name');

    $this->artisan('legal:lint')->assertFailed();

    File::put($path.'/en/privacy.md', 'no front matter');
    expect(fn () => app(LegalRepository::class)->current('privacy', 'en'))->toThrow(LegalDocumentException::class, 'front-matter');
});

it('lints the fixture set successfully', function (): void {
    $this->artisan('legal:lint')->assertSuccessful();
});

it('lints the real repository documents when they exist', function (): void {
    config(['legal.path' => resource_path('legal')]);
    app()->forgetInstance(LegalRepository::class);

    if (app(LegalRepository::class)->files() === []) {
        $this->markTestSkipped('resources/legal has no documents yet.');
    }

    $this->artisan('legal:lint')->assertSuccessful();
});

it('never leaks the template-notice marker from the real documents', function (): void {
    config(['legal.path' => resource_path('legal')]);
    app()->forgetInstance(LegalRepository::class);
    $legal = app(LegalRepository::class);

    if ($legal->files() === []) {
        $this->markTestSkipped('resources/legal has no documents yet.');
    }

    foreach ($legal->files() as $file) {
        $document = $legal->parseFile($file);
        expect($document->html)->not->toContain('template-notice')->not->toContain('&lt;!--')
            ->and($document->templateNotice)->toBeTrue();
    }

    $this->get('/legal/terms')->assertOk()->assertDontSee('template-notice', false);
});

it('accepts a template-notice marker placed after the front-matter', function (): void {
    $path = legalSandbox();
    $raw = (string) File::get($path.'/en/privacy.md');
    $parts = explode("---\n", str_replace("\r\n", "\n", ltrim(str_replace(LegalRepository::TEMPLATE_NOTICE, '', $raw))), 3);
    File::put($path.'/en/privacy.md', "---\n".$parts[1]."---\n\n".LegalRepository::TEMPLATE_NOTICE."\n\n".$parts[2]);

    $document = app(LegalRepository::class)->current('privacy', 'en');
    expect($document->templateNotice)->toBeTrue()->and($document->html)->not->toContain('template-notice');
});

it('fails lint when company placeholders remain while marked reviewed', function (): void {
    config(['legal.reviewed' => true, 'legal.company.name' => '[Company name]']);
    $this->artisan('legal:lint')->assertFailed();
});
