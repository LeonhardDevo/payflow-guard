import type { StatusTone } from '@/components/common/StatusBadge.vue';
import type {
    AlertSeverity,
    BankAccountStatus,
    ImportStatus,
    InvoiceDisplayStatus,
    LatePayerClass,
    PaymentStatus,
    PayoutStatus,
    ReminderStatus,
    RiskBand,
    Tone,
    VendorStatus,
} from '@/types/payflow';

export const INVOICE_STATUS_TONE: Record<InvoiceDisplayStatus, StatusTone> = {
    draft: 'neutral',
    open: 'info',
    due_soon: 'warning',
    overdue: 'destructive',
    partially_paid: 'ai',
    paid: 'success',
    disputed: 'warning',
    uncollectible: 'neutral',
    void: 'neutral',
};

export const RISK_TONE: Record<RiskBand, StatusTone> = {
    low: 'success',
    medium: 'warning',
    high: 'destructive',
};

export const CLASS_TONE: Record<LatePayerClass, StatusTone> = {
    new: 'info',
    reliable: 'success',
    occasional: 'warning',
    chronic: 'destructive',
};

export const REMINDER_STATUS_TONE: Record<ReminderStatus, StatusTone> = {
    scheduled: 'info',
    queued: 'info',
    deferred: 'warning',
    sent: 'success',
    failed: 'destructive',
    skipped: 'neutral',
    canceled: 'neutral',
};

export const PAYMENT_STATUS_TONE: Record<PaymentStatus, StatusTone> = {
    pending: 'info',
    succeeded: 'success',
    failed: 'destructive',
    refunded: 'neutral',
    partially_refunded: 'warning',
    reversed: 'neutral',
};

export const TONE_CLASS: Record<Tone, string> = {
    friendly: 'bg-success-soft text-success-soft-foreground',
    neutral: 'bg-info-soft text-info-soft-foreground',
    firm: 'bg-warning-soft text-warning-soft-foreground',
    final: 'bg-destructive-soft text-destructive-soft-foreground',
};

export const INVOICE_STATUSES: InvoiceDisplayStatus[] = [
    'draft',
    'open',
    'due_soon',
    'overdue',
    'partially_paid',
    'paid',
    'disputed',
    'uncollectible',
    'void',
];

export const LATE_PAYER_CLASSES: LatePayerClass[] = [
    'new',
    'reliable',
    'occasional',
    'chronic',
];

export const TONES: Tone[] = ['friendly', 'neutral', 'firm', 'final'];

export const IMPORT_STATUS_TONE: Record<ImportStatus, StatusTone> = {
    uploaded: 'neutral',
    mapping: 'info',
    queued: 'info',
    processing: 'ai',
    completed: 'success',
    completed_with_errors: 'warning',
    failed: 'destructive',
};

export const PAYOUT_STATUS_TONE: Record<PayoutStatus, StatusTone> = {
    draft: 'neutral',
    pending_approval: 'info',
    approved: 'ai',
    on_hold: 'warning',
    released: 'success',
    rejected: 'destructive',
    canceled: 'neutral',
};

export const BANK_STATUS_TONE: Record<BankAccountStatus, StatusTone> = {
    pending_verification: 'warning',
    verified: 'success',
    rejected: 'destructive',
    superseded: 'neutral',
};

export const VENDOR_STATUS_TONE: Record<VendorStatus, StatusTone> = {
    active: 'success',
    on_hold: 'warning',
    archived: 'neutral',
};

export const SEVERITY_TONE: Record<AlertSeverity, StatusTone> = {
    info: 'info',
    warning: 'warning',
    critical: 'destructive',
};
