<?php

declare(strict_types=1);

/*
| Audit action labels: `audit.actions.<action>` where <action> is e.g. `member.role_changed`.
*/

return [

    'actions' => [
        'auth' => [
            'login' => 'Signed in',
            'logout' => 'Signed out',
            'login_failed' => 'Failed sign-in attempt',
            'lockout' => 'Sign-in locked after too many attempts',
            'registered' => 'Account created',
            'email_verified' => 'Email confirmed',
            'password_reset' => 'Password reset',
            'two_factor_enabled' => 'Two-factor authentication enabled',
            'account_deleted' => 'Account deleted',
            'two_factor_disabled' => 'Two-factor authentication disabled',
        ],
        'workspace' => [
            'created' => 'Workspace created',
            'updated' => 'Workspace settings changed',
            'deleted' => 'Workspace deleted',
            'deletion_scheduled' => 'Workspace deletion scheduled',
            'deletion_cancelled' => 'Workspace deletion cancelled',
        ],
        'member' => [
            'role_changed' => 'Member role changed',
            'removed' => 'Member removed',
            'left' => 'Member left the workspace',
        ],
        'invitation' => [
            'created' => 'Invitation sent',
            'revoked' => 'Invitation revoked',
            'accepted' => 'Invitation accepted',
        ],
        'billing' => [
            'subscription_changed' => 'Subscription changed',
        ],
        'ai' => [
            'settings_updated' => 'AI settings changed',
            'key_updated' => 'AI provider key saved',
            'key_cleared' => 'AI provider key removed',
        ],
        'document' => [
            'uploaded' => 'Document uploaded',
            'updated' => 'Document details changed',
            'deleted' => 'Document deleted',
        ],
        'telegram' => [
            'linked' => 'Telegram connected',
            'unlinked' => 'Telegram disconnected',
        ],
        'assistant' => [
            'escalated' => 'Question escalated to admins',
        ],
        'legal' => [
            'accepted' => 'Legal document accepted',
        ],
        'privacy' => [
            'export_requested' => 'Personal data export requested',
            'workspace_export_requested' => 'Workspace export requested',
            'export_ready' => 'Data export ready',
            'export_failed' => 'Data export failed',
            'export_downloaded' => 'Data export downloaded',
            'deletion_scheduled' => 'Account deletion scheduled',
            'deletion_cancelled' => 'Account deletion cancelled',
            'deletion_completed' => 'Account permanently deleted',
            'workspace_purged' => 'Workspace data purged',
            'settings_updated' => 'Privacy settings changed',
            'retention_purged' => 'Retention purge completed',
        ],
        'email' => [
            'opted_out' => 'Recipient unsubscribed from automated e-mail',
        ],
    ],

    'system' => 'System',

];
