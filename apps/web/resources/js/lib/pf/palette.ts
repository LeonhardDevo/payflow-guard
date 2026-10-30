import { withQuery } from '@/lib/endpoints';
import { index as importsIndex } from '@/routes/imports';
import {
    create as createInvoice,
    index as invoicesIndex,
    show as showInvoice,
} from '@/routes/invoices';
import { create as createPayout, show as showPayout } from '@/routes/payouts';
import { show as showClient } from '@/routes/clients';
import { index as vendorsIndex, show as showVendor } from '@/routes/vendors';
import { create as createBankChange } from '@/routes/vendors/bank-changes';
import type { PfSearchResponse } from '@/types/payflow';

export type PfPaletteActionId =
    | 'create_invoice'
    | 'record_payment'
    | 'new_payout'
    | 'request_bank_change'
    | 'import_csv';

export type PfPaletteAction = {
    id: PfPaletteActionId;
    label: string;
    href: string;
};

export type PfPaletteContext = {
    component: string | null;
    props: Record<string, unknown>;
};

export type PfPaletteHit = {
    key: string;
    group: 'invoices' | 'clients' | 'vendors' | 'payouts';
    label: string;
    hint: string | null;
    href: string;
};

type Can = (ability: string) => boolean;

function contextId(ctx: PfPaletteContext, prop: string): number | null {
    const value = ctx.props[prop];

    if (value && typeof value === 'object' && 'id' in value) {
        const id = (value as { id: unknown }).id;

        return typeof id === 'number' ? id : null;
    }

    return null;
}

function contextInvoiceRecordable(ctx: PfPaletteContext): number | null {
    if (ctx.component !== 'invoices/Show') {
        return null;
    }

    const invoice = ctx.props.invoice as
        | { id?: unknown; status?: unknown }
        | undefined;

    if (
        !invoice ||
        typeof invoice.id !== 'number' ||
        !['open', 'partially_paid'].includes(String(invoice.status))
    ) {
        return null;
    }

    return invoice.id;
}

export function pfPaletteActions(
    can: Can,
    ctx: PfPaletteContext,
    label: (id: PfPaletteActionId) => string,
): PfPaletteAction[] {
    const actions: PfPaletteAction[] = [];

    if (can('invoices.manage')) {
        const clientId =
            ctx.component === 'clients/Show' ? contextId(ctx, 'client') : null;

        actions.push({
            id: 'create_invoice',
            label: label('create_invoice'),
            href: createInvoice(
                clientId ? { query: { client_id: clientId } } : undefined,
            ).url,
        });
    }

    if (can('invoices.record_payment')) {
        const invoiceId = contextInvoiceRecordable(ctx);

        actions.push({
            id: 'record_payment',
            label: label('record_payment'),
            href: invoiceId
                ? withQuery(showInvoice(invoiceId).url, { record: 1 })
                : withQuery(invoicesIndex().url, { status: 'outstanding' }),
        });
    }

    if (can('payouts.create')) {
        const vendorId =
            ctx.component === 'vendors/Show' ? contextId(ctx, 'vendor') : null;

        actions.push({
            id: 'new_payout',
            label: label('new_payout'),
            href: createPayout(
                vendorId ? { query: { vendor_id: vendorId } } : undefined,
            ).url,
        });
    }

    if (can('bank_changes.request')) {
        const vendorId =
            ctx.component === 'vendors/Show' ? contextId(ctx, 'vendor') : null;

        actions.push({
            id: 'request_bank_change',
            label: label('request_bank_change'),
            href: vendorId
                ? createBankChange(vendorId).url
                : vendorsIndex().url,
        });
    }

    if (can('imports.run')) {
        actions.push({
            id: 'import_csv',
            label: label('import_csv'),
            href: importsIndex().url,
        });
    }

    return actions;
}

export function pfSearchHits(
    response: PfSearchResponse | null,
    money: (
        value: PfSearchResponse['invoices'][number]['balance_due'],
    ) => string,
    status: (value: string) => string,
    untitled: string,
): PfPaletteHit[] {
    if (!response) {
        return [];
    }

    return [
        ...response.invoices.map((invoice) => ({
            key: `invoice-${invoice.id}`,
            group: 'invoices' as const,
            label: `${invoice.number ?? untitled} · ${invoice.client.name}`,
            hint: `${money(invoice.balance_due)} · ${status(invoice.display_status)}`,
            href: showInvoice(invoice.id).url,
        })),
        ...response.clients.map((client) => ({
            key: `client-${client.id}`,
            group: 'clients' as const,
            label: client.name,
            hint: null,
            href: showClient(client.id).url,
        })),
        ...response.vendors.map((vendor) => ({
            key: `vendor-${vendor.id}`,
            group: 'vendors' as const,
            label: vendor.name,
            hint: null,
            href: showVendor(vendor.id).url,
        })),
        ...response.payouts.map((payout) => ({
            key: `payout-${payout.id}`,
            group: 'payouts' as const,
            label: `${payout.number} · ${payout.vendor.name}`,
            hint: null,
            href: showPayout(payout.id).url,
        })),
    ];
}
