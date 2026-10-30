<?php

declare(strict_types=1);

namespace App\Domain\Guard\Risk;

use App\Domain\Guard\Enums\BankChangeSource;

final readonly class BankChangeRiskInput
{
    public function __construct(
        public BankChangeSource $source,
        public string $holderName,
        public string $vendorName,
        public ?string $vendorLegalName,
        public ?string $vendorEmail,
        public ?string $requesterEmail,
        public ?string $requesterDomain,
        public string $bankCountry,
        public string $currency,
        public ?string $previousCountry,
        public ?string $previousCurrency,
        public int $sharedWithOtherVendors,
        public bool $reusesRejectedAccount,
        public int $pendingPayouts,
        public int $recentChanges,
        public ?string $requestText,
        public ?string $vendorPhone,
        public ?int $vendorAgeDays,
        public int $version,
        public ?int $phoneChangedDaysAgo,
    ) {}
}
