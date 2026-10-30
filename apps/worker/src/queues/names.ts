export const QUEUES = {
  emails: 'emails',
  telegram: 'telegram',
  documents: 'documents',
  stripeEvents: 'stripe-events',
  telegramUpdates: 'telegram-updates',
  privacy: 'privacy',
  billing: 'billing',
  paymentEvents: 'payment-events',
  pfScheduler: 'pf-scheduler',
  pfReminders: 'pf-reminders',
  pfAnalytics: 'pf-analytics',
  pfPayments: 'pf-payments',
  pfImports: 'pf-imports',
} as const;

export type QueueName = (typeof QUEUES)[keyof typeof QUEUES];

export const ALL_QUEUES: readonly QueueName[] = Object.values(QUEUES);

export const JOB_NAMES = {
  stripeEvent: 'stripe.event',
  telegramUpdate: 'telegram.update',
  privacyPurge: 'privacy.purge.due',
  yookassaNotification: 'yookassa.notification',
  stripeAccountEvent: 'stripe.account.event',
  billingRenewals: 'billing.renewals.due',
  paymentsReconcile: 'payments.reconcile.due',
  paymentEvent: 'payment.event',
} as const;

export const QUEUE_SETTINGS: Record<QueueName, { concurrency: number; limiter?: { max: number; duration: number } }> = {
  emails: { concurrency: 5 },
  telegram: { concurrency: 5, limiter: { max: 25, duration: 1000 } },
  documents: { concurrency: 2 },
  'stripe-events': { concurrency: 5 },
  'telegram-updates': { concurrency: 10 },
  privacy: { concurrency: 1 },
  billing: { concurrency: 2 },
  'payment-events': { concurrency: 5 },
  'pf-scheduler': { concurrency: 2 },
  'pf-reminders': { concurrency: 10 },
  'pf-analytics': { concurrency: 2 },
  'pf-payments': { concurrency: 5 },
  'pf-imports': { concurrency: 1 },
};
