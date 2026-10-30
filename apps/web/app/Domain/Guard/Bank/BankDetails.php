<?php

declare(strict_types=1);

namespace App\Domain\Guard\Bank;

use App\Domain\Guard\Enums\BankScheme;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Shared\Exceptions\PayflowException;

final readonly class BankDetails
{
    public function __construct(
        public BankScheme $scheme,
        public string $holderName,
        public ?string $iban,
        public ?string $accountNumber,
        public ?string $routingNumber,
        public ?string $swiftBic,
        public ?string $bankName,
        public string $bankCountry,
        public string $currency,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromInput(array $data): self
    {
        $scheme = $data['scheme'] instanceof BankScheme ? $data['scheme'] : BankScheme::from((string) $data['scheme']);
        $iban = self::nullable($data['iban'] ?? null);
        $account = self::nullable($data['account_number'] ?? null);
        $routing = self::nullable($data['routing_number'] ?? null);
        $swift = self::nullable($data['swift_bic'] ?? null);

        $details = new self(
            scheme: $scheme,
            holderName: trim((string) ($data['account_holder_name'] ?? '')),
            iban: $scheme === BankScheme::Iban && $iban !== null ? BankRules::normalizeIban($iban) : null,
            accountNumber: $scheme !== BankScheme::Iban && $account !== null ? BankRules::normalizeAccount($account) : null,
            routingNumber: $routing !== null ? BankRules::normalizeAccount($routing) : null,
            swiftBic: $swift !== null ? strtoupper(str_replace(' ', '', $swift)) : null,
            bankName: self::nullable($data['bank_name'] ?? null),
            bankCountry: strtoupper((string) ($data['bank_country'] ?? '')),
            currency: strtoupper((string) ($data['currency'] ?? '')),
        );
        $details->assertValid();

        return $details;
    }

    public static function fromAccount(VendorBankAccount $account): self
    {
        return new self(
            scheme: $account->scheme,
            holderName: $account->account_holder_name,
            iban: $account->iban,
            accountNumber: $account->account_number,
            routingNumber: $account->routing_number,
            swiftBic: $account->swift_bic,
            bankName: $account->bank_name,
            bankCountry: $account->bank_country,
            currency: $account->currency,
        );
    }

    public function assertValid(): void
    {
        if ($this->holderName === '') {
            throw new PayflowException('bank_holder_required', 422);
        }

        match ($this->scheme) {
            BankScheme::Iban => $this->iban !== null && BankRules::ibanValid($this->iban) ? null : throw new PayflowException('iban_invalid', 422),
            BankScheme::UsAch => $this->assertAch(),
            BankScheme::Local => $this->accountNumber !== null && BankRules::localAccountValid($this->accountNumber) ? null : throw new PayflowException('account_invalid', 422),
        };

        if ($this->swiftBic !== null && ! BankRules::swiftValid($this->swiftBic)) {
            throw new PayflowException('swift_invalid', 422);
        }
    }

    public function accountIdentifier(): string
    {
        return (string) ($this->scheme === BankScheme::Iban ? $this->iban : $this->accountNumber);
    }

    public function last4(): string
    {
        return substr($this->accountIdentifier(), -4);
    }

    public function fingerprintMessage(): string
    {
        if ($this->scheme === BankScheme::Iban) {
            return 'v1|iban|'.substr((string) $this->iban, 0, 2).'|'.$this->iban;
        }

        return 'v1|'.$this->scheme->value.'|'.$this->bankCountry.'|'.($this->routingNumber ?? '').'|'.$this->accountNumber;
    }

    public function fingerprint(): string
    {
        return GuardKeys::mac(GuardKeys::BANK_FINGERPRINT, $this->fingerprintMessage());
    }

    public function display(): string
    {
        return self::displayFor($this->scheme, $this->accountIdentifier());
    }

    public static function displayFor(BankScheme $scheme, string $identifierOrLast4): string
    {
        return match ($scheme) {
            BankScheme::Iban => strlen($identifierOrLast4) > 8 ? BankRules::maskIban($identifierOrLast4) : '•••• '.substr($identifierOrLast4, -4),
            BankScheme::UsAch => '•••• '.substr($identifierOrLast4, -4).' (ACH)',
            BankScheme::Local => '•••• '.substr($identifierOrLast4, -4),
        };
    }

    private function assertAch(): void
    {
        if ($this->routingNumber === null || ! BankRules::abaValid($this->routingNumber)) {
            throw new PayflowException('routing_invalid', 422);
        }

        if ($this->accountNumber === null || ! BankRules::achAccountValid($this->accountNumber)) {
            throw new PayflowException('account_invalid', 422);
        }
    }

    private static function nullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
