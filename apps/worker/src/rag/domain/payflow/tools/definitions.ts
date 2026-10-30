import type { JsonSchema } from './schema.js';

export interface PayflowToolSpec {
  name: string;
  description: string;
  parameters: JsonSchema & { type: 'object' };
  requiredPermission: string;
  path: string;
}

const CURRENCY: JsonSchema = { type: 'string', pattern: '^[A-Z]{3}$' };

export const PAYFLOW_TOOL_SPECS: readonly PayflowToolSpec[] = [
  {
    name: 'list_overdue_invoices',
    description:
      'List overdue invoices, most overdue first (or by amount). Use for questions about late/overdue invoices or which invoice is most overdue.',
    parameters: {
      type: 'object',
      properties: {
        client_query: { type: 'string' },
        sort: { enum: ['days_overdue', 'amount'], default: 'days_overdue' },
        currency: CURRENCY,
        limit: { type: 'integer', minimum: 1, maximum: 10, default: 5 },
      },
      additionalProperties: false,
    },
    requiredPermission: 'invoices.view',
    path: '/internal/v1/payflow/ai/invoices/overdue',
  },
  {
    name: 'get_invoice',
    description: 'Get one invoice by its number: amounts, due date, payments, reminders, suggested tone and late fee.',
    parameters: {
      type: 'object',
      properties: { number: { type: 'string', minLength: 3, maxLength: 40 } },
      required: ['number'],
      additionalProperties: false,
    },
    requiredPermission: 'invoices.view',
    path: '/internal/v1/payflow/ai/invoices/lookup',
  },
  {
    name: 'get_client_profile',
    description: 'Payment behaviour of a client: late-payer class, average days late, on-time rate, balances, open invoices.',
    parameters: {
      type: 'object',
      properties: { client_query: { type: 'string', minLength: 2 } },
      required: ['client_query'],
      additionalProperties: false,
    },
    requiredPermission: 'clients.view',
    path: '/internal/v1/payflow/ai/clients/profile',
  },
  {
    name: 'get_reminder_schedule',
    description: 'When the next reminder will be sent for a client or invoice, in which tone and why; includes a recommendation.',
    parameters: {
      type: 'object',
      properties: { client_query: { type: 'string' }, invoice_number: { type: 'string' } },
      anyOf: [{ required: ['client_query'] }, { required: ['invoice_number'] }],
      additionalProperties: false,
    },
    requiredPermission: 'invoices.view',
    path: '/internal/v1/payflow/ai/reminders/next',
  },
  {
    name: 'get_contract_terms',
    description:
      'Configured payment terms and late-payment penalty for a client, the linked contract document and the fee accrued now. Always also search the contract document to quote the clause.',
    parameters: {
      type: 'object',
      properties: { client_query: { type: 'string', minLength: 2 } },
      required: ['client_query'],
      additionalProperties: false,
    },
    requiredPermission: 'clients.view',
    path: '/internal/v1/payflow/ai/clients/terms',
  },
  {
    name: 'list_bank_detail_changes',
    description:
      'Recent vendor bank-detail changes with status, risk and verification. Returns masked data only (last 4 digits, whether the holder matches the vendor).',
    parameters: {
      type: 'object',
      properties: {
        vendor_query: { type: 'string' },
        days: { type: 'integer', minimum: 1, maximum: 365, default: 90 },
        status: { enum: ['pending_verification', 'verified', 'rejected', 'any'], default: 'any' },
      },
      additionalProperties: false,
    },
    requiredPermission: 'vendors.view',
    path: '/internal/v1/payflow/ai/vendors/bank-changes',
  },
  {
    name: 'list_pending_approvals',
    description: 'Payouts waiting for approval, with risk and how many approvals are still needed.',
    parameters: { type: 'object', properties: {}, additionalProperties: false },
    requiredPermission: 'payouts.view',
    path: '/internal/v1/payflow/ai/payouts/pending',
  },
  {
    name: 'get_ar_summary',
    description: 'Accounts-receivable summary: outstanding, overdue, aging buckets, DSO, collected this month.',
    parameters: { type: 'object', properties: { currency: CURRENCY }, additionalProperties: false },
    requiredPermission: 'analytics.view',
    path: '/internal/v1/payflow/ai/ar/summary',
  },
  {
    name: 'list_late_payers',
    description: 'Clients who systematically pay late, with average days late.',
    parameters: {
      type: 'object',
      properties: { limit: { type: 'integer', minimum: 1, maximum: 10, default: 5 } },
      additionalProperties: false,
    },
    requiredPermission: 'clients.view',
    path: '/internal/v1/payflow/ai/clients/late-payers',
  },
];

export const PAYFLOW_TOOL_PERMISSIONS: Readonly<Record<string, string>> = Object.freeze(
  Object.fromEntries(PAYFLOW_TOOL_SPECS.map((spec) => [spec.name, spec.requiredPermission])),
);
