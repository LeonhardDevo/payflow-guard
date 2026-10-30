import type { PayflowApi } from '../src/domain/payflow/api.js';
import { PayflowJobs } from '../src/domain/payflow/jobs/handlers.js';
import { MemoryEnqueuer } from '../src/queues/enqueuer.js';
import { describe, expect, it } from 'vitest';
import type { JobDeps } from '../src/jobs/deps.js';
import { createHandlers } from '../src/jobs/index.js';
import { DEFAULT_JOB_OPTIONS, telegramUpdateJobId } from '../src/queues/defaults.js';
import { ALL_QUEUES, JOB_NAMES, QUEUE_SETTINGS, QUEUES } from '../src/queues/names.js';
import { TOPIC_ROUTES, resolveQueue, topicsForQueue } from '../src/queues/routes.js';

describe('topic → queue routing', () => {
  it('routes every foundation topic to its queue', () => {
    expect(resolveQueue('email.send')).toBe('emails');
    expect(resolveQueue('telegram.notify')).toBe('telegram');
    expect(resolveQueue('document.uploaded')).toBe('documents');
    expect(resolveQueue('document.deleted')).toBe('documents');
    expect(resolveQueue('workspace.reembed')).toBe('documents');
  });

  it('returns null for unknown topics and prototype keys', () => {
    expect(resolveQueue('invoice.paid')).toBeNull();
    expect(resolveQueue('toString')).toBeNull();
    expect(resolveQueue('__proto__')).toBeNull();
  });

  it('lists topics per queue', () => {
    expect(topicsForQueue(QUEUES.documents).sort()).toEqual(['document.deleted', 'document.uploaded', 'workspace.purge', 'workspace.reembed']);
    expect(topicsForQueue(QUEUES.privacy).sort()).toEqual(['privacy.export.requested', 'privacy.purge.due', 'user.purge']);
  });

  it('has a handler for every routed topic and for direct queues', () => {
    const payflow = new PayflowJobs({ api: {} as PayflowApi, enqueuer: new MemoryEnqueuer() });
    const handlers = createHandlers({} as JobDeps, payflow.handlers());
    for (const [topic, queue] of Object.entries(TOPIC_ROUTES)) {
      expect(handlers[queue][topic], `handler for ${topic}`).toBeTypeOf('function');
    }
    expect(handlers[QUEUES.stripeEvents][JOB_NAMES.stripeEvent]).toBeTypeOf('function');
    expect(handlers[QUEUES.telegramUpdates][JOB_NAMES.telegramUpdate]).toBeTypeOf('function');
  });

  it('defines settings for every queue and the platform job defaults', () => {
    for (const queue of ALL_QUEUES) expect(QUEUE_SETTINGS[queue].concurrency).toBeGreaterThan(0);
    expect(DEFAULT_JOB_OPTIONS).toMatchObject({
      attempts: 5,
      backoff: { type: 'exponential', delay: 2000 },
      removeOnComplete: 1000,
      removeOnFail: 5000,
    });
  });

  it('derives non-numeric job ids from Telegram update ids', () => {
    expect(telegramUpdateJobId(42)).toBe('tg-update-42');
    expect(/^\d+$/.test(telegramUpdateJobId(42))).toBe(false);
  });
});
