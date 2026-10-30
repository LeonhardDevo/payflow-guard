export type Locale = 'en' | 'ru';
export type CurrencyCode = string;
export type ISODate = string;
export type ISODateTime = string;
export type Money = { minor: number; currency: CurrencyCode };
export type UserRef = { id: number; name: string };
export type Option<V extends string | number = string> = {
    value: V;
    label: string;
};
export type Params = Record<string, string | number>;
export type Reason = { code: string; params: Params };
export type Signal = { code: string; points: number; params: Params };
export type RiskFactor = {
    code: string;
    weight: number;
    value: number;
    contribution: number;
    params: Params;
};
export type Paginated<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    meta: {
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
    };
};

export type WorkspaceRole =
    | 'owner'
    | 'admin'
    | 'finance'
    | 'approver'
    | 'member'
    | 'external_approver';
export type InvoiceStatus =
    | 'draft'
    | 'open'
    | 'partially_paid'
    | 'paid'
    | 'disputed'
    | 'uncollectible'
    | 'void';
export type InvoiceDisplayStatus =
    | 'draft'
    | 'open'
    | 'due_soon'
    | 'overdue'
    | 'partially_paid'
    | 'paid'
    | 'disputed'
    | 'uncollectible'
    | 'void';
export type RiskBand = 'low' | 'medium' | 'high';
export type Tone = 'friendly' | 'neutral' | 'firm' | 'final';
export type StepTone = Tone | 'auto';
export type ToneCeiling = 'none' | 'neutral' | 'firm';
export type LatePayerClass = 'new' | 'reliable' | 'occasional' | 'chronic';
export type AgingBucket =
    | 'current'
    | 'd1_30'
    | 'd31_60'
    | 'd61_90'
    | 'd90_plus';
export type LateFeeType =
    | 'none'
    | 'percent_monthly'
    | 'percent_daily'
    | 'fixed';
export type PaymentMethod =
    | 'payment_link'
    | 'bank_transfer'
    | 'card'
    | 'cash'
    | 'other';
export type PaymentStatus =
    | 'pending'
    | 'succeeded'
    | 'failed'
    | 'refunded'
    | 'partially_refunded'
    | 'reversed';
export type PaymentDriver = 'fake' | 'stripe' | 'yookassa';
export type PaymentProvider = 'manual' | PaymentDriver;
export type PaymentAccountStatus =
    | 'onboarding'
    | 'active'
    | 'restricted'
    | 'disconnected';
export type ClientPauseSource = 'manual' | 'recipient_opt_out';
export type InvoicePauseSource =
    | 'manual'
    | 'import_review'
    | 'client_reported_paid';
export type ReminderStatus =
    | 'scheduled'
    | 'queued'
    | 'deferred'
    | 'sent'
    | 'failed'
    | 'skipped'
    | 'canceled';
export type ReminderSkipReason =
    | 'invoice_paid'
    | 'invoice_void'
    | 'invoice_uncollectible'
    | 'invoice_disputed'
    | 'invoice_paused'
    | 'client_paused'
    | 'recipient_opt_out'
    | 'no_recipient'
    | 'superseded'
    | 'limit_reached'
    | 'policy_changed';
export type TemplateKey =
    | 'invoice_issued'
    | 'reminder_friendly'
    | 'reminder_neutral'
    | 'reminder_firm'
    | 'reminder_final'
    | 'payment_receipt';
export type VendorCategory =
    | 'contractor'
    | 'freelancer'
    | 'agency'
    | 'saas'
    | 'other';
export type VendorStatus = 'active' | 'on_hold' | 'archived';
export type BankScheme = 'iban' | 'us_ach' | 'local';
export type BankAccountStatus =
    | 'pending_verification'
    | 'verified'
    | 'rejected'
    | 'superseded';
export type BankChangeSource =
    | 'onboarding'
    | 'vendor_email'
    | 'vendor_phone'
    | 'vendor_portal'
    | 'invoice_document'
    | 'manual_entry'
    | 'import';
export type VerificationMethod =
    | 'callback_known_phone'
    | 'in_person'
    | 'signed_letter'
    | 'video_call'
    | 'vendor_portal_2fa';
export type VerificationMode = 'standard' | 'solo';
export type PayoutStatus =
    | 'draft'
    | 'pending_approval'
    | 'approved'
    | 'on_hold'
    | 'released'
    | 'rejected'
    | 'canceled';
export type PayoutHoldReason =
    | 'bank_details_changed'
    | 'bank_details_unverified'
    | 'vendor_on_hold'
    | 'approver_removed';
export type ApprovalChannel = 'web' | 'telegram' | 'trusted_contact';
export type StepUpMethod = 'passkey' | 'totp' | 'password';
export type AlertSeverity = 'info' | 'warning' | 'critical';
export type AlertStatus = 'open' | 'acknowledged' | 'resolved';
export type ImportKind = 'clients' | 'invoices';
export type ImportPreset = 'generic' | 'quickbooks' | 'xero' | 'freshbooks';
export type ImportStatus =
    | 'uploaded'
    | 'mapping'
    | 'queued'
    | 'processing'
    | 'completed'
    | 'completed_with_errors'
    | 'failed';
export type ImportActivationStatus = 'pending' | 'activated' | 'dismissed';
export type NotificationEvent =
    | 'payment_received'
    | 'reminder_failed'
    | 'collections_escalation'
    | 'reminders_opted_out'
    | 'client_reported_paid'
    | 'bank_change_requested'
    | 'bank_change_high_risk'
    | 'payout_pending_approval'
    | 'payout_decided'
    | 'payout_released'
    | 'guard_settings_changed'
    | 'guard_role_granted'
    | 'receiving_details_change'
    | 'payment_account_issue'
    | 'daily_digest';

export type LateFeeTerms = {
    type: LateFeeType;
    rate_bp: number | null;
    fixed: Money | null;
    grace_days: number;
    cap_bp: number | null;
};
export type ClientRef = {
    id: number;
    name: string;
    late_payer_class: LatePayerClass;
};
export type ClientOption = ClientRef & {
    currency: CurrencyCode;
    locale: Locale;
    email: string | null;
    payment_terms_days: number;
};
export type ClientListItem = ClientRef & {
    legal_name: string | null;
    email: string | null;
    country: string | null;
    currency: CurrencyCode;
    locale: Locale;
    owner: UserRef | null;
    avg_days_late: number;
    on_time_rate: number | null;
    paid_invoices_count: number;
    open_balance: Money;
    overdue_balance: Money;
    open_invoices_count: number;
    reminders_paused: boolean;
    reminders_pause_source: ClientPauseSource | null;
    tags: string[];
};
export type ClientDetail = ClientListItem & {
    contact_name: string | null;
    cc_emails: string[];
    phone: string | null;
    tax_id: string | null;
    address: string | null;
    timezone: string | null;
    effective_timezone: string;
    payment_terms_days: number | null;
    effective_payment_terms_days: number;
    reminder_policy: { id: number; name: string; inherited: boolean };
    tone_ceiling: ToneCeiling;
    reminders_paused_until: ISODate | null;
    pause_reason: string | null;
    opted_out_at: ISODateTime | null;
    late_fee: LateFeeTerms & { inherited: boolean };
    contract_document: {
        id: number;
        title: string;
        status: 'queued' | 'processing' | 'indexed' | 'failed';
    } | null;
    notes: string | null;
    late_invoices_count: number;
    stats_computed_at: ISODateTime | null;
    anonymised_at: ISODateTime | null;
    created_at: ISODateTime;
};
export type InvoiceRiskBrief = { score: number; band: RiskBand };
export type InvoiceListItem = {
    id: number;
    number: string | null;
    client: ClientRef;
    status: InvoiceStatus;
    display_status: InvoiceDisplayStatus;
    is_partially_paid: boolean;
    issue_date: ISODate;
    due_date: ISODate;
    days_overdue: number;
    days_until_due: number | null;
    total: Money;
    amount_paid: Money;
    balance_due: Money;
    risk: InvoiceRiskBrief | null;
    next_reminder_at: ISODateTime | null;
    last_reminder_at: ISODateTime | null;
    reminders_sent_count: number;
    reminders_paused: boolean;
    reminders_pause_source: InvoicePauseSource | null;
    client_reported_paid_at: ISODateTime | null;
    first_viewed_at: ISODateTime | null;
    source: 'manual' | 'import';
};
export type InvoiceItem = {
    id: number;
    position: number;
    kind: 'service' | 'late_fee';
    description: string;
    quantity: string;
    unit_price: Money;
    tax_rate_bp: number;
    amount: Money;
    tax: Money;
};
export type InvoiceRisk = InvoiceRiskBrief & {
    factors: RiskFactor[];
    expected_paid_on: ISODate | null;
    computed_at: ISODateTime;
};
export type PaymentLinkInfo = {
    id: number;
    driver: PaymentDriver;
    kind: 'reusable' | 'single';
    url: string;
    amount: Money;
    active: boolean;
    expires_at: ISODateTime | null;
    created_at: ISODateTime;
};
export type PayableReason =
    | 'no_account'
    | 'currency_not_supported'
    | 'account_restricted'
    | 'not_open'
    | null;
export type InvoiceDetail = Omit<InvoiceListItem, 'risk' | 'client'> & {
    client: ClientRef & {
        email: string | null;
        locale: Locale;
        tone_ceiling: ToneCeiling;
        reminders_paused: boolean;
        reminders_pause_source: ClientPauseSource | null;
    };
    locale: Locale;
    currency: CurrencyCode;
    po_number: string | null;
    notes: string | null;
    terms: string | null;
    items: InvoiceItem[];
    subtotal: Money;
    tax: Money;
    late_fee_applied: Money;
    public_url: string;
    pdf_url: string;
    sent_at: ISODateTime | null;
    first_viewed_at: ISODateTime | null;
    last_viewed_at: ISODateTime | null;
    view_count: number;
    paid_at: ISODateTime | null;
    voided_at: ISODateTime | null;
    void_reason: string | null;
    written_off_at: ISODateTime | null;
    write_off_reason: string | null;
    disputed_at: ISODateTime | null;
    dispute_reason: string | null;
    dispute_resolved_at: ISODateTime | null;
    reminders_paused_until: ISODate | null;
    client_reported_reference: string | null;
    first_contact_confirmed_at: ISODateTime | null;
    risk: InvoiceRisk | null;
    payment_link: PaymentLinkInfo | null;
    payable_reason: PayableReason;
    created_by: UserRef | null;
    created_at: ISODateTime;
};
export type ToneSuggestion = { tone: Tone; reasons: Reason[] };
export type LateFeeSuggestion = {
    amount: Money;
    chargeable_days: number;
    terms: LateFeeTerms;
    source: 'client' | 'workspace';
    already_applied: Money;
};
export type PaymentRow = {
    id: number;
    amount: Money;
    refunded: Money;
    method: PaymentMethod;
    status: PaymentStatus;
    provider: PaymentProvider;
    paid_at: ISODateTime;
    reference: string | null;
    note: string | null;
    recorded_by: UserRef | null;
    can_reverse: boolean;
};
export type ReminderRow = {
    id: number;
    kind: 'scheduled' | 'manual';
    tone: Tone;
    tone_reasons: Reason[];
    status: ReminderStatus;
    skip_reason: ReminderSkipReason | null;
    scheduled_for: ISODateTime;
    sent_at: ISODateTime | null;
    failed_at: ISODateTime | null;
    next_retry_at: ISODateTime | null;
    delivery_attempts: number;
    error: string | null;
    recipient_email: string | null;
    subject: string | null;
    step_offset_days: number | null;
    is_first_contact: boolean;
    created_by: UserRef | null;
};
export type ReminderListItem = ReminderRow & {
    invoice: {
        id: number;
        number: string | null;
        balance_due: Money;
        days_overdue: number;
    };
    client: ClientRef;
};
export type ReminderPreview = {
    tone: Tone;
    reasons: Reason[];
    subject: string;
    body_markdown: string;
    body_html: string;
    recipient: string | null;
    cc: string[];
    late_fee: Money | null;
    warnings: Array<
        | 'no_recipient'
        | 'recent_reminder'
        | 'paused'
        | 'recipient_opted_out'
        | 'client_reported_paid'
        | 'no_payment_link'
        | 'first_contact_capped'
    >;
};
export type AiDraftResponse = {
    subject: string;
    body_markdown: string;
    fallback: boolean;
    provider: string | null;
    model: string | null;
};
export type TimelineEvent = {
    id: number;
    type: string;
    actor: {
        type: 'user' | 'system' | 'client' | 'provider';
        user: UserRef | null;
    };
    data: Record<string, string | number | boolean | null>;
    occurred_at: ISODateTime;
};

export type VendorRef = { id: number; name: string; status: VendorStatus };
export type BankAccountView = {
    id: number;
    version: number;
    scheme: BankScheme;
    holder_name: string;
    bank_name: string | null;
    bank_country: string;
    currency: CurrencyCode;
    last4: string;
    display: string;
    swift_bic_masked: string | null;
    status: BankAccountStatus;
    source: BankChangeSource;
    requester_email_domain: string | null;
    risk_score: number;
    risk_band: RiskBand;
    risk_signals: Signal[];
    requested_by: UserRef;
    requested_at: ISODateTime;
    verified_by: UserRef | null;
    verified_at: ISODateTime | null;
    verification_method: VerificationMethod | null;
    verification_mode: VerificationMode;
    verification_note: string | null;
    cooling_off_until: ISODateTime | null;
    rejected_by: UserRef | null;
    rejected_at: ISODateTime | null;
    rejection_reason: string | null;
    superseded_at: ISODateTime | null;
};
export type VendorListItem = VendorRef & {
    legal_name: string | null;
    category: VendorCategory;
    country: string | null;
    default_currency: CurrencyCode;
    current_account: Pick<
        BankAccountView,
        | 'id'
        | 'display'
        | 'bank_country'
        | 'currency'
        | 'status'
        | 'cooling_off_until'
    > | null;
    pending_change: {
        id: number;
        risk_band: RiskBand;
        risk_score: number;
        requested_at: ISODateTime;
    } | null;
    open_payouts_count: number;
    last_released_at: ISODateTime | null;
};
export type VendorDetail = VendorListItem & {
    email: string | null;
    email_domain: string | null;
    phone: string | null;
    phone_verified_at: ISODateTime | null;
    tax_id_masked: string | null;
    notes: string | null;
    hold_reason: string | null;
    anonymised_at: ISODateTime | null;
    created_by: UserRef | null;
    created_at: ISODateTime;
};
export type BankDiffRow = {
    field:
        | 'scheme'
        | 'holder_name'
        | 'account'
        | 'bank_name'
        | 'bank_country'
        | 'currency'
        | 'swift_bic';
    before: string | null;
    after: string | null;
    changed: boolean;
};
export type PayoutListItem = {
    id: number;
    number: string;
    vendor: VendorRef;
    amount: Money;
    description: string;
    vendor_invoice_number: string | null;
    due_date: ISODate | null;
    status: PayoutStatus;
    hold_reason: PayoutHoldReason | null;
    risk: { score: number; band: RiskBand } | null;
    required_approvals: number;
    approvals_count: number;
    auto_approved: boolean;
    requested_by: UserRef;
    submitted_at: ISODateTime | null;
    approved_at: ISODateTime | null;
    released_at: ISODateTime | null;
    created_at: ISODateTime;
    awaiting_me: boolean;
};
export type ApprovalRow = {
    id: number;
    user: UserRef | null;
    trusted_contact: { name: string } | null;
    decision: 'approved' | 'rejected';
    channel: ApprovalChannel;
    comment: string | null;
    risk_band: RiskBand;
    approval_round: number;
    step_up_method: StepUpMethod | 'none' | null;
    created_at: ISODateTime;
    invalidated_at: ISODateTime | null;
};
export type PayoutCheck = {
    code:
        | 'vendor_active'
        | 'details_verified'
        | 'details_current'
        | 'not_in_cooling_off'
        | 'no_duplicates'
        | 'no_split'
        | 'approvals_complete'
        | 'release_delay_passed'
        | 'releaser_differs';
    ok: boolean;
    params: Params;
};
export type EligibleApprover = UserRef & {
    role: WorkspaceRole;
    decided: boolean;
    eligible: boolean;
    eligible_from: ISODateTime | null;
    missing_second_factor: boolean;
};
export type TrustedConfirmationInfo = {
    id: number;
    contact_name: string;
    status: 'pending' | 'confirmed' | 'declined' | 'expired' | 'canceled';
    expires_at: ISODateTime;
    decided_at: ISODateTime | null;
};
export type PayoutDetail = PayoutListItem & {
    bank_account: BankAccountView | null;
    current_account_matches: boolean;
    risk_signals: Signal[];
    telegram_allowed: boolean;
    approval_round: number;
    approvals: ApprovalRow[];
    eligible_approvers: EligibleApprover[];
    trusted_confirmation: TrustedConfirmationInfo | null;
    release_requires_other_user: boolean;
    release_not_before: ISODateTime | null;
    split_group_total: Money | null;
    checks: PayoutCheck[];
    paid_reference: string | null;
    paid_on: ISODate | null;
    released_by: UserRef | null;
    rejected_at: ISODateTime | null;
    canceled_at: ISODateTime | null;
    canceled_by: UserRef | null;
    cancel_reason: string | null;
    timeline: TimelineEvent[];
};
export type GuardAlertItem = {
    id: number;
    type: string;
    severity: AlertSeverity;
    status: AlertStatus;
    subject: { type: string; id: number; label: string; url: string | null };
    data: Params;
    created_at: ISODateTime;
    acknowledged_by: UserRef | null;
};

export type AgingRow = { bucket: AgingBucket; amount: Money; count: number };
export type ForecastWeek = {
    week_start: ISODate;
    expected: Money;
    at_risk: Money;
    invoices: number;
};
export type MonthlyPoint = {
    month: string;
    collected: Money;
    invoiced: Money;
    on_time_rate: number | null;
};
export type DsoPoint = { date: ISODate; days: number | null };
export type LatePayerRow = {
    client: ClientRef;
    avg_days_late: number;
    on_time_rate: number | null;
    open_balance: Money;
    overdue_balance: Money;
    last_payment_at: ISODateTime | null;
};
export type ToneEffectiveness = {
    tone: Tone;
    sent: number;
    paid_within_7d: number;
    rate: number | null;
};
export type UpcomingReminder = {
    id: number;
    scheduled_for: ISODateTime;
    tone: Tone;
    invoice: { id: number; number: string | null; balance_due: Money };
    client: ClientRef;
};
export type Insight = {
    code:
        | 'bank_change_pending'
        | 'chronic_client_overdue'
        | 'forecast_gap'
        | 'unviewed_invoices'
        | 'reminders_failed';
    severity: AlertSeverity;
    params: Params;
    action: { label_key: string; href: string } | null;
};

export type ReminderPolicyStep = {
    id: number | null;
    position: number;
    offset_days: number;
    tone: StepTone;
    attach_pdf: boolean;
    include_payment_link: boolean;
    include_late_fee_notice: boolean;
    notify_owner: boolean;
};
export type ReminderPolicy = {
    id: number;
    name: string;
    description: string | null;
    is_default: boolean;
    max_reminders: number;
    min_interval_days: number;
    escalate_after_days: number;
    steps: ReminderPolicyStep[];
    clients_count: number;
};
export type MessageTemplate = {
    id: number;
    key: TemplateKey;
    locale: Locale;
    subject: string;
    body_markdown: string;
    is_customized: boolean;
    updated_by: UserRef | null;
    updated_at: ISODateTime;
};
export type ReceivingDetailsVersion = {
    id: number;
    version: number;
    details: string;
    status:
        | 'pending_confirmation'
        | 'scheduled'
        | 'active'
        | 'superseded'
        | 'rejected'
        | 'canceled';
    requested_by: UserRef;
    requested_at: ISODateTime;
    confirmation_mode:
        | 'second_person'
        | 'trusted_contact'
        | 'unconfirmed_solo'
        | null;
    confirmed_by: UserRef | null;
    confirmed_at: ISODateTime | null;
    effective_at: ISODateTime | null;
};
export type CollectionsSettings = {
    invoice_prefix: string;
    next_invoice_seq: number;
    default_payment_terms_days: number;
    default_reminder_policy_id: number | null;
    send_window_start: string;
    send_window_end: string;
    business_days_only: boolean;
    first_contact_max_per_hour: number;
    sender_display_name: string | null;
    reply_to_email: string | null;
    privacy_contact_email: string | null;
    track_invoice_views: boolean;
    late_fee: LateFeeTerms;
    company: {
        legal_name: string | null;
        address: string | null;
        tax_id: string | null;
        email: string | null;
        phone: string | null;
    };
    receiving_details: {
        active: ReceivingDetailsVersion | null;
        pending: ReceivingDetailsVersion | null;
    };
    digest_enabled: boolean;
    digest_hour: number;
};
export type GuardThreshold = {
    currency: CurrencyCode;
    auto_approve_below: Money;
    dual_approval_from: Money;
    auto_approve_cumulative: Money | null;
};
export type TrustedContact = {
    id: number;
    name: string;
    email_masked: string;
    locale: Locale;
    status: 'pending_verification' | 'active' | 'revoked';
    verified_at: ISODateTime | null;
    active_from: ISODateTime | null;
    created_by: UserRef;
};
export type GuardSettings = {
    thresholds: GuardThreshold[];
    cooling_off_hours_medium: number;
    cooling_off_hours_high: number;
    allow_telegram_approvals: boolean;
    escalate_pending_after_hours: number;
    guard_trust_delay_hours: number;
    require_second_factor: boolean;
    solo_release_delay_hours: number;
};
export type PaymentAccount = {
    driver: PaymentDriver;
    mode: 'test' | 'live';
    status: PaymentAccountStatus;
    display_name: string | null;
    key_hint: string | null;
    country: string | null;
    charges_enabled: boolean;
    webhook_url_masked: string | null;
    missing_permissions: string[];
    supported_currencies: CurrencyCode[];
    last_event_at: ISODateTime | null;
    last_error: string | null;
    connected_by: UserRef;
    created_at: ISODateTime;
};
export type NotificationPreferences = {
    telegram_events: NotificationEvent[];
    email_events: NotificationEvent[];
    digest_channel: 'telegram' | 'email' | 'none';
    quiet_hours_start: string | null;
    quiet_hours_end: string | null;
};

export type ImportListItem = {
    id: number;
    kind: ImportKind;
    preset: ImportPreset;
    status: ImportStatus;
    original_name: string;
    rows_total: number;
    rows_imported: number;
    rows_skipped: number;
    rows_failed: number;
    activation_status: ImportActivationStatus;
    created_by: UserRef;
    created_at: ISODateTime;
    finished_at: ISODateTime | null;
};
export type ImportDetail = ImportListItem & {
    headers: string[];
    sample_rows: string[][];
    mapping: Record<string, string | null>;
    suggested_mapping: Record<string, string | null>;
    fields: Array<{ key: string; required: boolean }>;
    options: {
        default_currency: CurrencyCode;
        date_format: 'Y-m-d' | 'm/d/Y' | 'd/m/Y' | 'd.m.Y';
        create_missing_clients: boolean;
        mark_as_sent: boolean;
    };
    errors: Array<{ row: number; field: string | null; code: string }>;
    progress: number;
};
export type ImportActivationPreview = {
    invoices: number;
    overdue: number;
    beyond_escalation: number;
    tones_engine: Record<Tone, number>;
    tones_capped: Record<Tone, number>;
    first_slots: Array<{ hour_start: ISODateTime; count: number }>;
    samples: Array<{
        tone: Tone;
        locale: Locale;
        subject: string;
        body_html: string;
    }>;
};

export type PublicInvoice = {
    number: string;
    status: InvoiceStatus;
    display_status: InvoiceDisplayStatus;
    issue_date: ISODate;
    due_date: ISODate;
    days_overdue: number;
    locale: Locale;
    currency: CurrencyCode;
    po_number: string | null;
    notes: string | null;
    terms: string | null;
    items: Array<{
        description: string;
        quantity: string;
        unit_price: Money;
        amount: Money;
        tax: Money;
        tax_rate_bp: number;
    }>;
    subtotal: Money;
    tax: Money;
    total: Money;
    amount_paid: Money;
    balance_due: Money;
    paid_at: ISODateTime | null;
};
export type PublicLegal = {
    privacy_contact: string;
    reminder_recipients_url: string;
    choices_url: string;
    terms_url: string;
    privacy_url: string;
};

export type PfShared = {
    nav_counts: {
        overdue_invoices: number;
        failed_reminders: number;
        critical_alerts: number;
        my_approvals: number;
        pending_bank_changes: number;
    };
    payment_account: {
        driver: 'fake' | 'stripe' | 'yookassa';
        mode: 'test' | 'live';
        status: 'onboarding' | 'active' | 'restricted' | 'disconnected';
        charges_enabled: boolean;
    } | null;
    entitlements: Record<
        | 'payment_links'
        | 'telegram_bot'
        | 'payment_guard'
        | 'rag_assistant'
        | 'csv_import'
        | 'risk_scoring'
        | 'custom_templates'
        | 'ai_drafts'
        | 'analytics_advanced'
        | 'audit_export',
        boolean
    >;
    upgrade: null | {
        limit_key: string;
        plan_suggestion: 'starter' | 'pro' | 'business';
    };
    stepup: {
        fresh_until: ISODateTime | null;
        methods: Array<'passkey' | 'totp' | 'password'>;
        second_factor_required: boolean;
        has_second_factor: boolean;
        prompt?: boolean;
    };
    guard: { privileged: boolean; eligible_from: ISODateTime | null };
    guard_error?: ({ code: string } & Record<string, unknown>) | null;
};

export type DashboardProps = {
    currency: CurrencyCode;
    currencies: CurrencyCode[];
    kpis: {
        outstanding: { amount: Money; count: number };
        overdue: { amount: Money; count: number; share: number };
        collected_this_month: { amount: Money; delta_pct: number | null };
        dso: {
            days: number | null;
            delta_days: number | null;
            trend: DsoPoint[];
        };
        at_risk: { amount: Money; count: number } | null;
    };
    aging: AgingRow[];
    forecast: { weeks: ForecastWeek[]; later_total: Money } | null;
    attention: InvoiceListItem[];
    upcoming_reminders: UpcomingReminder[];
    late_payers: LatePayerRow[];
    guard: {
        pending_approvals: PayoutListItem[];
        awaiting_me: number;
        bank_changes_pending: number;
        bank_changes_high: number;
        open_alerts: GuardAlertItem[];
    } | null;
    insights: Insight[];
    onboarding: {
        has_clients: boolean;
        has_invoices: boolean;
        provider_connected: boolean;
        telegram_linked: boolean;
        has_vendors: boolean;
        documents_indexed: number;
        dismissed: boolean;
    };
};

export type InvoicesIndexProps = {
    invoices: Paginated<InvoiceListItem>;
    filters: {
        status: InvoiceDisplayStatus | 'all' | 'outstanding';
        client_id: number | null;
        q: string | null;
        currency: CurrencyCode | null;
        risk: RiskBand | null;
        aging: AgingBucket | null;
        due_from: ISODate | null;
        due_to: ISODate | null;
        sort:
            | 'due_date'
            | '-due_date'
            | 'balance'
            | '-balance'
            | 'days_overdue'
            | '-days_overdue'
            | 'risk'
            | '-risk'
            | 'issue_date'
            | '-issue_date';
        per_page: 25 | 50 | 100;
    };
    counts: Record<InvoiceDisplayStatus | 'all' | 'outstanding', number>;
    totals: Array<{
        currency: CurrencyCode;
        outstanding: Money;
        overdue: Money;
    }>;
    clients: Option<number>[];
    currencies: CurrencyCode[];
};
export type InvoiceFormProps = {
    invoice: InvoiceDetail | null;
    clients: ClientOption[];
    defaults: {
        client_id: number | null;
        currency: CurrencyCode;
        locale: Locale;
        issue_date: ISODate;
        due_date: ISODate;
        payment_terms_days: number;
        tax_rate_bp: number;
        notes: string | null;
        terms: string | null;
    };
    next_number_preview: string;
    currencies: CurrencyCode[];
    provider_connected: boolean;
    limits: { invoices_per_month: { used: number; limit: number | null } };
};
export type InvoiceShowProps = {
    invoice: InvoiceDetail;
    payments: PaymentRow[];
    reminders: ReminderRow[];
    next_reminder: (ReminderRow & { policy_name: string }) | null;
    tone: ToneSuggestion | null;
    late_fee: LateFeeSuggestion | null;
    timeline: TimelineEvent[];
    client_stats: Pick<
        ClientListItem,
        | 'avg_days_late'
        | 'on_time_rate'
        | 'paid_invoices_count'
        | 'open_balance'
        | 'overdue_balance'
    >;
    can: {
        edit: boolean;
        send: boolean;
        record_payment: boolean;
        remind: boolean;
        void: boolean;
        write_off: boolean;
        dispute: boolean;
        apply_late_fee: boolean;
        delete: boolean;
    };
};

export type ClientsIndexProps = {
    clients: Paginated<ClientListItem>;
    filters: {
        q: string | null;
        class: LatePayerClass | null;
        owner_id: number | 'me' | null;
        paused: boolean | null;
        sort: 'name' | '-open_balance' | '-overdue_balance' | '-avg_days_late';
    };
    class_counts: Record<LatePayerClass, number>;
    owners: Option<number>[];
};
export type ClientFormProps = {
    client: ClientDetail | null;
    policies: Option<number>[];
    members: Option<number>[];
    documents: Array<{ id: number; title: string }>;
    currencies: CurrencyCode[];
    countries: Option<string>[];
    workspace_defaults: {
        payment_terms_days: number;
        late_fee: LateFeeTerms;
        policy: Option<number> | null;
    };
    limits: { clients: { used: number; limit: number | null } };
};
export type ClientShowProps = {
    client: ClientDetail;
    invoices: InvoiceListItem[];
    behaviour: Array<{
        invoice_id: number;
        number: string;
        due_date: ISODate;
        paid_on: ISODate;
        days_late: number;
        amount: Money;
    }>;
    reminders: ReminderListItem[];
    timeline: TimelineEvent[];
    next_reminder: UpcomingReminder | null;
    can: {
        edit: boolean;
        delete: boolean;
        pause: boolean;
        create_invoice: boolean;
    };
};

export type ReminderTab = 'upcoming' | 'sent' | 'failed' | 'skipped';

export type RemindersIndexProps = {
    tab: ReminderTab;
    reminders: Paginated<ReminderListItem>;
    counts: Record<ReminderTab, number>;
    stats: {
        sent_30d: number;
        paid_within_7d_rate: number | null;
        failed_7d: number;
    };
    filters: { tone: Tone | null; q: string | null };
};

export type ReminderDetail = ReminderListItem & {
    body_html: string | null;
    body_text: string | null;
};

export type AnalyticsLockable = 'forecast' | 'tone_effectiveness' | 'risk_mix';

export type AnalyticsProps = {
    currency: CurrencyCode;
    currencies: CurrencyCode[];
    months: 3 | 6 | 12;
    kpis: DashboardProps['kpis'];
    aging: AgingRow[];
    monthly: MonthlyPoint[];
    dso_trend: DsoPoint[];
    forecast: { weeks: ForecastWeek[]; later_total: Money } | null;
    late_payers: LatePayerRow[];
    risk_mix: Array<{ band: RiskBand; amount: Money; count: number }> | null;
    tone_effectiveness: ToneEffectiveness[] | null;
    locked: AnalyticsLockable[];
};

export type ImportsIndexProps = {
    imports: Paginated<ImportListItem>;
    presets: Option<ImportPreset>[];
    kinds: Option<ImportKind>[];
    limits: { imports_per_month: { used: number; limit: number | null } };
    entitled: boolean;
};

export type ImportShowProps = {
    import: ImportDetail;
    currencies: CurrencyCode[];
};

export type ImportActivationProps = {
    import: ImportListItem;
    preview: ImportActivationPreview;
    invoices: InvoiceListItem[];
};

export type PublicInvoiceProps = {
    invoice: PublicInvoice;
    company: {
        name: string;
        legal_name: string | null;
        address: string | null;
        tax_id: string | null;
        email: string | null;
        phone: string | null;
        receiving_bank_details: string | null;
    };
    client: {
        name: string;
        legal_name: string | null;
        address: string | null;
        tax_id: string | null;
    };
    payable: boolean;
    payable_reason: PayableReason;
    pay_label: 'card' | 'yookassa' | 'demo' | null;
    pdf_url: string;
    provider_mode: 'test' | 'live' | null;
    legal: PublicLegal;
    locale: Locale;
};

export type PaymentThanksProps = {
    invoice: Pick<
        PublicInvoice,
        | 'number'
        | 'status'
        | 'display_status'
        | 'total'
        | 'balance_due'
        | 'locale'
    >;
    status_url: string;
    company_name: string;
    legal: PublicLegal;
};

export type ReminderChoicesProps = {
    invoice: Pick<
        PublicInvoice,
        'number' | 'balance_due' | 'due_date' | 'locale'
    >;
    company_name: string;
    client_name: string;
    opted_out: boolean;
    reported_paid_at: ISODateTime | null;
    legal: PublicLegal;
};

export type PfSearchResponse = {
    invoices: Array<
        Pick<
            InvoiceListItem,
            'id' | 'number' | 'client' | 'balance_due' | 'display_status'
        >
    >;
    clients: ClientRef[];
    vendors: VendorRef[];
    payouts: Array<{ id: number; number: string; vendor: VendorRef }>;
};
