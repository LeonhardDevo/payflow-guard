<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Guard\Approvals\ApprovalInput;
use App\Domain\Guard\Approvals\ApprovalRequirement;
use App\Domain\Guard\Bank\BankDetails;
use App\Domain\Guard\Bank\BankRules;
use App\Domain\Guard\Enums\BankChangeSource;
use App\Domain\Guard\Risk\BankChangeRiskInput;
use App\Domain\Guard\Risk\BankChangeRiskScorer;
use App\Domain\Guard\Risk\PayoutRiskInput;
use App\Domain\Guard\Risk\PayoutRiskScorer;
use App\Domain\Guard\Risk\RiskResult;
use App\Domain\Guard\Settings\GuardSettingsService;
use App\Domain\Guard\Text\DomainLookalike;
use App\Domain\Guard\Text\NameNormalizer;
use App\Domain\Guard\Text\RequestTextSignals;
use App\Domain\Guard\Text\TrigramSimilarity;
use App\Domain\Shared\Exceptions\PayflowException;
use Database\Seeders\Payflow\NorthstarGuardSeeder;
use Tests\Feature\Payflow\Support\Payflow;

it('computes the required approvals for every contract vector', function (): void {
    $fixture = Payflow::fixture('approval-vectors.json');

    foreach ($fixture['vectors'] as $vector) {
        $result = ApprovalRequirement::for(new ApprovalInput(
            amountMinor: $vector['amount'],
            threshold: $fixture['thresholds'],
            band: RiskBand::from($vector['band']),
            coolingOff: $vector['cooling'],
            recentChange: $vector['recent_change'],
            firstPayout: false,
            splitSuspected: $vector['split'],
            cumulative30Minor: $vector['cum30'],
            othersApprove: $vector['approve'],
            othersRelease: $vector['release'],
            trustedContactActive: $vector['trusted'],
            allowTelegramApprovals: true,
        ));

        expect($result->required)->toBe($vector['required'], 'vector '.$vector['id'])
            ->and($result->releaseRequiresOtherUser)->toBe($vector['release_other'], 'vector '.$vector['id'])
            ->and($result->blocked)->toBe($vector['blocked'], 'vector '.$vector['id']);
    }
});

it('snapshots the auto-approval basis and uses the trusted contact only when needed', function (): void {
    $base = fn (array $overrides = []): ApprovalInput => new ApprovalInput(...[
        'amountMinor' => 32000,
        'threshold' => ['auto_approve_below_minor' => 50000, 'dual_approval_from_minor' => 1000000, 'auto_approve_cumulative_minor' => null],
        'band' => RiskBand::Low,
        'coolingOff' => false,
        'recentChange' => false,
        'firstPayout' => false,
        'splitSuspected' => false,
        'cumulative30Minor' => 32000,
        'othersApprove' => 2,
        'othersRelease' => 1,
        'trustedContactActive' => false,
        'allowTelegramApprovals' => true,
        ...$overrides,
    ]);

    $auto = ApprovalRequirement::for($base());
    $capped = ApprovalRequirement::for($base(['cumulative30Minor' => 70000]));
    $noThreshold = ApprovalRequirement::for($base(['threshold' => null, 'amountMinor' => 100]));
    $solo = ApprovalRequirement::for($base(['band' => RiskBand::Medium, 'othersApprove' => 0, 'trustedContactActive' => true]));
    $first = ApprovalRequirement::for($base(['firstPayout' => true]));

    expect($auto->autoApproved)->toBeTrue()
        ->and($auto->autoApproveBasis)->toBe(['threshold' => 50000, 'cumulative_used' => 32000, 'cumulative_cap' => 100000])
        ->and($capped->required)->toBe(1)
        ->and($noThreshold->required)->toBe(1)
        ->and($noThreshold->autoApproved)->toBeFalse()
        ->and($solo->usesTrustedContact)->toBeTrue()
        ->and($auto->usesTrustedContact)->toBeFalse()
        ->and($first->required)->toBe(1);
});

it('allows Telegram approvals only below high risk without cooling-off or split', function (): void {
    $input = fn (RiskBand $band, bool $cooling, bool $split, bool $allowed): ApprovalInput => new ApprovalInput(
        amountMinor: 720000,
        threshold: null,
        band: $band,
        coolingOff: $cooling,
        recentChange: false,
        firstPayout: false,
        splitSuspected: $split,
        cumulative30Minor: 0,
        othersApprove: 3,
        othersRelease: 1,
        trustedContactActive: false,
        allowTelegramApprovals: $allowed,
    );

    expect(ApprovalRequirement::for($input(RiskBand::Medium, false, false, true))->telegramAllowed)->toBeTrue()
        ->and(ApprovalRequirement::for($input(RiskBand::High, false, false, true))->telegramAllowed)->toBeFalse()
        ->and(ApprovalRequirement::for($input(RiskBand::Low, true, false, true))->telegramAllowed)->toBeFalse()
        ->and(ApprovalRequirement::for($input(RiskBand::Low, false, true, true))->telegramAllowed)->toBeFalse()
        ->and(ApprovalRequirement::for($input(RiskBand::Low, false, false, false))->telegramAllowed)->toBeFalse();
});

it('scores the PixelForge bank change at 145 points capped to 100 and high', function (): void {
    $result = BankChangeRiskScorer::score(new BankChangeRiskInput(
        source: BankChangeSource::VendorEmail,
        holderName: 'PF Studio Payments UAB',
        vendorName: 'PixelForge Studio',
        vendorLegalName: 'PixelForge Studio sp. z o.o.',
        vendorEmail: 'billing@pixelforge.example',
        requesterEmail: 'billing@pixelf0rge.example',
        requesterDomain: 'pixelf0rge.example',
        bankCountry: 'LT',
        currency: 'EUR',
        previousCountry: 'PL',
        previousCurrency: 'EUR',
        sharedWithOtherVendors: 0,
        reusesRejectedAccount: false,
        pendingPayouts: 1,
        recentChanges: 0,
        requestText: NorthstarGuardSeeder::PIXELFORGE_REQUEST,
        vendorPhone: '+48 22 555 01 47',
        vendorAgeDays: 240,
        version: 2,
        phoneChangedDaysAgo: null,
    ));

    expect(array_sum(array_column($result->signals, 'points')))->toBe(145)
        ->and($result->score)->toBe(100)
        ->and($result->band)->toBe(RiskBand::High)
        ->and($result->codes())->toBe([
            'request_via_email', 'lookalike_domain', 'holder_name_mismatch', 'country_changed', 'pending_payout',
            'urgency_language', 'secrecy_language', 'new_contact_in_request',
        ]);
});

it('raises every bank-change signal under its own condition', function (): void {
    $input = fn (array $overrides): BankChangeRiskInput => new BankChangeRiskInput(...[
        'source' => BankChangeSource::ManualEntry,
        'holderName' => 'Acme Design',
        'vendorName' => 'Acme Design',
        'vendorLegalName' => null,
        'vendorEmail' => 'ap@acme.example',
        'requesterEmail' => null,
        'requesterDomain' => null,
        'bankCountry' => 'DE',
        'currency' => 'EUR',
        'previousCountry' => 'DE',
        'previousCurrency' => 'EUR',
        'sharedWithOtherVendors' => 0,
        'reusesRejectedAccount' => false,
        'pendingPayouts' => 0,
        'recentChanges' => 0,
        'requestText' => null,
        'vendorPhone' => null,
        'vendorAgeDays' => 400,
        'version' => 2,
        'phoneChangedDaysAgo' => null,
        ...$overrides,
    ]);

    expect(BankChangeRiskScorer::score($input([]))->signals)->toBe([])
        ->and(BankChangeRiskScorer::score($input(['source' => BankChangeSource::InvoiceDocument]))->codes())->toBe(['request_via_document'])
        ->and(BankChangeRiskScorer::score($input(['requesterEmail' => 'x@gmail.com', 'requesterDomain' => 'gmail.com']))->codes())->toBe(['domain_mismatch'])
        ->and(BankChangeRiskScorer::score($input(['vendorEmail' => 'acme@gmail.com', 'requesterEmail' => 'acme@gmail.com', 'requesterDomain' => 'gmail.com']))->codes())->toBe([])
        ->and(BankChangeRiskScorer::score($input(['vendorEmail' => 'acme@gmail.com', 'requesterEmail' => 'acme.billing@gmail.com', 'requesterDomain' => 'gmail.com']))->codes())->toBe(['domain_mismatch'])
        ->and(BankChangeRiskScorer::score($input(['currency' => 'USD']))->codes())->toBe(['currency_changed'])
        ->and(BankChangeRiskScorer::score($input(['sharedWithOtherVendors' => 1]))->score)->toBe(30)
        ->and(BankChangeRiskScorer::score($input(['reusesRejectedAccount' => true]))->score)->toBe(40)
        ->and(BankChangeRiskScorer::score($input(['recentChanges' => 1]))->codes())->toBe(['recent_change'])
        ->and(BankChangeRiskScorer::score($input(['vendorAgeDays' => 10]))->codes())->toBe(['new_vendor'])
        ->and(BankChangeRiskScorer::score($input(['vendorAgeDays' => 10, 'version' => 1]))->codes())->toBe([])
        ->and(BankChangeRiskScorer::score($input(['phoneChangedDaysAgo' => 5]))->codes())->toBe(['phone_recently_changed'])
        ->and(BankChangeRiskScorer::score($input(['requestText' => 'Call me at +44 20 7946 0958', 'vendorPhone' => '+44 20 7946 0958']))->codes())->toBe([])
        ->and(BankChangeRiskScorer::score($input(['requestText' => 'Срочно оплатите, не звоните в офис']))->codes())->toBe(['urgency_language', 'secrecy_language']);
});

it('bands risk scores at 25 and 50', function (): void {
    expect(RiskResult::band(0))->toBe(RiskBand::Low)
        ->and(RiskResult::band(24))->toBe(RiskBand::Low)
        ->and(RiskResult::band(25))->toBe(RiskBand::Medium)
        ->and(RiskResult::band(49))->toBe(RiskBand::Medium)
        ->and(RiskResult::band(50))->toBe(RiskBand::High);
});

it('scores Ivan Smirnov PO-2026-0089 as unusual and urgent', function (): void {
    $result = PayoutRiskScorer::score(new PayoutRiskInput(
        amountMinor: 720000,
        coolingOff: false,
        coolingOffUntil: null,
        accountChangeBand: RiskBand::Low,
        accountChangeScore: 0,
        verifiedDaysAgo: 295,
        releasedToAccount: 5,
        releasedAmounts: [220000, 240000, 220000, 220000, 200000],
        duplicateOf: null,
        recentSubmittedAmounts: [],
        dualApprovalFromMinor: 1000000,
        urgentDue: true,
        dueDate: '2026-10-23',
    ));

    expect($result->codes())->toBe(['unusual_amount', 'urgent_due'])
        ->and($result->score)->toBe(30)
        ->and($result->band)->toBe(RiskBand::Medium);
});

it('flags split payments, duplicates, cooling-off and first payouts', function (): void {
    $input = fn (array $overrides): PayoutRiskInput => new PayoutRiskInput(...[
        'amountMinor' => 500000,
        'coolingOff' => false,
        'coolingOffUntil' => null,
        'accountChangeBand' => RiskBand::Low,
        'accountChangeScore' => 0,
        'verifiedDaysAgo' => 400,
        'releasedToAccount' => 3,
        'releasedAmounts' => [],
        'duplicateOf' => null,
        'recentSubmittedAmounts' => [],
        'dualApprovalFromMinor' => 1000000,
        'urgentDue' => false,
        'dueDate' => null,
        ...$overrides,
    ]);

    $split = PayoutRiskScorer::score($input(['recentSubmittedAmounts' => [600000]]));

    expect($split->codes())->toBe(['split_suspected'])
        ->and($split->signals[0]['params']['total_minor'])->toBe(1100000)
        ->and(PayoutRiskScorer::score($input(['recentSubmittedAmounts' => [400000]]))->codes())->toBe([])
        ->and(PayoutRiskScorer::score($input(['amountMinor' => 1200000, 'recentSubmittedAmounts' => [600000]]))->codes())->toBe([])
        ->and(PayoutRiskScorer::score($input(['dualApprovalFromMinor' => null, 'recentSubmittedAmounts' => [600000]]))->codes())->toBe([])
        ->and(PayoutRiskScorer::score($input(['duplicateOf' => 'PO-2026-0083']))->score)->toBe(30)
        ->and(PayoutRiskScorer::score($input(['coolingOff' => true, 'verifiedDaysAgo' => 1, 'accountChangeBand' => RiskBand::High, 'accountChangeScore' => 100]))->codes())->toBe(['cooling_off', 'bank_change_risk_high'])
        ->and(PayoutRiskScorer::score($input(['verifiedDaysAgo' => 26, 'accountChangeBand' => RiskBand::Medium, 'accountChangeScore' => 35, 'releasedToAccount' => 0]))->score)->toBe(35)
        ->and(PayoutRiskScorer::score($input(['releasedAmounts' => [100000, 100000]]))->codes())->toBe([])
        ->and(PayoutRiskScorer::median([1, 3, 2, 4]))->toBe(2.5);
});

it('validates IBANs, ABA routing numbers and SWIFT codes', function (): void {
    expect(BankRules::ibanValid('LT58 3250 0677 4120 0519'))->toBeTrue()
        ->and(BankRules::ibanValid('PL61109010140000071219812874'))->toBeTrue()
        ->and(BankRules::ibanValid('GE44TB7741936582010047'))->toBeTrue()
        ->and(BankRules::ibanValid('LT58325006774120051'))->toBeFalse()
        ->and(BankRules::ibanValid('LT59325006774120 0519'))->toBeFalse()
        ->and(BankRules::ibanValid('XX583250067741200519'))->toBeFalse()
        ->and(BankRules::abaValid('123456780'))->toBeTrue()
        ->and(BankRules::abaValid('123456789'))->toBeFalse()
        ->and(BankRules::abaValid('12345678'))->toBeFalse()
        ->and(BankRules::achAccountValid('000123454471'))->toBeTrue()
        ->and(BankRules::achAccountValid('12a'))->toBeFalse()
        ->and(BankRules::swiftValid('DEMOLV2X'))->toBeTrue()
        ->and(BankRules::swiftValid('DEMOLV2XXXX'))->toBeTrue()
        ->and(BankRules::swiftValid('DEMO1'))->toBeFalse()
        ->and(BankRules::maskIban('LT583250067741200519'))->toBe('LT58 •••• •••• •••• 0519')
        ->and(BankRules::maskSwift('DEMOLV2X'))->toBe('DEMO••2X')
        ->and(BankRules::maskTaxId('PL 5251 4471'))->toBe('•••• 4471');
});

it('normalizes bank details and derives stable fingerprints without holder name or currency', function (): void {
    $a = BankDetails::fromInput(['scheme' => 'iban', 'account_holder_name' => 'A', 'iban' => 'lt58-3250 0677 4120 0519', 'bank_country' => 'LT', 'currency' => 'EUR']);
    $b = BankDetails::fromInput(['scheme' => 'iban', 'account_holder_name' => 'B', 'iban' => 'LT583250067741200519', 'bank_country' => 'LT', 'currency' => 'USD']);
    $ach = BankDetails::fromInput(['scheme' => 'us_ach', 'account_holder_name' => 'CloudNest', 'routing_number' => '123456780', 'account_number' => '000123454471', 'bank_country' => 'US', 'currency' => 'USD']);

    expect($a->iban)->toBe('LT583250067741200519')
        ->and($a->fingerprintMessage())->toBe('v1|iban|LT|LT583250067741200519')
        ->and($a->fingerprint())->toBe($b->fingerprint())
        ->and(strlen($a->fingerprint()))->toBe(64)
        ->and($a->last4())->toBe('0519')
        ->and($ach->fingerprintMessage())->toBe('v1|us_ach|US|123456780|000123454471')
        ->and($ach->display())->toBe('•••• 4471 (ACH)')
        ->and($ach->fingerprint())->not->toBe($a->fingerprint());

    expect(fn () => BankDetails::fromInput(['scheme' => 'iban', 'account_holder_name' => 'A', 'iban' => 'LT00', 'bank_country' => 'LT', 'currency' => 'EUR']))
        ->toThrow(PayflowException::class, 'iban_invalid');
    expect(fn () => BankDetails::fromInput(['scheme' => 'us_ach', 'account_holder_name' => 'A', 'routing_number' => '123456789', 'account_number' => '1234', 'bank_country' => 'US', 'currency' => 'USD']))
        ->toThrow(PayflowException::class, 'routing_invalid');
});

it('matches the text helper vectors of B.10', function (): void {
    expect(TrigramSimilarity::similarity('PixelForge Studio sp. z o.o.', 'PF Studio Payments UAB'))->toBeLessThan(0.6)
        ->and(TrigramSimilarity::similarity('IE Ivan Smirnov', 'Иван Смирнов'))->toBe(1.0)
        ->and(TrigramSimilarity::similarity('QA Crew OÜ', 'QA Crew'))->toBe(1.0)
        ->and(NameNormalizer::normalize('ООО «Ёлка» LLC'))->toBe('elka')
        ->and(DomainLookalike::isLookalike('pixelf0rge.studio', 'pixelforge.studio'))->toBeTrue()
        ->and(DomainLookalike::isLookalike('pixelforge.co', 'pixelforge.studio'))->toBeTrue()
        ->and(DomainLookalike::isLookalike('pixelforge-billing.com', 'pixelforge.studio'))->toBeTrue()
        ->and(DomainLookalike::isLookalike('qacrew.ee', 'qacrew.ee'))->toBeFalse()
        ->and(DomainLookalike::isLookalike('gmail.com', 'pixelforge.studio'))->toBeFalse()
        ->and(DomainLookalike::isLookalike('xn--pxelforge-xyz.com', 'pixelforge.studio'))->toBeTrue()
        ->and(DomainLookalike::isLookalike('acme.co.uk', 'acme.com'))->toBeTrue()
        ->and(RequestTextSignals::hasUrgency('Please pay ASAP'))->toBeTrue()
        ->and(RequestTextSignals::hasUrgency('todays plan'))->toBeFalse()
        ->and(RequestTextSignals::hasSecrecy('I am travelling this week'))->toBeTrue()
        ->and(RequestTextSignals::hasNewContact('call +370 600 41 977', '+48 22 555 01 47'))->toBeTrue();
});

it('detects loosening changes of the Guard settings', function (): void {
    $before = [
        'thresholds' => ['USD' => ['auto_approve_below_minor' => 50000, 'dual_approval_from_minor' => 1000000, 'auto_approve_cumulative_minor' => 100000]],
        'cooling_off_hours_medium' => 24,
        'cooling_off_hours_high' => 48,
        'allow_telegram_approvals' => false,
        'escalate_pending_after_hours' => 24,
        'guard_trust_delay_hours' => 72,
        'require_second_factor' => true,
        'solo_release_delay_hours' => 24,
    ];

    $raised = $before;
    $raised['thresholds']['USD']['auto_approve_below_minor'] = 60000;
    $added = $before;
    $added['thresholds']['EUR'] = $before['thresholds']['USD'];
    $tightened = $before;
    $tightened['thresholds']['USD']['dual_approval_from_minor'] = 500000;
    $tightened['cooling_off_hours_high'] = 72;

    expect(GuardSettingsService::isLoosening($before, $before))->toBeFalse()
        ->and(GuardSettingsService::isLoosening($before, $raised))->toBeTrue()
        ->and(GuardSettingsService::isLoosening($before, $added))->toBeTrue()
        ->and(GuardSettingsService::isLoosening($before, $tightened))->toBeFalse()
        ->and(GuardSettingsService::isLoosening($before, [...$before, 'guard_trust_delay_hours' => 24]))->toBeTrue()
        ->and(GuardSettingsService::isLoosening($before, [...$before, 'allow_telegram_approvals' => true]))->toBeTrue()
        ->and(GuardSettingsService::isLoosening($before, [...$before, 'require_second_factor' => false]))->toBeTrue()
        ->and(GuardSettingsService::isLoosening($before, [...$before, 'solo_release_delay_hours' => 0]))->toBeTrue()
        ->and(GuardSettingsService::isLoosening($before, [...$before, 'escalate_pending_after_hours' => 48]))->toBeFalse();
});
