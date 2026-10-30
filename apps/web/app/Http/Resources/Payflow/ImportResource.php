<?php

declare(strict_types=1);

namespace App\Http\Resources\Payflow;

use App\Domain\Imports\ImportService;
use App\Domain\Imports\Mapping\ImportFields;
use App\Domain\Imports\Models\Import;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;

final class ImportResource
{
    /** @return array<string, mixed> */
    public static function listItem(Import $import): array
    {
        return [
            'id' => $import->id,
            'kind' => $import->kind->value,
            'preset' => $import->preset->value,
            'status' => $import->status->value,
            'original_name' => $import->original_name,
            'rows_total' => $import->rows_total,
            'rows_imported' => $import->rows_imported,
            'rows_skipped' => $import->rows_skipped,
            'rows_failed' => $import->rows_failed,
            'activation_status' => $import->activation_status->value,
            'created_by' => UserRef::of($import->creator),
            'created_at' => Iso::dateTime($import->created_at),
            'finished_at' => Iso::dateTime($import->finished_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Import $import): array
    {
        $sample = [];

        try {
            $sample = array_slice(ImportService::read($import)['rows'], 0, 5);
        } catch (PayflowException) {
            $sample = [];
        }

        $options = $import->options;
        $suggested = ImportFields::suggest($import->kind, $import->preset, $import->headers);
        $mapping = $import->mapping ?? $suggested;

        return [
            ...self::listItem($import),
            'headers' => $import->headers,
            'sample_rows' => $sample,
            'mapping' => $mapping,
            'suggested_mapping' => $suggested,
            'fields' => ImportFields::describe($import->kind),
            'options' => [
                'default_currency' => (string) ($options['default_currency'] ?? 'USD'),
                'date_format' => (string) ($options['date_format'] ?? 'Y-m-d'),
                'create_missing_clients' => (bool) ($options['create_missing_clients'] ?? true),
                'mark_as_sent' => (bool) ($options['mark_as_sent'] ?? true),
            ],
            'errors' => $import->errors,
            'progress' => $import->rows_total > 0 ? round(min(1, $import->rows_processed / $import->rows_total), 4) : 0,
        ];
    }
}
