<?php

declare(strict_types=1);

return [
    'defaults' => [
        'ai_queries' => (int) env('RETENTION_AI_QUERIES_DAYS', 180),
        'audit_logs' => (int) env('RETENTION_AUDIT_LOGS_DAYS', 365),
        'deliveries' => (int) env('RETENTION_DELIVERIES_DAYS', 90),
        'webhook_events' => (int) env('RETENTION_WEBHOOK_EVENTS_DAYS', 30),
        'outbox_dispatched' => (int) env('RETENTION_OUTBOX_DAYS', 7),
        'failed_jobs' => (int) env('RETENTION_FAILED_JOBS_DAYS', 30),
        'notifications' => (int) env('RETENTION_NOTIFICATIONS_DAYS', 180),

        'deleted_documents' => 0,

        'data_exports' => max(1, (int) ceil(((int) env('PRIVACY_EXPORT_LINK_HOURS', 24)) / 24)),
    ],

    'payflow' => [
        'provider_event_payloads' => 30,
        'reminder_bodies' => 365,
        'import_files' => 30,
        'pdf_cache' => 7,
        'approval_network' => 365,
        'bank_change_evidence' => 1095,
        'trusted_confirmation_tokens' => 365,
        'alerts' => 730,
        'digest_runs' => 90,
        'financial_records' => 3650,
    ],

    'account_deletion_grace' => (int) env('PRIVACY_USER_DELETION_GRACE_DAYS', 14),
    'workspace_deletion_grace' => (int) env('PRIVACY_WORKSPACE_DELETION_GRACE_DAYS', 30),
    'export_link_hours' => (int) env('PRIVACY_EXPORT_LINK_HOURS', 24),

    'overridable' => [
        'ai_queries' => ['min' => 7, 'max' => 730],
        'audit_logs' => ['min' => 30, 'max' => 2555],
    ],

    'plan_max' => [
        'free' => ['ai_queries' => 180, 'audit_logs' => 365],
    ],
];
