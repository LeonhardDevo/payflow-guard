<?php

declare(strict_types=1);

namespace App\Domain\Guard\Risk;

use App\Domain\Guard\Enums\BankChangeSource;
use App\Domain\Guard\Text\DomainLookalike;
use App\Domain\Guard\Text\RequestTextSignals;
use App\Domain\Guard\Text\TrigramSimilarity;

final class BankChangeRiskScorer
{
    public const array POINTS = [
        'request_via_email' => 20,
        'request_via_document' => 10,
        'lookalike_domain' => 35,
        'domain_mismatch' => 20,
        'holder_name_mismatch' => 25,
        'country_changed' => 20,
        'currency_changed' => 10,
        'shared_account' => 30,
        'reused_rejected_account' => 40,
        'pending_payout' => 15,
        'recent_change' => 15,
        'urgency_language' => 10,
        'secrecy_language' => 10,
        'new_contact_in_request' => 10,
        'new_vendor' => 10,
        'phone_recently_changed' => 15,
    ];

    public const float HOLDER_MATCH_THRESHOLD = 0.6;

    public static function score(BankChangeRiskInput $input): RiskResult
    {
        $signals = [];
        $add = function (string $code, array $params = []) use (&$signals): void {
            $signals[] = ['code' => $code, 'points' => self::POINTS[$code], 'params' => $params];
        };

        if ($input->source === BankChangeSource::VendorEmail) {
            $add('request_via_email');
        }

        if ($input->source === BankChangeSource::InvoiceDocument) {
            $add('request_via_document');
        }

        self::domainSignal($input, $add);

        $similarity = self::holderSimilarity($input->holderName, $input->vendorName, $input->vendorLegalName);

        if ($similarity < self::HOLDER_MATCH_THRESHOLD) {
            $add('holder_name_mismatch', ['similarity' => (int) round($similarity * 100)]);
        }

        if ($input->previousCountry !== null && $input->previousCountry !== $input->bankCountry) {
            $add('country_changed', ['from' => $input->previousCountry, 'to' => $input->bankCountry]);
        }

        if ($input->previousCurrency !== null && $input->previousCurrency !== $input->currency) {
            $add('currency_changed', ['from' => $input->previousCurrency, 'to' => $input->currency]);
        }

        if ($input->sharedWithOtherVendors > 0) {
            $add('shared_account', ['count' => $input->sharedWithOtherVendors]);
        }

        if ($input->reusesRejectedAccount) {
            $add('reused_rejected_account');
        }

        if ($input->pendingPayouts > 0) {
            $add('pending_payout', ['count' => $input->pendingPayouts]);
        }

        if ($input->recentChanges > 0) {
            $add('recent_change', ['count' => $input->recentChanges]);
        }

        $text = $input->requestText ?? '';

        if ($text !== '' && RequestTextSignals::hasUrgency($text)) {
            $add('urgency_language');
        }

        if ($text !== '' && RequestTextSignals::hasSecrecy($text)) {
            $add('secrecy_language');
        }

        if ($text !== '' && RequestTextSignals::hasNewContact($text, $input->vendorPhone)) {
            $add('new_contact_in_request');
        }

        if ($input->vendorAgeDays !== null && $input->vendorAgeDays < 30 && $input->version > 1) {
            $add('new_vendor', ['days' => $input->vendorAgeDays]);
        }

        if ($input->phoneChangedDaysAgo !== null && $input->phoneChangedDaysAgo < 30) {
            $add('phone_recently_changed', ['days' => $input->phoneChangedDaysAgo]);
        }

        return RiskResult::fromSignals($signals);
    }

    public static function holderSimilarity(string $holder, string $vendorName, ?string $legalName): float
    {
        $byName = TrigramSimilarity::similarity($holder, $vendorName);
        $byLegal = $legalName !== null && $legalName !== '' ? TrigramSimilarity::similarity($holder, $legalName) : 0.0;

        return max($byName, $byLegal);
    }

    public static function similarityBucket(float $similarity): string
    {
        return match (true) {
            $similarity >= 0.8 => 'match',
            $similarity >= self::HOLDER_MATCH_THRESHOLD => 'partial',
            default => 'mismatch',
        };
    }

    /** @param  \Closure(string, array<string, string|int>=): void  $add */
    private static function domainSignal(BankChangeRiskInput $input, \Closure $add): void
    {
        $requesterDomain = $input->requesterDomain !== null ? strtolower($input->requesterDomain) : null;
        $vendorEmail = $input->vendorEmail !== null ? strtolower($input->vendorEmail) : null;

        if ($requesterDomain === null || $requesterDomain === '' || $vendorEmail === null || ! str_contains($vendorEmail, '@')) {
            return;
        }

        $vendorDomain = substr($vendorEmail, strrpos($vendorEmail, '@') + 1);

        if (DomainLookalike::isLookalike($requesterDomain, $vendorDomain)) {
            $add('lookalike_domain', ['domain' => $requesterDomain, 'known' => $vendorDomain]);

            return;
        }

        if (DomainLookalike::isFreeMail($vendorDomain)) {
            if ($input->requesterEmail === null || strtolower($input->requesterEmail) !== $vendorEmail) {
                $add('domain_mismatch', ['domain' => $requesterDomain, 'known' => $vendorDomain]);
            }

            return;
        }

        if ($requesterDomain !== $vendorDomain) {
            $add('domain_mismatch', ['domain' => $requesterDomain, 'known' => $vendorDomain]);
        }
    }
}
