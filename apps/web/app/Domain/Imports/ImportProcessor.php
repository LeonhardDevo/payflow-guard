<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Audit\Auditor;
use App\Billing\PlanLimitExceeded;
use App\Domain\Analytics\Risk\RiskRecomputer;
use App\Domain\Imports\Csv\CsvFile;
use App\Domain\Imports\Enums\ImportKind;
use App\Domain\Imports\Enums\ImportPreset;
use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Mapping\DateFormats;
use App\Domain\Imports\Models\Import;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Clients\ClientService;
use App\Domain\Receivables\Enums\EventActorType;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Enums\InvoicePauseSource;
use App\Domain\Receivables\Enums\InvoiceSource;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Invoices\InvoiceDrafts;
use App\Domain\Receivables\Invoices\InvoiceNumbering;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Money\MoneyParser;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class ImportProcessor
{
    public const int MAX_ERRORS = 500;

    public function __construct(
        private InvoiceDrafts $drafts,
        private ClientService $clients,
        private InvoiceTimeline $timeline,
        private RiskRecomputer $risk,
        private Auditor $auditor,
        private Clock $clock,
    ) {}

    /** @return array{processed: int, imported: int, skipped: int, failed: int, done: bool, next_offset: int} */
    public function processBatch(Import $import, int $offset, int $limit): array
    {
        if (in_array($import->status, [ImportStatus::Completed, ImportStatus::CompletedWithErrors, ImportStatus::Failed], true)) {
            return ['processed' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'done' => true, 'next_offset' => $import->rows_processed];
        }

        if (! in_array($import->status, [ImportStatus::Queued, ImportStatus::Processing], true)) {
            throw new PayflowException('import_not_ready', 409);
        }

        $workspace = Workspace::query()->findOrFail($import->workspace_id);
        $actor = User::query()->find($import->created_by);
        $offset = max($offset, $import->rows_processed);

        try {
            $records = $this->records($import);
        } catch (PayflowException) {
            $import->forceFill(['status' => ImportStatus::Failed, 'finished_at' => $this->clock->now()])->save();

            return ['processed' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'done' => true, 'next_offset' => $offset];
        }

        $import->forceFill(['status' => ImportStatus::Processing, 'rows_total' => count($records)])->save();
        $batch = array_slice($records, $offset, max(1, min($limit, 500)), true);
        $counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        $errors = $import->errors;

        foreach ($batch as $index => $record) {
            $row = $record['row'];

            try {
                $outcome = DB::transaction(fn (): array => $import->kind === ImportKind::Invoices
                    ? $this->invoiceRecord($workspace, $actor, $import, $record['values'])
                    : $this->clientRecord($workspace, $actor, $record['values']));
            } catch (PlanLimitExceeded) {
                $outcome = ['result' => 'failed', 'field' => null, 'code' => 'plan_limit'];
            } catch (PayflowException $exception) {
                $outcome = ['result' => 'failed', 'field' => null, 'code' => $exception->errorCode];
            }

            $counts[$outcome['result']]++;

            if ($outcome['result'] !== 'imported' && count($errors) < self::MAX_ERRORS) {
                $errors[] = ['row' => $row, 'field' => $outcome['field'] ?? null, 'code' => $outcome['code'] ?? 'invalid'];
            }

            unset($index);
        }

        $processed = count($batch);
        $nextOffset = $offset + $processed;
        $done = $nextOffset >= count($records);

        $import->forceFill([
            'rows_processed' => $nextOffset,
            'rows_imported' => $import->rows_imported + $counts['imported'],
            'rows_skipped' => $import->rows_skipped + $counts['skipped'],
            'rows_failed' => $import->rows_failed + $counts['failed'],
            'errors' => $errors,
        ])->save();

        if ($done) {
            $import->forceFill([
                'status' => $import->rows_skipped + $import->rows_failed > 0 ? ImportStatus::CompletedWithErrors : ImportStatus::Completed,
                'finished_at' => $this->clock->now(),
            ])->save();
            $this->risk->workspace($workspace, $this->clock->now());
            $this->auditor->record(
                'payflow.import.completed',
                $import,
                metadata: ['imported' => $import->rows_imported, 'skipped' => $import->rows_skipped, 'failed' => $import->rows_failed],
                actor: $actor,
                workspace: $workspace,
                subjectLabel: $import->original_name,
                system: true,
            );
        }

        return ['processed' => $processed, ...$counts, 'done' => $done, 'next_offset' => $nextOffset];
    }

    /** @return list<array{row: int, values: array<string, string>}> */
    public function records(Import $import): array
    {
        $parsed = ImportService::read($import);
        $mapping = $import->mapping ?? [];
        $records = [];

        foreach ($parsed['rows'] as $position => $row) {
            $records[] = ['row' => $position + 2, 'values' => CsvFile::map($parsed['headers'], $row, $mapping)];
        }

        if ($import->kind !== ImportKind::Invoices || $import->preset !== ImportPreset::Xero) {
            return $records;
        }

        $grouped = [];

        foreach ($records as $record) {
            $number = trim($record['values']['number'] ?? '');
            $key = $number === '' ? 'row-'.$record['row'] : mb_strtolower($number);

            if (! isset($grouped[$key])) {
                $grouped[$key] = $record;
                $grouped[$key]['values']['line_total'] = (string) $this->lineTotal($record['values']);

                continue;
            }

            $grouped[$key]['values']['line_total'] = (string) ((int) $grouped[$key]['values']['line_total'] + $this->lineTotal($record['values']));
        }

        return array_values($grouped);
    }

    /** @param  array<string, string>  $values */
    private function lineTotal(array $values): int
    {
        $quantity = trim($values['quantity'] ?? '');
        $unit = MoneyParser::lenient($values['unit_amount'] ?? '');

        if ($unit === null) {
            return 0;
        }

        $milli = is_numeric(str_replace(',', '.', $quantity)) ? (int) round((float) str_replace(',', '.', $quantity) * 1000) : 1000;

        return intdiv($milli * $unit + 500, 1000);
    }

    /**
     * @param  array<string, string>  $values
     * @return array{result: 'imported'|'skipped'|'failed', field?: string|null, code?: string}
     */
    private function invoiceRecord(Workspace $workspace, ?User $actor, Import $import, array $values): array
    {
        $options = $import->options;
        $format = is_string($options['date_format'] ?? null) ? $options['date_format'] : 'Y-m-d';
        $number = trim($values['number'] ?? '');
        $clientName = trim($values['client_name'] ?? '');

        if ($number === '') {
            return ['result' => 'failed', 'field' => 'number', 'code' => 'required'];
        }

        if ($clientName === '') {
            return ['result' => 'failed', 'field' => 'client_name', 'code' => 'required'];
        }

        if (InvoiceNumbering::isTaken($workspace->id, $number)) {
            return ['result' => 'skipped', 'field' => 'number', 'code' => 'duplicate_number'];
        }

        $issueDate = DateFormats::parse($values['issue_date'] ?? '', $format);
        $dueDate = DateFormats::parse($values['due_date'] ?? '', $format);

        if ($issueDate === null) {
            return ['result' => 'failed', 'field' => 'issue_date', 'code' => 'invalid_date'];
        }

        if ($dueDate === null || $dueDate < $issueDate) {
            return ['result' => 'failed', 'field' => 'due_date', 'code' => 'invalid_date'];
        }

        $total = isset($values['total']) && trim($values['total']) !== '' ? MoneyParser::lenient($values['total']) : (isset($values['line_total']) ? (int) $values['line_total'] : null);

        if ($total === null || $total <= 0) {
            return ['result' => 'failed', 'field' => 'total', 'code' => 'invalid_amount'];
        }

        $currency = strtoupper(trim($values['currency'] ?? '') ?: (string) ($options['default_currency'] ?? $workspace->currency));

        if (! array_key_exists($currency, (array) config('payflow.currencies'))) {
            return ['result' => 'failed', 'field' => 'currency', 'code' => 'invalid_currency'];
        }

        $paid = isset($values['amount_paid']) && trim($values['amount_paid']) !== '' ? MoneyParser::lenient($values['amount_paid']) : null;
        $balance = isset($values['balance']) && trim($values['balance']) !== '' ? MoneyParser::lenient($values['balance']) : null;
        $balance ??= $paid !== null ? $total - $paid : $total;
        $balance = min($balance, $total);

        $client = $this->resolveClient($workspace, $actor, $clientName, trim($values['client_email'] ?? ''), $currency, (bool) ($options['create_missing_clients'] ?? true));

        if ($client === null) {
            return ['result' => 'failed', 'field' => 'client_name', 'code' => 'client_not_found'];
        }

        $description = trim($values['description'] ?? '');
        $invoice = $this->drafts->create($workspace, $actor, [
            'client_id' => $client->id,
            'number' => mb_substr($number, 0, 40),
            'currency' => $currency,
            'locale' => $client->locale,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'po_number' => ($values['po_number'] ?? '') !== '' ? mb_substr((string) $values['po_number'], 0, 64) : null,
            'items' => [[
                'description' => mb_substr($description !== '' ? $description : (string) trans('payflow.imports.invoice_description', ['number' => $number], $client->locale), 0, 500),
                'quantity' => '1',
                'unit_price_minor' => $total,
                'tax_rate_bp' => 0,
            ]],
        ], InvoiceSource::Import);

        $amountPaid = $total - max(0, $balance);
        $status = match (true) {
            $balance <= 0 => InvoiceStatus::Paid,
            $amountPaid > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Open,
        };

        $timezone = $workspace->timezone;
        $paidDate = isset($values['paid_date']) ? DateFormats::parse($values['paid_date'], $format) : null;
        $estimated = $status === InvoiceStatus::Paid && $paidDate === null;
        $paidAt = $status === InvoiceStatus::Paid ? BusinessDate::toUtc($paidDate ?? $dueDate, '12:00', $timezone) : null;

        if ($amountPaid > 0) {
            $payment = new Payment;
            $payment->forceFill([
                'workspace_id' => $workspace->id,
                'invoice_id' => $invoice->id,
                'client_id' => $client->id,
                'amount_minor' => $amountPaid,
                'currency' => $currency,
                'method' => PaymentMethod::Other,
                'status' => PaymentStatus::Succeeded,
                'provider' => PaymentProvider::Manual,
                'paid_at' => $paidAt ?? BusinessDate::toUtc($issueDate, '12:00', $timezone),
                'note' => (string) trans('payflow.payments.imported_note', [], $client->locale),
                'recorded_by' => $actor?->id,
            ])->save();
        }

        $invoice->forceFill([
            'status' => $status,
            'amount_paid_minor' => $amountPaid,
            'paid_at' => $paidAt,
            'paid_at_estimated' => $estimated,
            'sent_at' => ($options['mark_as_sent'] ?? true) ? BusinessDate::toUtc($issueDate, '09:00', $timezone) : null,
            'reminders_paused' => true,
            'reminders_pause_source' => InvoicePauseSource::ImportReview,
            'import_id' => $import->id,
        ])->save();

        $this->timeline->record($invoice, InvoiceEventType::Imported, ['import_id' => $import->id, 'paid_at_estimated' => $estimated], $actor, $actor === null ? EventActorType::System : null);

        return ['result' => 'imported'];
    }

    /**
     * @param  array<string, string>  $values
     * @return array{result: 'imported'|'skipped'|'failed', field?: string|null, code?: string}
     */
    private function clientRecord(Workspace $workspace, ?User $actor, array $values): array
    {
        $name = trim($values['name'] ?? '');

        if ($name === '') {
            return ['result' => 'failed', 'field' => 'name', 'code' => 'required'];
        }

        $externalRef = trim($values['external_ref'] ?? '');
        $exists = Client::query()->forWorkspace($workspace)
            ->where(fn ($query) => $query->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->when($externalRef !== '', fn ($inner) => $inner->orWhere('external_ref', $externalRef)))
            ->exists();

        if ($exists) {
            return ['result' => 'skipped', 'field' => 'name', 'code' => 'duplicate_client'];
        }

        $email = trim($values['email'] ?? '');

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['result' => 'failed', 'field' => 'email', 'code' => 'invalid_email'];
        }

        $currency = strtoupper(trim($values['currency'] ?? '')) ?: strtoupper($workspace->currency);

        if (! array_key_exists($currency, (array) config('payflow.currencies'))) {
            return ['result' => 'failed', 'field' => 'currency', 'code' => 'invalid_currency'];
        }

        $country = strtoupper(trim($values['country'] ?? ''));
        $terms = trim($values['payment_terms_days'] ?? '');

        $this->clients->create($workspace, $actor, [
            'name' => mb_substr($name, 0, 200),
            'email' => $email !== '' ? $email : null,
            'contact_name' => ($values['contact_name'] ?? '') !== '' ? mb_substr((string) $values['contact_name'], 0, 120) : null,
            'phone' => ($values['phone'] ?? '') !== '' ? mb_substr((string) $values['phone'], 0, 40) : null,
            'country' => preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
            'currency' => $currency,
            'payment_terms_days' => ctype_digit($terms) && (int) $terms <= 180 ? (int) $terms : null,
            'tax_id' => ($values['tax_id'] ?? '') !== '' ? mb_substr((string) $values['tax_id'], 0, 64) : null,
            'address' => ($values['address'] ?? '') !== '' ? mb_substr((string) $values['address'], 0, 1000) : null,
            'external_ref' => $externalRef !== '' ? mb_substr($externalRef, 0, 100) : null,
        ]);

        return ['result' => 'imported'];
    }

    private function resolveClient(Workspace $workspace, ?User $actor, string $name, string $email, string $currency, bool $create): ?Client
    {
        $client = Client::query()->forWorkspace($workspace)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($client !== null || ! $create) {
            return $client;
        }

        return $this->clients->create($workspace, $actor, [
            'name' => mb_substr($name, 0, 200),
            'email' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            'currency' => $currency,
        ]);
    }
}
