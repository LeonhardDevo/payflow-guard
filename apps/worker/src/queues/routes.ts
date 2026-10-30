import type { JobsOptions } from 'bullmq';
import { CLIENT_MAIL_BACKOFF, CLIENT_MAIL_ATTEMPTS } from './backoff.js';
import { QUEUES, type QueueName } from './names.js';

export const TOPIC_ROUTES: Readonly<Record<string, QueueName>> = Object.freeze({
  'email.send': QUEUES.emails,
  'telegram.notify': QUEUES.telegram,
  'document.uploaded': QUEUES.documents,
  'document.deleted': QUEUES.documents,
  'workspace.reembed': QUEUES.documents,
  'workspace.purge': QUEUES.documents,
  'privacy.export.requested': QUEUES.privacy,
  'privacy.purge.due': QUEUES.privacy,
  'user.purge': QUEUES.privacy,
  'payflow.import.requested': QUEUES.pfImports,
});

export function resolveQueue(topic: string): QueueName | null {
  return Object.hasOwn(TOPIC_ROUTES, topic) ? (TOPIC_ROUTES[topic] ?? null) : null;
}

export function topicsForQueue(queue: QueueName): string[] {
  return Object.entries(TOPIC_ROUTES)
    .filter(([, target]) => target === queue)
    .map(([topic]) => topic);
}

export function jobOptionsFor(topic: string, payload: unknown): Omit<JobsOptions, 'jobId'> | undefined {
  if (topic !== 'email.send' || payload === null || typeof payload !== 'object') return undefined;
  if ((payload as { retry_profile?: unknown }).retry_profile !== 'client_mail') return undefined;
  return { attempts: CLIENT_MAIL_ATTEMPTS, backoff: { type: CLIENT_MAIL_BACKOFF } };
}
