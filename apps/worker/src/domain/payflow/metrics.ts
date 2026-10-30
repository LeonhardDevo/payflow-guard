import { Counter, Histogram, type Registry } from 'prom-client';

export class PayflowMetrics {
  readonly remindersDispatched: Counter<'status'>;
  readonly dispatchLag: Histogram;
  readonly clientMailDeferred: Counter;
  readonly paymentSyncRecorded: Counter;

  constructor(registry: Registry) {
    this.remindersDispatched = new Counter({
      name: 'pf_reminders_dispatched_total',
      help: 'Reminder dispatch outcomes reported by the web dispatcher.',
      labelNames: ['status'],
      registers: [registry],
    });
    this.dispatchLag = new Histogram({
      name: 'pf_reminder_dispatch_lag_seconds',
      help: 'Seconds between the planned send slot and the actual dispatch of a queued reminder.',
      buckets: [1, 5, 15, 30, 60, 120, 300, 900, 1800, 3600, 7200],
      registers: [registry],
    });
    this.clientMailDeferred = new Counter({
      name: 'pf_client_mail_deferred_total',
      help: 'Client e-mails deferred for another attempt after a retryable delivery failure.',
      registers: [registry],
    });
    this.paymentSyncRecorded = new Counter({
      name: 'pf_payment_sync_recorded_total',
      help: 'Payments recorded by the payment-link reconciliation job.',
      registers: [registry],
    });
  }
}
