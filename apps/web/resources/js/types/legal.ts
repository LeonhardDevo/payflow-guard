export type LegalDocKey =
    | 'terms'
    | 'privacy'
    | 'cookies'
    | 'dpa'
    | 'subprocessors'
    | 'aup'
    | 'ai'
    | 'consent-ru'
    | (string & {});

export type LegalDocSummary = {
    key: LegalDocKey;
    title: string;
    version: string;
    effective_date: string;
};

export type LegalTocEntry = {
    id: string;
    text: string;
    level: number;
};

export type LegalDoc = LegalDocSummary & {
    html: string;
    toc: LegalTocEntry[];
};

export type LegalVersion = {
    version: string;
    effective_date: string;
};

export type LegalVersionRef = {
    version: string;
    url: string;
    title?: string | null;
};

export type RegisterLegal = {
    terms: LegalVersionRef | null;
    privacy: LegalVersionRef | null;
    consent_ru: LegalVersionRef | null;
};

export type PendingAcceptance = {
    key: LegalDocKey;
    title: string;
    version: string;
    summary_of_changes: string | null;
    url?: string | null;
    workspace?: string | null;
};

export type DataExportStatus =
    | 'pending'
    | 'processing'
    | 'ready'
    | 'failed'
    | 'expired'
    | (string & {});

export type DataExport = {
    id: number | string;
    status: DataExportStatus;
    created_at: string;
    expires_at: string | null;
    download_url: string | null;
    scope?: 'user' | 'workspace';
};

export type LegalAcceptance = {
    document: LegalDocKey;
    version: string;
    accepted_at: string;
    title?: string | null;
};

export type RetentionBounds = { min: number; max: number };

export type PrivacyWorkspace = {
    id?: number;
    name?: string;
    ai_external_allowed: boolean;
    retention: Record<string, number>;
    retention_bounds?: Record<string, RetentionBounds | null> | null;
    deletion?: { scheduled_for: string | null; grace_days?: number } | null;
};

export type PrivacyPermissions = {
    manage_workspace_privacy: boolean;
    export_workspace?: boolean;
    delete_workspace?: boolean;
};

export type PrivacyPageProps = {
    exports: DataExport[];
    deletion: {
        scheduled_for: string | null;
        grace_days?: number;
        blocked_by_workspace?: string | null;
    };
    acceptances: LegalAcceptance[];
    privacy_workspace: PrivacyWorkspace | null;
    privacy_can: PrivacyPermissions;
    export_link_hours?: number;
};

export type OptOutStatus = 'confirm' | 'done' | 'invalid';

export type OptOutPageProps = {
    status: OptOutStatus;
    token?: string | null;
    email?: string | null;
    workspace?: string | null;
};
