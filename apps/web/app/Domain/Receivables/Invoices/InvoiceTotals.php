<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Invoices;

use InvalidArgumentException;

final class InvoiceTotals
{
    public static function quantityMilli(string|int|float $quantity): int
    {
        $value = is_string($quantity) ? trim($quantity) : (string) $quantity;

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid quantity.');
        }

        $fraction = $matches[2] ?? '';
        $milli = ((int) $matches[1]) * 1000 + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        if (strlen($fraction) > 3 && (int) $fraction[3] >= 5) {
            $milli++;
        }

        return $milli;
    }

    /** @return array{amount_minor: int, tax_minor: int} */
    public static function line(string|int|float $quantity, int $unitPriceMinor, int $taxRateBp): array
    {
        $amount = intdiv(self::quantityMilli($quantity) * $unitPriceMinor + 500, 1000);
        $tax = intdiv($amount * $taxRateBp + 5000, 10000);

        return ['amount_minor' => $amount, 'tax_minor' => $tax];
    }

    /**
     * @param  list<array{amount_minor: int, tax_minor: int, kind?: string}>  $lines
     * @return array{subtotal_minor: int, tax_minor: int, total_minor: int, late_fee_applied_minor: int}
     */
    public static function totals(array $lines): array
    {
        $subtotal = 0;
        $tax = 0;
        $lateFees = 0;

        foreach ($lines as $line) {
            $subtotal += $line['amount_minor'];
            $tax += $line['tax_minor'];

            if (($line['kind'] ?? 'service') === 'late_fee') {
                $lateFees += $line['amount_minor'] + $line['tax_minor'];
            }
        }

        return [
            'subtotal_minor' => $subtotal,
            'tax_minor' => $tax,
            'total_minor' => $subtotal + $tax,
            'late_fee_applied_minor' => $lateFees,
        ];
    }
}
