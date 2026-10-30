import { describe, expect, it } from 'vitest';
import { pfPaletteActions, pfSearchHits } from '@/lib/pf/palette';
import type { PfSearchResponse } from '@/types/payflow';

const grant = (abilities: string[]) => (ability: string) =>
    abilities.includes(ability);

const label = (id: string) => `label:${id}`;

const none = { component: null, props: {} };

describe('pfPaletteActions', () => {
    it('offers only the actions the viewer is allowed to run', () => {
        expect(pfPaletteActions(grant([]), none, label)).toEqual([]);

        const ids = pfPaletteActions(
            grant([
                'invoices.manage',
                'invoices.record_payment',
                'payouts.create',
                'bank_changes.request',
                'imports.run',
            ]),
            none,
            label,
        ).map((action) => action.id);

        expect(ids).toEqual([
            'create_invoice',
            'record_payment',
            'new_payout',
            'request_bank_change',
            'import_csv',
        ]);
    });

    it('uses generic destinations outside a record page', () => {
        const actions = pfPaletteActions(
            grant([
                'invoices.manage',
                'invoices.record_payment',
                'payouts.create',
                'bank_changes.request',
                'imports.run',
            ]),
            none,
            label,
        );
        const href = Object.fromEntries(actions.map((a) => [a.id, a.href]));

        expect(href.create_invoice).toBe('/invoices/create');
        expect(href.record_payment).toBe('/invoices?status=outstanding');
        expect(href.new_payout).toBe('/payouts/create');
        expect(href.request_bank_change).toBe('/vendors');
        expect(href.import_csv).toBe('/imports');
        expect(actions[0].label).toBe('label:create_invoice');
    });

    it('targets the record on screen', () => {
        const invoicePage = {
            component: 'invoices/Show',
            props: { invoice: { id: 42, status: 'partially_paid' } },
        };
        const record = pfPaletteActions(
            grant(['invoices.record_payment']),
            invoicePage,
            label,
        );

        expect(record[0].href).toBe('/invoices/42?record=1');

        const paid = pfPaletteActions(
            grant(['invoices.record_payment']),
            {
                component: 'invoices/Show',
                props: { invoice: { id: 42, status: 'paid' } },
            },
            label,
        );

        expect(paid[0].href).toBe('/invoices?status=outstanding');

        const vendorPage = {
            component: 'vendors/Show',
            props: { vendor: { id: 7 } },
        };
        const vendor = pfPaletteActions(
            grant(['payouts.create', 'bank_changes.request']),
            vendorPage,
            label,
        );

        expect(vendor.map((a) => a.href)).toEqual([
            '/payouts/create?vendor_id=7',
            '/vendors/7/bank-changes/create',
        ]);

        const clientPage = {
            component: 'clients/Show',
            props: { client: { id: 3 } },
        };

        expect(
            pfPaletteActions(grant(['invoices.manage']), clientPage, label)[0]
                .href,
        ).toBe('/invoices/create?client_id=3');
    });
});

describe('pfSearchHits', () => {
    const response: PfSearchResponse = {
        invoices: [
            {
                id: 1,
                number: 'NS-2026-0001',
                client: { id: 9, name: 'Acme', late_payer_class: 'reliable' },
                balance_due: { minor: 125000, currency: 'USD' },
                display_status: 'overdue',
            },
            {
                id: 2,
                number: null,
                client: { id: 9, name: 'Acme', late_payer_class: 'reliable' },
                balance_due: { minor: 0, currency: 'USD' },
                display_status: 'draft',
            },
        ],
        clients: [{ id: 9, name: 'Acme', late_payer_class: 'reliable' }],
        vendors: [{ id: 4, name: 'Acme Supply', status: 'active' }],
        payouts: [
            {
                id: 5,
                number: 'PO-2026-0003',
                vendor: { id: 4, name: 'Acme Supply', status: 'active' },
            },
        ],
    };

    it('returns nothing without a response', () => {
        expect(
            pfSearchHits(
                null,
                () => '',
                () => '',
                'Draft',
            ),
        ).toEqual([]);
    });

    it('maps every group to its page in a stable order', () => {
        const hits = pfSearchHits(
            response,
            (m) => `${m.minor} ${m.currency}`,
            (s) => `status:${s}`,
            'Draft',
        );

        expect(hits.map((h) => [h.group, h.href])).toEqual([
            ['invoices', '/invoices/1'],
            ['invoices', '/invoices/2'],
            ['clients', '/clients/9'],
            ['vendors', '/vendors/4'],
            ['payouts', '/payouts/5'],
        ]);
        expect(hits[0].label).toBe('NS-2026-0001 · Acme');
        expect(hits[0].hint).toBe('125000 USD · status:overdue');
        expect(hits[1].label).toBe('Draft · Acme');
        expect(hits[4].label).toBe('PO-2026-0003 · Acme Supply');
    });
});
