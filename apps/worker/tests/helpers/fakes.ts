import { vi } from 'vitest';
import type { AiApi, ChatResponse } from '../../src/clients/ai.js';
import type { TelegramContext, WebApi } from '../../src/clients/web.js';

export function linkedContext(overrides: Partial<TelegramContext> = {}): TelegramContext {
  return {
    user: { id: 7, name: 'Anna Petrova', locale: 'ru' },
    workspace: { id: 3, name: 'Demo Studio', plan: 'pro', ai_external_allowed: false },
    role: 'admin',
    permissions: ['documents.view', 'invoices.approve'],
    telegram: { user_id: 5001, chat_id: 5001, username: 'anna' },
    ...overrides,
  };
}

export function chatResponse(overrides: Partial<ChatResponse> = {}): ChatResponse {
  return {
    answer_markdown: 'Refunds are processed within **5 days** [1].',
    answer_html: '<p>Refunds are processed within <strong>5 days</strong> [1].</p>',
    citations: [
      { document_id: 11, title: 'Refund policy', page: 2, snippet: 'within 5 days', url: '/knowledge/11' },
      { document_id: 12, title: 'Billing FAQ', page: null, snippet: null, url: 'https://docs.example.com/faq' },
    ],
    confidence: 0.82,
    refused: false,
    tools_used: ['search_documents'],
    actions: [],
    provider: 'mock',
    model: 'mock-1',
    latency_ms: 42,
    ...overrides,
  };
}

export function fakeWeb(contexts: Map<number, TelegramContext> = new Map()) {
  return {
    ping: vi.fn<WebApi['ping']>(async () => true),
    linkTelegram: vi.fn<WebApi['linkTelegram']>(async () => ({ ok: true, userName: 'Anna Petrova', workspaceName: 'Demo Studio', locale: 'ru' })),
    telegramContext: vi.fn<WebApi['telegramContext']>(async (lookup) => {
      if ('telegramUserId' in lookup) return contexts.get(lookup.telegramUserId) ?? null;
      for (const context of contexts.values()) if (context.user.id === lookup.userId) return context;
      return null;
    }),
    reportDelivery: vi.fn<WebApi['reportDelivery']>(async () => undefined),
    forwardStripeEvent: vi.fn<WebApi['forwardStripeEvent']>(async () => undefined),
    reportDocumentStatus: vi.fn<WebApi['reportDocumentStatus']>(async () => undefined),
    logAiQuery: vi.fn<WebApi['logAiQuery']>(async () => undefined),
    telegramAction: vi.fn<WebApi['telegramAction']>(async () => ({ ok: true, message: 'Approved', message_html: null })),
    unlinkTelegram: vi.fn<WebApi['unlinkTelegram']>(async () => true),
    privacyPurge: vi.fn<WebApi['privacyPurge']>(async () => ({
      deletions: { users: 1, workspaces: 0, blocked: 0, failed: 0 },
      retention: { ai_queries: 4 },
      webhook_events_days: 30,
      skipped: false,
    })),
    buildExport: vi.fn<WebApi['buildExport']>(async (id) => ({ id, status: 'ready' })),
    forwardYooKassaNotification: vi.fn<WebApi['forwardYooKassaNotification']>(async () => undefined),
    forwardStripeAccountEvent: vi.fn<WebApi['forwardStripeAccountEvent']>(async () => undefined),
    paymentAccountSecret: vi.fn<WebApi['paymentAccountSecret']>(async () => null),
    paymentEndpoint: vi.fn<WebApi['paymentEndpoint']>(async () => null),
    forwardPaymentEvent: vi.fn<WebApi['forwardPaymentEvent']>(async () => ({ result: 'processed' as const })),
    runBillingRenewals: vi.fn<WebApi['runBillingRenewals']>(async () => ({ due: 0 })),
    reconcilePayments: vi.fn<WebApi['reconcilePayments']>(async () => ({ checked: 0 })),
    fetchAttachment: vi.fn<WebApi['fetchAttachment']>(async () => ({ content: Buffer.from('%PDF-1.7 demo'), contentType: 'application/pdf' })),
  } satisfies WebApi;
}

export function fakeAi(response: ChatResponse = chatResponse()) {
  return {
    chat: vi.fn<AiApi['chat']>(async () => response),
    ingestDocument: vi.fn<AiApi['ingestDocument']>(async () => ({ pages: 3, chunks: 12, ocr_used: false })),
    deleteDocument: vi.fn<AiApi['deleteDocument']>(async () => undefined),
    reembedWorkspace: vi.fn<AiApi['reembedWorkspace']>(async () => undefined),
    purgeWorkspace: vi.fn<AiApi['purgeWorkspace']>(async () => undefined),
    purgeUser: vi.fn<AiApi['purgeUser']>(async () => undefined),
    pruneMemory: vi.fn<AiApi['pruneMemory']>(async () => undefined),
  } satisfies AiApi;
}
