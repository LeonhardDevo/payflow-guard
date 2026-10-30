import { writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const currencies = [
    'USD',
    'EUR',
    'RUB',
    'GBP',
    'CAD',
    'AUD',
    'CHF',
    'PLN',
    'CZK',
    'SEK',
    'NOK',
    'DKK',
    'KZT',
    'GEL',
    'AMD',
    'AED',
    'TRY',
];
const amounts = [0, 5, 123450, 123456789, -1250];
const vectors = [];

for (const locale of ['en', 'ru']) {
    for (const currency of currencies) {
        const format = new Intl.NumberFormat(locale, {
            style: 'currency',
            currency,
        });
        for (const minor of amounts) {
            vectors.push({
                locale,
                currency,
                minor,
                expected: format.format(minor / 100),
            });
        }
    }
}

writeFileSync(
    resolve(root, 'tests/Fixtures/payflow/money-format-vectors.json'),
    JSON.stringify(vectors, null, 2) + '\n',
);
