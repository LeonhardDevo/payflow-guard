<?php

declare(strict_types=1);

namespace App\Domain\Imports\Mapping;

use App\Domain\Imports\Enums\ImportKind;
use App\Domain\Imports\Enums\ImportPreset;

final class ImportFields
{
    public const array INVOICE_FIELDS = [
        'number' => true,
        'client_name' => true,
        'client_email' => false,
        'issue_date' => true,
        'due_date' => true,
        'currency' => false,
        'total' => true,
        'balance' => false,
        'amount_paid' => false,
        'paid_date' => false,
        'po_number' => false,
        'description' => false,
    ];

    public const array CLIENT_FIELDS = [
        'name' => true,
        'email' => false,
        'contact_name' => false,
        'phone' => false,
        'country' => false,
        'currency' => false,
        'payment_terms_days' => false,
        'tax_id' => false,
        'address' => false,
        'external_ref' => false,
    ];

    public const array ALIASES = [
        'quickbooks' => [
            'number' => ['Num', 'No.', 'Invoice no.'],
            'client_name' => ['Customer', 'Customer full name'],
            'client_email' => ['Email', 'Customer email'],
            'issue_date' => ['Date', 'Invoice date'],
            'due_date' => ['Due date'],
            'total' => ['Amount', 'Total'],
            'balance' => ['Open balance', 'Balance'],
            'currency' => ['Currency'],
            'po_number' => ['P.O. Number'],
            'description' => ['Memo/Description'],
        ],
        'xero' => [
            'number' => ['InvoiceNumber', '*InvoiceNumber'],
            'client_name' => ['ContactName', '*ContactName'],
            'client_email' => ['EmailAddress'],
            'issue_date' => ['InvoiceDate', '*InvoiceDate'],
            'due_date' => ['DueDate', '*DueDate'],
            'total' => ['Total'],
            'balance' => ['AmountDue'],
            'currency' => ['Currency'],
            'po_number' => ['Reference'],
            'description' => ['Description', '*Description'],
            'quantity' => ['Quantity', '*Quantity'],
            'unit_amount' => ['UnitAmount', '*UnitAmount'],
        ],
        'freshbooks' => [
            'number' => ['Invoice #', 'Invoice Number'],
            'client_name' => ['Client Name', 'Organization'],
            'client_email' => ['Email'],
            'issue_date' => ['Date Issued', 'Issue Date'],
            'due_date' => ['Due Date'],
            'total' => ['Amount', 'Total'],
            'balance' => ['Outstanding', 'Amount Outstanding'],
            'currency' => ['Currency'],
            'po_number' => ['PO Number'],
            'description' => ['Description'],
        ],
        'generic' => [
            'number' => ['Number', 'Invoice number', 'Invoice', 'Номер', 'Номер счёта', 'Номер счета'],
            'client_name' => ['Client', 'Client name', 'Customer', 'Клиент', 'Контрагент'],
            'client_email' => ['Email', 'Client email', 'Почта'],
            'issue_date' => ['Issue date', 'Date', 'Дата', 'Дата счёта', 'Дата счета'],
            'due_date' => ['Due date', 'Срок оплаты', 'Оплатить до'],
            'total' => ['Total', 'Amount', 'Сумма', 'Итого'],
            'balance' => ['Balance', 'Amount due', 'Остаток', 'К оплате'],
            'amount_paid' => ['Amount paid', 'Paid', 'Оплачено'],
            'paid_date' => ['Paid date', 'Payment date', 'Дата оплаты'],
            'currency' => ['Currency', 'Валюта'],
            'po_number' => ['PO number', 'PO', 'Номер заказа'],
            'description' => ['Description', 'Описание'],
            'name' => ['Name', 'Client', 'Company', 'Название', 'Клиент'],
            'email' => ['Email', 'Почта'],
            'contact_name' => ['Contact', 'Contact name', 'Контактное лицо'],
            'phone' => ['Phone', 'Телефон'],
            'country' => ['Country', 'Страна'],
            'payment_terms_days' => ['Payment terms', 'Terms', 'Отсрочка'],
            'tax_id' => ['Tax ID', 'VAT', 'ИНН'],
            'address' => ['Address', 'Адрес'],
            'external_ref' => ['External ID', 'ID', 'Код'],
        ],
    ];

    public const array DEFAULT_DATE_FORMATS = [
        'quickbooks' => 'm/d/Y',
        'xero' => 'd/m/Y',
        'freshbooks' => 'Y-m-d',
    ];

    /** @return array<string, bool> */
    public static function fields(ImportKind $kind): array
    {
        return $kind === ImportKind::Invoices ? self::INVOICE_FIELDS : self::CLIENT_FIELDS;
    }

    /** @return list<array{key: string, required: bool}> */
    public static function describe(ImportKind $kind): array
    {
        $result = [];

        foreach (self::fields($kind) as $key => $required) {
            $result[] = ['key' => $key, 'required' => $required];
        }

        return $result;
    }

    public static function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header, " \t\n\r\0\x0B\u{FEFF}"));
        $header = str_replace('ё', 'е', $header);

        return (string) preg_replace('/[\s_*]+/u', '', $header);
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    public static function suggest(ImportKind $kind, ImportPreset $preset, array $headers): array
    {
        $normalized = [];

        foreach ($headers as $header) {
            $normalized[self::normalizeHeader($header)] ??= $header;
        }

        $fields = array_keys(self::fields($kind));

        if ($kind === ImportKind::Invoices && $preset === ImportPreset::Xero) {
            $fields = [...$fields, 'quantity', 'unit_amount'];
        }

        $mapping = [];

        foreach ($fields as $field) {
            $mapping[$field] = null;
            $candidates = [...(self::ALIASES[$preset->value][$field] ?? []), ...(self::ALIASES['generic'][$field] ?? []), $field];

            foreach ($candidates as $alias) {
                $key = self::normalizeHeader($alias);

                if (isset($normalized[$key])) {
                    $mapping[$field] = $normalized[$key];
                    break;
                }
            }
        }

        return $mapping;
    }
}
