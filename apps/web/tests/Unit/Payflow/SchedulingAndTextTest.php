<?php

declare(strict_types=1);

use App\Domain\Collections\Drafts\ReminderDraftAssistant;
use App\Domain\Collections\Planning\SendSlot;
use App\Domain\Collections\Templates\TemplateRenderer;
use App\Domain\Imports\Csv\CsvFile;
use App\Domain\Imports\Enums\ImportKind;
use App\Domain\Imports\Enums\ImportPreset;
use App\Domain\Imports\ImportService;
use App\Domain\Imports\Mapping\DateFormats;
use App\Domain\Imports\Mapping\ImportFields;
use App\Domain\Privacy\PiiRedactor;
use Carbon\CarbonImmutable;
use Tests\Feature\Payflow\Support\Payflow;

it('computes send slots for the planner vectors', function (): void {
    foreach (Payflow::fixture('planner-vectors.json') as $vector) {
        $slot = SendSlot::next(
            $vector['date'],
            '09:00',
            '17:00',
            $vector['business_days_only'],
            $vector['timezone'],
            CarbonImmutable::parse($vector['now']),
            $vector['spread'],
        );

        expect($slot->utc()->format('Y-m-d\TH:i:s\Z'))->toBe($vector['expected'], $vector['case']);
    }
});

it('derives a stable spread from the public token', function (): void {
    expect(SendSlot::spread('abc'))->toBe(SendSlot::spread('abc'))
        ->and(SendSlot::spread('abc'))->toBeGreaterThanOrEqual(0)->toBeLessThan(30)
        ->and(SendSlot::spread('abc'))->toBe(crc32('abc') % 30);
});

it('buckets first contacts by hour respecting the throttle', function (): void {
    $slots = array_fill(0, 120, CarbonImmutable::parse('2026-10-23T06:10:00Z'));
    $buckets = ImportService::throttleBuckets($slots, 50, 'Europe/Riga');

    expect($buckets)->toBe([
        ['hour_start' => '2026-10-23T06:00:00Z', 'count' => 50],
        ['hour_start' => '2026-10-23T07:00:00Z', 'count' => 50],
        ['hour_start' => '2026-10-23T08:00:00Z', 'count' => 20],
    ]);
});

it('renders templates with whitelisted variables and escapes user data', function (): void {
    $rendered = TemplateRenderer::render(
        'Invoice {{invoice_number}} {{due_phrase}}',
        "Hi {{greeting_name}},\n\n[Pay]({{invoice_link}})\n\n{{late_fee_notice}}",
        ['invoice_number' => 'INV-1', 'due_phrase' => 'is due today', 'greeting_name' => '*Bob* <script>', 'invoice_link' => 'https://app.test/i/x', 'late_fee_notice' => ''],
    );

    expect($rendered['subject'])->toBe('Invoice INV-1 is due today')
        ->and($rendered['html'])->toContain('href="https://app.test/i/x"')
        ->and($rendered['html'])->not->toContain('<script>')
        ->and($rendered['html'])->not->toContain('<em>Bob</em>')
        ->and($rendered['text'])->toContain('Pay: https://app.test/i/x')
        ->and(TemplateRenderer::unknownVariables('{{invoice_number}} {{iban}}', '{{ secret }}'))->toBe(['iban', 'secret']);
});

it('ships default templates in both locales without unknown variables', function (): void {
    foreach (['en', 'ru'] as $locale) {
        foreach (['invoice_issued', 'reminder_friendly', 'reminder_neutral', 'reminder_firm', 'reminder_final', 'payment_receipt'] as $key) {
            $subject = (string) trans("payflow.templates.{$key}.subject", [], $locale);
            $body = (string) trans("payflow.templates.{$key}.body", [], $locale);

            expect(TemplateRenderer::unknownVariables($subject, $body))->toBe([], "{$locale} {$key}")
                ->and($body)->not->toBe("payflow.templates.{$key}.body");
        }
    }
});

it('detects date formats and parses import dates strictly', function (): void {
    expect(DateFormats::detect(['2026-01-31', '2026-02-01']))->toBe('Y-m-d')
        ->and(DateFormats::detect(['31.01.2026']))->toBe('d.m.Y')
        ->and(DateFormats::detect(['13/01/2026', '01/02/2026']))->toBe('d/m/Y')
        ->and(DateFormats::detect(['01/13/2026']))->toBe('m/d/Y')
        ->and(DateFormats::parse('02/29/2026', 'm/d/Y'))->toBeNull()
        ->and(DateFormats::parse('1/5/2026', 'm/d/Y'))->toBe('2026-01-05')
        ->and(DateFormats::parse('2026-03-01T00:00:00', 'Y-m-d'))->toBe('2026-03-01');
});

it('suggests column mappings for the accounting presets', function (): void {
    $quickbooks = ImportFields::suggest(ImportKind::Invoices, ImportPreset::Quickbooks, ['Date', 'Num', 'Customer', 'Due date', 'Amount', 'Open balance', 'Memo/Description']);
    $xero = ImportFields::suggest(ImportKind::Invoices, ImportPreset::Xero, ['*ContactName', '*InvoiceNumber', '*InvoiceDate', '*DueDate', 'Quantity', 'UnitAmount', 'Currency']);
    $russian = ImportFields::suggest(ImportKind::Invoices, ImportPreset::Generic, ['Номер счёта', 'Контрагент', 'Дата', 'Оплатить до', 'Итого', 'Валюта']);

    expect($quickbooks)->toMatchArray(['number' => 'Num', 'client_name' => 'Customer', 'issue_date' => 'Date', 'due_date' => 'Due date', 'total' => 'Amount', 'balance' => 'Open balance', 'description' => 'Memo/Description'])
        ->and($xero)->toMatchArray(['number' => '*InvoiceNumber', 'client_name' => '*ContactName', 'quantity' => 'Quantity', 'unit_amount' => 'UnitAmount', 'currency' => 'Currency'])
        ->and($russian)->toMatchArray(['number' => 'Номер счёта', 'client_name' => 'Контрагент', 'issue_date' => 'Дата', 'due_date' => 'Оплатить до', 'total' => 'Итого', 'currency' => 'Валюта']);
});

it('sniffs CSV delimiters and maps rows', function (): void {
    $semicolon = "name;email\nAcme;ap@acme.example\n";
    $parsed = CsvFile::parse($semicolon, CsvFile::sniffDelimiter($semicolon));

    expect(CsvFile::sniffDelimiter("a,b,c\n1,2,3\n"))->toBe(',')
        ->and(CsvFile::sniffDelimiter("a\tb\n1\t2\n"))->toBe("\t")
        ->and($parsed['headers'])->toBe(['name', 'email'])
        ->and(CsvFile::map($parsed['headers'], $parsed['rows'][0], ['name' => 'name', 'email' => 'email', 'phone' => null]))->toBe(['name' => 'Acme', 'email' => 'ap@acme.example']);
});

it('redacts contact data and account numbers but keeps money and invoice numbers', function (): void {
    $text = 'Contact ap@helios-media.example or +1 555 010 0199, IBAN LT58 3250 0677 4120 0519, invoice NS-2026-0118 for $12,400.00 and 1 234 567,89 €';
    $redacted = PiiRedactor::text($text);

    expect($redacted)->toContain('•••@helios-media.example')
        ->and($redacted)->not->toContain('ap@helios')
        ->and($redacted)->not->toContain('3250 0677')
        ->and($redacted)->toContain('•••• 0519')
        ->and($redacted)->toContain('•••• 0199')
        ->and($redacted)->toContain('NS-2026-0118')
        ->and($redacted)->toContain('$12,400.00');
});

it('accepts AI drafts only when every fact and placeholder survives', function (): void {
    $facts = ['invoice_number' => 'NS-2026-0118', 'amount_due' => '$12,400.00', 'invoice_link' => 'https://app.test/i/abc', 'due_date' => 'August 31, 2026', 'days_overdue' => '52'];
    $base = ['subject' => 'Final notice: invoice NS-2026-0118', 'body_markdown' => 'Dear [[GREETING]], invoice NS-2026-0118 for $12,400.00 is 52 days overdue. https://app.test/i/abc [[SENDER]]'];
    $good = 'Dear [[GREETING]], please settle NS-2026-0118 ($12,400.00) now: https://app.test/i/abc. Thank you, [[SENDER]]';

    expect(ReminderDraftAssistant::passesGuard('Invoice NS-2026-0118', $good, $facts, $base))->toBeTrue()
        ->and(ReminderDraftAssistant::passesGuard('Invoice NS-2026-0118', str_replace('[[SENDER]]', 'Anna', $good), $facts, $base))->toBeFalse()
        ->and(ReminderDraftAssistant::passesGuard('Invoice NS-2026-0118', $good.' Pay within 3 days.', $facts, $base))->toBeFalse()
        ->and(ReminderDraftAssistant::passesGuard('Invoice NS-2026-0118', $good." \u{1F600}", $facts, $base))->toBeFalse()
        ->and(ReminderDraftAssistant::passesGuard('Invoice', str_replace('https://app.test/i/abc', '', $good), $facts, $base))->toBeFalse();
});

it('accepts every valid email v2 sample and rejects every invalid one', function (): void {
    $samples = json_decode((string) file_get_contents(base_path('../../contracts/outbox/email-send.v2.samples.json')), true, 512, JSON_THROW_ON_ERROR);

    foreach ($samples['valid'] as $sample) {
        expect(Payflow::emailPayloadErrors($sample['payload']))->toBeNull($sample['name']);
    }

    foreach ($samples['invalid'] as $sample) {
        expect(Payflow::emailPayloadErrors($sample['payload']))->not->toBeNull($sample['name']);
    }
});

it('keeps translation files, templates and seed data free of emoji', function (): void {
    $pattern = '/(?![\x{00A9}\x{00AE}\x{2122}])[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{1F1E6}-\x{1F1FF}]/u';
    $files = [
        ...glob(base_path('lang/*/payflow.php')) ?: [],
        ...glob(base_path('lang/*/billing.php')) ?: [],
        ...glob(base_path('database/seeders/Payflow/*')) ?: [],
        ...glob(base_path('resources/views/emails/payflow/*')) ?: [],
        ...glob(base_path('resources/views/pdf/*')) ?: [],
        ...glob(base_path('tests/Fixtures/payflow/*')) ?: [],
    ];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(preg_match($pattern, (string) file_get_contents($file)))->toBe(0, $file);
    }
});
