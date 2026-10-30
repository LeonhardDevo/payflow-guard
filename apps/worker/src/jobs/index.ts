import { JOB_NAMES, QUEUES, type QueueName } from '../queues/names.js';
import {
  createBillingRenewalsHandler,
  createPaymentsReconcileHandler,
  createStripeAccountEventHandler,
  createYooKassaNotificationHandler,
} from './billing.js';
import type { JobDeps } from './deps.js';
import { createDocumentDeletedHandler, createDocumentUploadedHandler, createWorkspaceReembedHandler } from './documents.js';
import { createEmailHandler } from './email.js';
import { createPaymentEventHandler } from './paymentEvents.js';
import { createExportRequestedHandler, createPrivacyPurgeHandler, createUserPurgeHandler, createWorkspacePurgeHandler } from './privacy.js';
import { createStripeEventHandler } from './stripeEvents.js';
import { createTelegramNotifyHandler } from './telegramNotify.js';
import { createTelegramUpdateHandler } from './telegramUpdates.js';
import type { HandlerMap } from './types.js';

export function createHandlers(deps: JobDeps, domain: Partial<Record<QueueName, HandlerMap>> = {}): Record<QueueName, HandlerMap> {
  const handlers: Record<QueueName, HandlerMap> = {
    [QUEUES.emails]: { 'email.send': createEmailHandler(deps) },
    [QUEUES.telegram]: { 'telegram.notify': createTelegramNotifyHandler(deps) },
    [QUEUES.documents]: {
      'document.uploaded': createDocumentUploadedHandler(deps),
      'document.deleted': createDocumentDeletedHandler(deps),
      'workspace.reembed': createWorkspaceReembedHandler(deps),
      'workspace.purge': createWorkspacePurgeHandler(deps),
    },
    [QUEUES.stripeEvents]: { [JOB_NAMES.stripeEvent]: createStripeEventHandler(deps) },
    [QUEUES.telegramUpdates]: { [JOB_NAMES.telegramUpdate]: createTelegramUpdateHandler(deps) },
    [QUEUES.privacy]: {
      'privacy.export.requested': createExportRequestedHandler(deps),
      'user.purge': createUserPurgeHandler(deps),
      [JOB_NAMES.privacyPurge]: createPrivacyPurgeHandler(deps),
    },
    [QUEUES.billing]: {
      [JOB_NAMES.yookassaNotification]: createYooKassaNotificationHandler(deps),
      [JOB_NAMES.stripeAccountEvent]: createStripeAccountEventHandler(deps),
      [JOB_NAMES.billingRenewals]: createBillingRenewalsHandler(deps),
      [JOB_NAMES.paymentsReconcile]: createPaymentsReconcileHandler(deps),
    },
    [QUEUES.paymentEvents]: { [JOB_NAMES.paymentEvent]: createPaymentEventHandler(deps) },
    [QUEUES.pfScheduler]: {},
    [QUEUES.pfReminders]: {},
    [QUEUES.pfAnalytics]: {},
    [QUEUES.pfPayments]: {},
    [QUEUES.pfImports]: {},
  };
  for (const [queue, map] of Object.entries(domain) as Array<[QueueName, HandlerMap | undefined]>) {
    if (!map) continue;
    for (const [name, handler] of Object.entries(map)) {
      if (Object.hasOwn(handlers[queue], name)) throw new Error(`Job handler "${name}" on queue "${queue}" registered twice`);
      handlers[queue][name] = handler;
    }
  }
  return handlers;
}

export type { JobDeps, TelegramSender } from './deps.js';
export type { HandlerMap, JobContext, JobHandler } from './types.js';
