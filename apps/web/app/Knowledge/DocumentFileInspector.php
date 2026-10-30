<?php

declare(strict_types=1);

namespace App\Knowledge;

use finfo;
use Illuminate\Http\UploadedFile;
use ZipArchive;

final class DocumentFileInspector
{
    public const int MAX_SIZE_MB = 20;

    public const int MAX_DOCX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    public const int MAX_DOCX_COMPRESSION_RATIO = 100;

    public const int MAX_DOCX_ENTRIES = 10_000;

    private const array ALLOWED = [
        'pdf' => ['application/pdf'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
        'markdown' => ['text/plain', 'text/markdown', 'text/x-markdown'],
        'html' => ['text/html', 'text/plain'],
        'htm' => ['text/html', 'text/plain'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ];

    private const array CANONICAL = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'html' => 'text/html',
        'htm' => 'text/html',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    /** @return list<string> e.g. [".pdf", ".docx", ...] for the file input `accept` */
    public static function acceptList(): array
    {
        return array_map(fn (string $extension): string => '.'.$extension, array_keys(self::ALLOWED));
    }

    public static function maxSizeKilobytes(): int
    {
        return self::MAX_SIZE_MB * 1024;
    }

    public function inspect(UploadedFile $file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! array_key_exists($extension, self::ALLOWED)) {
            return null;
        }

        $path = (string) $file->getRealPath();
        $sniffed = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($path));

        if (! in_array($sniffed, self::ALLOWED[$extension], true)) {
            return null;
        }

        if ($extension === 'docx' && ! $this->isWordPackage($path)) {
            return null;
        }

        if (in_array($extension, ['txt', 'md', 'markdown', 'html', 'htm'], true) && ! $this->isText($path)) {
            return null;
        }

        return self::CANONICAL[$extension];
    }

    private function isWordPackage(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            if ($zip->locateName('word/document.xml') === false || $zip->locateName('[Content_Types].xml') === false) {
                return false;
            }

            return $this->withinExpansionLimits($zip);
        } finally {
            $zip->close();
        }
    }

    private function withinExpansionLimits(ZipArchive $zip): bool
    {
        if ($zip->numFiles > self::MAX_DOCX_ENTRIES) {
            return false;
        }

        $uncompressed = 0;
        $compressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                return false;
            }

            $uncompressed += (int) $stat['size'];
            $compressed += (int) $stat['comp_size'];

            if ($uncompressed > self::MAX_DOCX_UNCOMPRESSED_BYTES) {
                return false;
            }
        }

        return $compressed === 0 || $uncompressed / $compressed <= self::MAX_DOCX_COMPRESSION_RATIO;
    }

    private function isText(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $sample = (string) fread($handle, 65536);
        fclose($handle);

        if (str_contains($sample, "\0")) {
            return false;
        }

        return mb_check_encoding($sample, 'UTF-8') || mb_check_encoding(substr($sample, 0, -3), 'UTF-8');
    }
}
