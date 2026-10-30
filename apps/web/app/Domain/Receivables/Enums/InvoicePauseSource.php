<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Enums;

use App\Domain\Shared\Enums\HasValues;

enum InvoicePauseSource: string
{
    use HasValues;

    case Manual = 'manual';
    case ImportReview = 'import_review';
    case ClientReportedPaid = 'client_reported_paid';
}
