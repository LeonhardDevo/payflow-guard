<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Domain\Collections\Dispatch\ReminderDispatcher;
use App\Domain\Collections\Enums\StepTone;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Collections\Planning\ReminderEligibility;
use App\Domain\Collections\Planning\SendSlot;
use App\Domain\Collections\Tone\ToneContext;
use App\Domain\Imports\Csv\CsvFile;
use App\Domain\Imports\Enums\ImportActivationStatus;
use App\Domain\Imports\Enums\ImportKind;
use App\Domain\Imports\Enums\ImportPreset;
use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Mapping\DateFormats;
use App\Domain\Imports\Mapping\ImportFields;
use App\Domain\Imports\Models\Import;
use App\Domain\Receivables\Clients\ClientTerms;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Enums\InvoicePauseSource;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class ImportService
{
    public const string TOPIC = 'payflow.import.requested';

    public function __construct(
        private PlanLimits $limits,
        private Outbox $outbox,
        private InvoiceTimeline $timeline,
        private ReminderDispatcher $dispatcher,
        private Auditor $auditor,
        private Clock $clock,
    ) {}

    public static function disk(): string
    {
        return (string) config('payflow.imports.disk', 'local');
    }

    public function upload(Workspace $workspace, User $actor, UploadedFile $file, ImportKind $kind, ImportPreset $preset): Import
    {
        if (! $this->limits->hasFeature($workspace, 'csv_import')) {
            throw new PayflowException('entitlement_required', 403);
        }

        $this->limits->ensure($workspace, 'imports_per_month');
        $contents = (string) file_get_contents($file->getRealPath() ?: '');
        $delimiter = CsvFile::sniffDelimiter($contents);
        $parsed = CsvFile::parse($contents, $delimiter);
        $path = 'imports/'.$workspace->id.'/'.Str::uuid()->toString().'.csv';
        Storage::disk(self::disk())->put($path, $contents);

        $import = new Import;
        $import->forceFill([
            'workspace_id' => $workspace->id,
            'kind' => $kind,
            'preset' => $preset,
            'status' => ImportStatus::Mapping,
            'file_path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'size_bytes' => (int) $file->getSize(),
            'delimiter' => $delimiter,
            'headers' => $parsed['headers'],
            'mapping' => ImportFields::suggest($kind, $preset, $parsed['headers']),
            'options' => [
                'default_currency' => strtoupper($workspace->currency),
                'date_format' => $this->detectFormat($kind, $preset, $parsed['headers'], $parsed['rows']),
                'create_missing_clients' => true,
                'mark_as_sent' => true,
            ],
            'rows_total' => count($parsed['rows']),
            'created_by' => $actor->id,
        ])->save();

        return $import;
    }

    /** @return array{headers: list<string>, rows: list<list<string>>} */
    public static function read(Import $import): array
    {
        if ($import->file_path === null || ! Storage::disk(self::disk())->exists($import->file_path)) {
            throw new PayflowException('import_not_ready', 409);
        }

        return CsvFile::parse((string) Storage::disk(self::disk())->get($import->file_path), $import->delimiter);
    }

    /**
     * @param  array<string, string|null>  $mapping
     * @param  array{default_currency: string, date_format: string, create_missing_clients: bool, mark_as_sent: bool}  $options
     */
    public function start(Import $import, User $actor, array $mapping, array $options): Import
    {
        if (! in_array($import->status, [ImportStatus::Uploaded, ImportStatus::Mapping], true)) {
            throw new PayflowException('import_not_ready', 409);
        }

        foreach (ImportFields::fields($import->kind) as $field => $required) {
            if ($required && (($mapping[$field] ?? null) === null) && ! ($field === 'total' && isset($mapping['unit_amount']))) {
                throw new PayflowException('import_mapping_incomplete', 422, ['field' => $field]);
            }
        }

        return DB::transaction(function () use ($import, $actor, $mapping, $options): Import {
            $import->forceFill([
                'mapping' => $mapping,
                'options' => $options,
                'status' => ImportStatus::Queued,
                'started_at' => $this->clock->now(),
            ])->save();

            $this->outbox->publish(self::TOPIC, ['import_id' => $import->id, 'workspace_id' => $import->workspace_id], $import->workspace_id);
            $this->auditor->record('payflow.import.started', $import, metadata: ['kind' => $import->kind->value, 'preset' => $import->preset->value, 'rows' => $import->rows_total], actor: $actor, workspace: $import->workspace_id, subjectLabel: $import->original_name);

            return $import;
        });
    }

    /** @return Collection<int, Invoice> */
    public static function pendingInvoices(Import $import): Collection
    {
        return Invoice::query()->forWorkspace($import->workspace_id)
            ->with('client')
            ->where('import_id', $import->id)
            ->whereIn('status', Invoice::OPEN_STATUSES)
            ->where('reminders_paused', true)
            ->where('reminders_pause_source', InvoicePauseSource::ImportReview->value)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    public function activationPreview(Import $import): array
    {
        $workspace = Workspace::query()->findOrFail($import->workspace_id);
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $now = $this->clock->now();
        $today = WorkspaceClock::todayIn($workspace->timezone, $now)->toDateString();
        $invoices = self::pendingInvoices($import);
        $engine = array_fill_keys(array_map(fn (Tone $tone): string => $tone->value, Tone::cases()), 0);
        $capped = $engine;
        $overdue = 0;
        $beyond = 0;
        $hours = [];
        $samples = [];

        foreach ($invoices as $invoice) {
            $days = BusinessDate::diff($invoice->dueDate(), $today);
            $overdue += $days >= 1 ? 1 : 0;
            $policy = ClientTerms::policy($invoice->client);

            if ($policy !== null && $days >= $policy->escalate_after_days) {
                $beyond++;

                continue;
            }

            if (ReminderEligibility::clientReason($invoice->client, $today) !== null || $invoice->client->email === null) {
                continue;
            }

            $suggestion = ToneContext::suggest($invoice, $today, StepTone::Auto);
            $cappedTone = $suggestion->tone->isAbove(Tone::Neutral) ? Tone::Neutral : $suggestion->tone;
            $engine[$suggestion->tone->value]++;
            $capped[$cappedTone->value]++;

            $slot = SendSlot::next(
                BusinessDate::max($invoice->dueDate(), $today),
                $settings->windowStart(),
                $settings->windowEnd(),
                $settings->business_days_only,
                ClientTerms::sendTimezone($invoice->client, $workspace),
                $now,
                SendSlot::spread($invoice->public_token),
            );
            $hours[] = $slot;

            $key = $cappedTone->value.'|'.$invoice->locale;

            if (! isset($samples[$key])) {
                $email = $this->dispatcher->render($invoice, $cappedTone, CarbonImmutable::parse($today), null);
                $samples[$key] = ['tone' => $cappedTone->value, 'locale' => $invoice->locale, 'subject' => $email->subject, 'body_html' => $email->html];
            }
        }

        return [
            'invoices' => $invoices->count(),
            'overdue' => $overdue,
            'beyond_escalation' => $beyond,
            'tones_engine' => $engine,
            'tones_capped' => $capped,
            'first_slots' => self::throttleBuckets($hours, $settings->first_contact_max_per_hour, $workspace->timezone),
            'samples' => array_values($samples),
        ];
    }

    /**
     * @param  list<CarbonImmutable>  $slots
     * @return list<array{hour_start: string, count: int}>
     */
    public static function throttleBuckets(array $slots, int $perHour, string $timezone): array
    {
        usort($slots, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());
        $zone = WorkspaceClock::zone($timezone);
        $buckets = [];

        foreach ($slots as $slot) {
            $local = $slot->setTimezone($zone);
            $hour = $local->setTime((int) $local->format('H'), 0)->utc();

            while (($buckets[$hour->getTimestamp()] ?? 0) >= $perHour) {
                $hour = $hour->addHour();
            }

            $buckets[$hour->getTimestamp()] = ($buckets[$hour->getTimestamp()] ?? 0) + 1;
        }

        ksort($buckets);
        $result = [];

        foreach ($buckets as $timestamp => $count) {
            $result[] = ['hour_start' => CarbonImmutable::createFromTimestampUTC($timestamp)->format('Y-m-d\TH:i:s\Z'), 'count' => $count];
        }

        return $result;
    }

    /** @param  list<int>  $excludeIds */
    public function activate(Import $import, User $actor, bool $allowEngineTone, array $excludeIds): int
    {
        $this->assertActivatable($import);
        $now = $this->clock->now();

        return DB::transaction(function () use ($import, $actor, $allowEngineTone, $excludeIds, $now): int {
            $count = 0;

            foreach (self::pendingInvoices($import) as $invoice) {
                if (in_array($invoice->id, $excludeIds, true)) {
                    continue;
                }

                $invoice->forceFill([
                    'reminders_paused' => false,
                    'reminders_paused_until' => null,
                    'reminders_pause_source' => null,
                    ...($allowEngineTone ? ['first_contact_confirmed_at' => $now, 'first_contact_confirmed_by' => $actor->id] : []),
                ])->save();
                $this->timeline->record($invoice, InvoiceEventType::RemindersActivated, ['import_id' => $import->id, 'engine_tone' => $allowEngineTone], $actor);
                $count++;
            }

            $import->forceFill([
                'activation_status' => ImportActivationStatus::Activated,
                'activated_at' => $now,
                'activated_by' => $actor->id,
            ])->save();

            $this->auditor->record('payflow.import.activated', $import, metadata: ['invoices' => $count, 'allow_engine_tone' => $allowEngineTone, 'excluded' => count($excludeIds)], actor: $actor, workspace: $import->workspace_id, subjectLabel: $import->original_name);

            return $count;
        });
    }

    public function dismiss(Import $import, User $actor): void
    {
        $this->assertActivatable($import);
        $import->forceFill(['activation_status' => ImportActivationStatus::Dismissed])->save();
        $this->auditor->record('payflow.import.dismissed', $import, actor: $actor, workspace: $import->workspace_id, subjectLabel: $import->original_name);
    }

    private function assertActivatable(Import $import): void
    {
        if ($import->kind !== ImportKind::Invoices
            || ! in_array($import->status, [ImportStatus::Completed, ImportStatus::CompletedWithErrors], true)
            || $import->activation_status !== ImportActivationStatus::Pending) {
            throw new PayflowException('import_not_ready', 409);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function detectFormat(ImportKind $kind, ImportPreset $preset, array $headers, array $rows): string
    {
        if (isset(ImportFields::DEFAULT_DATE_FORMATS[$preset->value])) {
            return ImportFields::DEFAULT_DATE_FORMATS[$preset->value];
        }

        if ($kind !== ImportKind::Invoices) {
            return 'Y-m-d';
        }

        $mapping = ImportFields::suggest($kind, $preset, $headers);
        $samples = [];

        foreach (array_slice($rows, 0, 50) as $row) {
            $values = CsvFile::map($headers, $row, ['issue_date' => $mapping['issue_date'] ?? null, 'due_date' => $mapping['due_date'] ?? null]);
            $samples = [...$samples, ...array_values($values)];
        }

        return DateFormats::detect($samples);
    }
}
