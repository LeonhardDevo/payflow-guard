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
        'payflow' => [
            'client' => [
                'created' => 'Client created',
                'updated' => 'Client updated',
                'deleted' => 'Client deleted',
                'reminders_paused' => 'Client reminders paused',
                'reminders_resumed' => 'Client reminders resumed',
                'anonymised' => 'Client anonymised',
            ],
            'invoice' => [
                'created' => 'Invoice created',
                'updated' => 'Invoice updated',
                'issued' => 'Invoice issued',
                'sent' => 'Invoice e-mailed',
                'voided' => 'Invoice voided',
                'written_off' => 'Invoice written off',
                'disputed' => 'Invoice marked as disputed',
                'dispute_resolved' => 'Invoice dispute resolved',
                'late_fee_applied' => 'Late fee applied',
                'draft_deleted' => 'Draft invoice deleted',
                'reminders_paused' => 'Invoice reminders paused',
                'reminders_activated' => 'Invoice reminders activated',
                'first_contact_confirmed' => 'First reminder tone confirmed',
            ],
            'payment' => [
                'recorded' => 'Payment recorded',
                'reversed' => 'Payment reversed',
                'provider_recorded' => 'Online payment received',
                'refunded' => 'Payment refunded',
            ],
            'payment_account' => [
                'connected' => 'Payment account connected',
                'key_replaced' => 'Payment account key replaced',
                'restricted' => 'Payment account restricted',
                'disconnected' => 'Payment account disconnected',
            ],
            'settings' => [
                'collections_updated' => 'Collections settings changed',
                'guard_updated' => 'Payment guard settings changed',
                'thresholds_updated' => 'Approval thresholds changed',
                'second_factor_requirement_changed' => 'Second-factor requirement changed',
            ],
            'receiving_details' => [
                'requested' => 'Receiving bank details change requested',
                'confirmed' => 'Receiving bank details change confirmed',
                'rejected' => 'Receiving bank details change rejected',
                'canceled' => 'Receiving bank details change cancelled',
                'activated' => 'Receiving bank details activated',
            ],
            'template' => [
                'updated' => 'E-mail template updated',
                'reset' => 'E-mail template reset to default',
            ],
            'policy' => [
                'created' => 'Reminder policy created',
                'updated' => 'Reminder policy updated',
                'deleted' => 'Reminder policy deleted',
            ],
            'vendor' => [
                'created' => 'Vendor created',
                'updated' => 'Vendor updated',
                'phone_changed' => 'Vendor phone changed',
                'held' => 'Vendor put on hold',
                'hold_released' => 'Vendor hold released',
                'archived' => 'Vendor archived',
                'anonymised' => 'Vendor anonymised',
            ],
            'bank_account' => [
                'requested' => 'Bank details change requested',
                'viewed' => 'Bank details change reviewed',
                'verified' => 'Bank details verified',
                'verified_solo' => 'Bank details verified in solo mode',
                'rejected' => 'Bank details change rejected',
                'revealed' => 'Full bank details revealed',
            ],
            'payout' => [
                'created' => 'Payout drafted',
                'submitted' => 'Payout submitted for approval',
                'auto_approved' => 'Payout approved automatically',
                'approved' => 'Payout approved',
                'rejected' => 'Payout rejected',
                'held' => 'Payout put on hold',
                'resubmitted' => 'Payout resubmitted',
                'released' => 'Payout released',
                'canceled' => 'Payout cancelled',
                'approvals_invalidated' => 'Payout approvals invalidated',
            ],
            'member' => [
                'guard_role_granted' => 'Guard role granted',
                'guard_role_revoked' => 'Guard role revoked',
            ],
            'trusted_contact' => [
                'added' => 'Trusted contact added',
                'verified' => 'Trusted contact verified',
                'revoked' => 'Trusted contact revoked',
                'confirmation_sent' => 'Trusted contact confirmation sent',
                'confirmed' => 'Trusted contact confirmed a change',
                'declined' => 'Trusted contact declined a change',
            ],
            'stepup' => [
                'succeeded' => 'Second-factor confirmation succeeded',
                'failed' => 'Second-factor confirmation failed',
            ],
            'reminder' => [
                'sent_manually' => 'Reminder sent manually',
                'canceled' => 'Reminder cancelled',
                'retried' => 'Reminder retried',
            ],
            'recipient' => [
                'opted_out' => 'Recipient stopped automated reminders',
                'reported_paid' => 'Recipient reported the invoice as paid',
            ],
            'import' => [
                'started' => 'Import started',
                'completed' => 'Import completed',
                'activated' => 'Imported reminders activated',
                'dismissed' => 'Imported reminders dismissed',
            ],
            'alert' => [
                'acknowledged' => 'Alert acknowledged',
                'resolved' => 'Alert resolved',
            ],
            'export' => [
                'masked' => 'Masked export downloaded',
                'unmasked' => 'Unmasked export downloaded',
            ],
            'retention' => [
                'purged' => 'Retention purge completed',
            ],
        ],
    ],

    'system' => 'System',

];
