export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type PaginationMeta = {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
};

export type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
    meta: PaginationMeta;
};

export type SelectOption<T extends string = string> = {
    value: T;
    label: string;
};

export type PlanKey = 'free' | 'starter' | 'pro' | 'business';

export type BillingInterval = 'month' | 'year';

export type BillingCurrency = 'USD' | 'EUR' | 'RUB';

export type BillingMarket = {
    country: string;
    currency: BillingCurrency;
    gateway: 'fake' | 'stripe' | 'yookassa';
    country_name: string;
    gateway_name: string;
    available: boolean;
};

export type BillingCapabilities = {
    portal: boolean;
    manage: boolean;
};

export type CountryOption = {
    code: string;
    name: string;
    currency: BillingCurrency;
    gateway: 'stripe' | 'yookassa';
    available: boolean;
};

export type Plan = {
    key: PlanKey;
    name: string;
    description: string;
    price_monthly: number;
    price_yearly: number;
    currency: BillingCurrency;
    features: string[];
    limits: Record<string, number | null>;
    highlighted?: boolean;
};

export type SubscriptionStatus =
    | 'trialing'
    | 'active'
    | 'past_due'
    | 'canceled'
    | 'incomplete';

export type Subscription = {
    plan: PlanKey;
    status: SubscriptionStatus;
    interval: BillingInterval;
    currency?: BillingCurrency | null;
    gateway?: string;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
    trial_ends_at?: string | null;
    payment_failed_at?: string | null;
};

export type UsageEntry = {
    used: number;
    limit: number | null;
};

export type UsageMap = Record<string, UsageEntry>;

export type Member = {
    id: number;
    name: string;
    email: string;
    role: string;
    joined_at: string;
    telegram_linked: boolean;
    is_you: boolean;
};

export type Invitation = {
    id: number;
    email: string;
    role: string;
    expires_at: string;
    invited_by: string;
};

export type NamedRef = { id: number; name: string };

export type InvitationPreview = {
    workspace: NamedRef | string;
    email: string;
    role: string;
    inviter: string;
    expires_at: string;
};

export type WorkspaceSettings = {
    id: number;
    name: string;
    slug: string;
    timezone: string;
    currency: string;
    locale: string;
};

export type AuditLog = {
    id: number;
    action: string;
    action_label?: string | null;
    actor: { id: number; name: string } | null;
    subject_type: string | null;
    subject_id: string | null;
    subject_label: string | null;
    changes: Record<string, [unknown, unknown]> | null;
    ip: string | null;
    created_at: string;
};

export type AuditActor = NamedRef;

export type AuditFilters = {
    action?: string | null;
    actor?: string | null;
    from?: string | null;
    to?: string | null;
};

export type KbDocumentStatus = 'queued' | 'processing' | 'indexed' | 'failed';

export type KbDocument = {
    id: number;
    title: string;
    original_name: string;
    mime: string;
    size_bytes: number;
    department: string | null;
    roles: string[];
    tags: string[];
    version: string | null;
    effective_date: string | null;
    status: KbDocumentStatus;
    pages: number | null;
    chunks: number | null;
    ocr_used: boolean;
    error: string | null;
    uploaded_by: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
    download_url?: string | null;
};

export type KbFilters = {
    q?: string | null;
    status?: KbDocumentStatus | null;
    department?: string | null;
};

export type ChunkPreview = {
    page: number | null;
    section: string | null;
    content: string;
};

export type AiChannel = 'telegram' | 'web';

export type AiQuery = {
    id: number;
    channel: AiChannel;
    user: { id: number; name: string } | null;
    question: string;
    answer: string | null;
    refused: boolean;
    confidence: number | null;
    citations: Array<{
        document_id: number;
        title: string;
        page?: number | null;
    }>;
    tools_used: string[];
    latency_ms: number | null;
    provider: string | null;
    model: string | null;
    created_at: string;
};

export type AiQueryFilters = {
    status?: 'answered' | 'refused' | null;
    channel?: AiChannel | null;
    q?: string | null;
};

export type AiQueryStats = {
    total: number;
    answered: number;
    refused: number;
    avg_confidence: number;
};

export type ChatCitation = {
    n?: number;
    document_id: number;
    section?: string | null;
    title: string;
    page?: number | null;
    snippet: string;
    url?: string | null;
};

export type ChatAction = {
    type: string;
    label: string;
    payload: Record<string, unknown>;
};

export type ChatResponse = {
    answer_markdown: string;
    answer_html: string;
    citations: ChatCitation[];
    confidence: number;
    refused: boolean;
    tools_used: string[];
    actions: ChatAction[];
    provider: string;
    model: string;
    latency_ms: number;
    conversation_id?: string | null;
    degraded?: boolean;
    refusal_reason?:
        | 'low_confidence'
        | 'not_found'
        | 'model_refusal'
        | 'unavailable'
        | 'policy'
        | null;
};

export type KeySource = 'settings' | 'env' | null;

export type Provider = {
    id: string;
    label: string;
    configured: boolean;
    needs_key: boolean;
    key_source: KeySource;
    key_hint: string | null;
    default_model: string;
    models: string[];
    note?: string;
};

export type EmbeddingsProvider = 'local' | 'openai-compatible' | 'hash';

export type AiSettings = {
    provider: string;
    model: string;
    fallback_model: string | null;
    temperature: number;
    embeddings: { provider: EmbeddingsProvider; model: string };
};

export type UsageDay = {
    date: string;
    prompt_tokens: number;
    completion_tokens: number;
    cost_usd: number;
    requests: number;
};

export type UsageByModel = {
    provider: string;
    model: string;
    requests: number;
    cost_usd: number;
};

export type UsageSummary = {
    days: UsageDay[];
    by_model: UsageByModel[];
    total_cost_usd: number;
};

export type ConnectionTestResult = {
    ok: boolean;
    latency_ms: number | null;
    provider?: string | null;
    model: string | null;
    reply?: string | null;
    error?: string | null;
};

export type ModelInfo = {
    id: string;
    free?: boolean;
    context?: number | null;
    structured?: boolean;
};

export type ModelsResponse =
    | Array<string | ModelInfo>
    | {
          provider?: string;
          source?: 'live' | 'catalog';
          models: Array<string | ModelInfo>;
          error?: string | null;
      };

export type TelegramStatus = {
    linked: boolean;
    username: string | null;
    linked_at: string | null;
};

export type TelegramLinkResponse = {
    url: string;
    expires_at: string;
};

export type FakeCheckoutSession = {
    id: string;
    kind: 'subscription' | 'payment';
    title: string;
    amount: number;
    currency: string;
    workspace: NamedRef | string;
    success_url: string;
    cancel_url: string;
};

export type DashboardStat = {
    key: string;
    label: string;
    value: number | string;
    delta?: number | null;
};
