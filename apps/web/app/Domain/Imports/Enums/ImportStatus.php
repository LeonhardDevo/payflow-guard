<?php

declare(strict_types=1);

namespace App\Domain\Imports\Enums;

use App\Domain\Shared\Enums\HasValues;

enum ImportStatus: string
{
    use HasValues;

    case Uploaded = 'uploaded';
    case Mapping = 'mapping';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
}
