<?php

declare(strict_types=1);

namespace App\Domain\Imports\Csv;

use App\Domain\Shared\Exceptions\PayflowException;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;

final class CsvFile
{
    public const array DELIMITERS = [',', ';', "\t"];

    public static function sniffDelimiter(string $contents): string
    {
        $lines = array_slice(preg_split('/\r\n|\n|\r/', $contents) ?: [], 0, 5);
        $best = ',';
        $bestScore = -1;

        foreach (self::DELIMITERS as $delimiter) {
            $counts = array_map(fn (string $line): int => substr_count($line, $delimiter), array_filter($lines, fn (string $line): bool => trim($line) !== ''));

            if ($counts === []) {
                continue;
            }

            $score = min($counts) > 0 ? min($counts) * 10 + (count(array_unique($counts)) === 1 ? 5 : 0) : 0;

            if ($score > $bestScore) {
                $best = $delimiter;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** @return array{headers: list<string>, rows: list<list<string>>} */
    public static function parse(string $contents, string $delimiter): array
    {
        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $converted = mb_convert_encoding($contents, 'UTF-8', 'Windows-1251');
            $contents = $converted;
        }

        try {
            $reader = Reader::fromString($contents);
            $reader->setDelimiter($delimiter);
            $records = [];

            foreach ($reader->getRecords() as $record) {
                $records[] = array_values(array_map(fn (mixed $value): string => trim((string) $value), $record));
            }
        } catch (CsvException) {
            throw new PayflowException('invalid_csv', 422);
        }

        $records = array_values(array_filter($records, fn (array $row): bool => implode('', $row) !== ''));

        if ($records === []) {
            throw new PayflowException('invalid_csv', 422);
        }

        $headers = array_shift($records);

        return ['headers' => $headers, 'rows' => $records];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $row
     * @param  array<string, string|null>  $mapping
     * @return array<string, string>
     */
    public static function map(array $headers, array $row, array $mapping): array
    {
        $index = array_flip($headers);
        $result = [];

        foreach ($mapping as $field => $header) {
            if ($header === null || ! isset($index[$header])) {
                continue;
            }

            $result[$field] = $row[$index[$header]] ?? '';
        }

        return $result;
    }
}
