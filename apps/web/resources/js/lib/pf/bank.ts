import type { BankFields } from '@/components/pf/guard/BankDetailsFields.vue';

export function blankBank(
    currency: string,
    country: string | null,
): BankFields {
    return {
        scheme: 'iban',
        account_holder_name: '',
        iban: '',
        account_number: '',
        routing_number: '',
        swift_bic: '',
        bank_name: '',
        bank_country: country,
        currency,
    };
}

export function bankPayload(bank: BankFields): Record<string, string | null> {
    const clean = (value: string) => value.replace(/\s+/g, '').toUpperCase();

    return {
        scheme: bank.scheme,
        account_holder_name: bank.account_holder_name.trim(),
        iban: bank.scheme === 'iban' ? clean(bank.iban) || null : null,
        account_number:
            bank.scheme === 'iban'
                ? null
                : bank.account_number.replace(/\s+/g, '') || null,
        routing_number:
            bank.scheme === 'us_ach'
                ? bank.routing_number.replace(/\s+/g, '') || null
                : null,
        swift_bic: clean(bank.swift_bic) || null,
        bank_name: bank.bank_name.trim() || null,
        bank_country: bank.bank_country,
        currency: bank.currency,
    };
}
