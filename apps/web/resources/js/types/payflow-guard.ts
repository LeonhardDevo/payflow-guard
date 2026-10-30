import type {
    AlertSeverity,
    AlertStatus,
    BankAccountStatus,
    BankAccountView,
    BankChangeSource,
    BankDiffRow,
    CurrencyCode,
    GuardAlertItem,
    GuardSettings,
    GuardThreshold,
    ISODateTime,
    Locale,
    Money,
    Option,
    Paginated,
    PayoutDetail,
    PayoutListItem,
    TrustedConfirmationInfo,
    TrustedContact,
    UserRef,
    VendorCategory,
    VendorDetail,
    VendorListItem,
    VendorRef,
    VendorStatus,
    VerificationMethod,
} from './payflow';

export type {
    AlertSeverity,
    AlertStatus,
    ApprovalChannel,
    ApprovalRow,
    BankAccountStatus,
    BankAccountView,
    BankChangeSource,
    BankDiffRow,
    BankScheme,
    CurrencyCode,
    EligibleApprover,
    GuardAlertItem,
    GuardSettings,
    GuardThreshold,
    ISODate,
    ISODateTime,
    Locale,
    Money,
    Option,
    Paginated,
    Params,
    PayoutCheck,
    PayoutDetail,
    PayoutHoldReason,
    PayoutListItem,
    PayoutStatus,
    RiskBand,
    Signal,
    StepUpMethod,
    TimelineEvent,
    TrustedConfirmationInfo,
    TrustedContact,
    UserRef,
    VendorCategory,
    VendorDetail,
    VendorListItem,
    VendorRef,
    VendorStatus,
    VerificationMethod,
    VerificationMode,
    WorkspaceRole,
} from './payflow';

export type GuardIndexProps = {
    kpis: {
        awaiting_me: number;
        pending_approvals: { count: number; amounts: Money[] };
        bank_changes_pending: number;
        bank_changes_high: number;
        on_hold: number;
        released_this_month: Money[];
    };
    needs_decision: PayoutListItem[];
    bank_changes: Array<{ account: BankAccountView; vendor: VendorRef }>;
    alerts: Paginated<GuardAlertItem>;
    alert_filters: {
        severity: AlertSeverity | null;
        status: AlertStatus | 'active';
    };
    rules: GuardSettings & {
        eligible_approvers: number;
        pending_eligibility: Array<UserRef & { eligible_from: ISODateTime }>;
        trusted_contact: TrustedContact | null;
        solo_mode: boolean;
    };
    me: {
        privileged: boolean;
        eligible_from: ISODateTime | null;
        missing_second_factor: boolean;
    };
};

export type CannotVerifyReason =
    | 'is_requester'
    | 'no_permission'
    | 'not_pending'
    | 'not_eligible_yet'
    | 'second_factor_missing';

export type BankChangeReviewProps = {
    change: BankAccountView & {
        change_request_text: string | null;
        requester_contact_hint: string | null;
    };
    previous: BankAccountView | null;
    diff: BankDiffRow[];
    vendor: VendorDetail;
    affected_payouts: PayoutListItem[];
    requirements: {
        allowed_methods: VerificationMethod[];
        note_required: boolean;
        note_min: number;
        step_up_required: boolean;
        cooling_off_hours: number;
        solo: {
            available: boolean;
            trusted_contact: TrustedContact | null;
            confirmation: TrustedConfirmationInfo | null;
        };
    };
    callback: {
        known_phone: string | null;
        phone_verified_at: ISODateTime | null;
        phone_recently_changed: boolean;
    };
    can: {
        verify: boolean;
        verify_solo: boolean;
        reject: boolean;
        withdraw: boolean;
        reveal: boolean;
    };
    cannot_verify_reason: CannotVerifyReason | null;
    disclaimer_url: string;
};

export type VendorsIndexProps = {
    vendors: Paginated<VendorListItem>;
    filters: {
        q: string | null;
        status: VendorStatus | null;
        category: VendorCategory | null;
        attention: 'pending_change' | 'on_hold' | null;
    };
    counts: { all: number; pending_change: number; on_hold: number };
    processing_notice: boolean;
};

export type VendorFormProps = {
    vendor: VendorDetail | null;
    categories: Option<VendorCategory>[];
    countries: Option<string>[];
    currencies: CurrencyCode[];
    limits: { vendors: { used: number; limit: number | null } };
};

export type VendorShowProps = {
    vendor: VendorDetail;
    accounts: BankAccountView[];
    payouts: PayoutListItem[];
    alerts: GuardAlertItem[];
    can: {
        edit: boolean;
        hold: boolean;
        request_change: boolean;
        reveal: boolean;
        create_payout: boolean;
        anonymise: boolean;
    };
};

export type BankChangeFormProps = {
    vendor: VendorDetail;
    current: BankAccountView | null;
    countries: Option<string>[];
    currencies: CurrencyCode[];
    sources: Option<BankChangeSource>[];
};

export type PayoutTab =
    | 'awaiting_me'
    | 'pending'
    | 'approved'
    | 'on_hold'
    | 'released'
    | 'all';

export type PayoutsIndexProps = {
    tab: PayoutTab;
    payouts: Paginated<PayoutListItem>;
    counts: Record<PayoutTab, number>;
    filters: { vendor_id: number | null; q: string | null };
    vendors: Option<number>[];
};

export type PayoutVendorOption = VendorRef & {
    currency: CurrencyCode | null;
    account_display: string | null;
    account_status: BankAccountStatus | null;
    cooling_off_until: ISODateTime | null;
    pending_change: boolean;
};

export type PayoutFormProps = {
    payout: PayoutDetail | null;
    vendors: PayoutVendorOption[];
    thresholds: GuardThreshold[];
    limits: { payouts_per_month: { used: number; limit: number | null } };
    approvers_available: { eligible: number; trusted_contact: boolean };
};

export type CannotApproveReason =
    | 'is_requester'
    | 'already_decided'
    | 'no_permission'
    | 'not_pending'
    | 'details_changed'
    | 'not_eligible_yet'
    | 'second_factor_missing';

export type CannotReleaseReason =
    | 'releaser_is_requester'
    | 'release_delayed'
    | 'not_approved'
    | 'no_permission';

export type PayoutShowProps = {
    payout: PayoutDetail;
    can: {
        edit: boolean;
        submit: boolean;
        resubmit: boolean;
        approve: boolean;
        reject: boolean;
        release: boolean;
        cancel: boolean;
    };
    cannot_approve_reason: CannotApproveReason | null;
    cannot_release_reason: CannotReleaseReason | null;
    disclaimer_url: string;
};

export type RevealedBankDetails = {
    iban: string | null;
    account_number: string | null;
    routing_number: string | null;
    swift_bic: string | null;
    expires_in: number;
};

export type TrustedConfirmProps = {
    confirmation: {
        workspace: string;
        requester: string;
        subject:
            | 'payout_request'
            | 'vendor_bank_account'
            | 'receiving_details_version';
        snapshot: Record<string, string | number>;
        signals: string[];
        expires_at: ISODateTime;
        status: string;
    };
    disclaimer_url: string;
    locale: Locale;
};

export type TrustedVerifiedProps = {
    state: 'verified' | 'declined' | 'decline_prompt' | 'invalid';
    workspace: string | null;
    contact_name: string | null;
    active_from: ISODateTime | null;
    decline_url: string | null;
    disclaimer_url: string;
    locale: Locale;
};
