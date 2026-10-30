<?php

declare(strict_types=1);

/*
| Backend-facing flash / toast messages and API error messages.
*/

return [

    'workspace' => [
        'created' => 'Workspace “:name” is ready.',
        'updated' => 'Workspace settings saved.',
        'deleted' => 'Workspace “:name” was deleted.',
        'switched' => 'Switched to “:name”.',
        'required' => 'Create or join a workspace first.',
    ],

    'members' => [
        'role_changed' => ':name is now :role.',
        'removed' => ':name was removed from the workspace.',
        'left' => 'You left “:workspace”.',
    ],

    'invitations' => [
        'sent' => 'Invitation sent to :email.',
        'revoked' => 'Invitation for :email was revoked.',
        'accepted' => 'Welcome to “:workspace”!',
    ],

    'documents' => [
        'uploaded' => '“:title” uploaded — indexing has started.',
        'updated' => '“:title” updated — re-indexing has started.',
        'deleted' => '“:title” was deleted.',
    ],

    'telegram' => [
        'unlinked' => 'Telegram account disconnected.',
        'not_configured' => 'The Telegram bot is not configured for this installation.',
        'not_linked' => 'Link your Telegram account in the app settings first.',
        'escalated' => 'Sent to your workspace admins — they will get back to you.',
        'escalation_no_admins' => 'There is no admin in this workspace to escalate to.',
        'escalation_html' => '<b>:name</b> needs help in <b>:workspace</b>. The assistant found no answer in the approved documents:
<i>:question</i>',
    ],

    'assistant' => [
        'unavailable' => 'The assistant is temporarily unavailable. Please try again in a minute.',
        'failed' => 'The assistant could not answer this request. Please rephrase and try again.',
        'action_unknown' => 'This action is not available yet.',
    ],

    // In-app feed (header bell). Titles/bodies are translated when the feed is read.
    'in_app' => [
        'escalation' => [
            'title' => ':name asked for a specialist',
            'body' => 'No answer in the approved documents: “:question”',
            'body_empty' => 'The assistant found no answer in the approved documents.',
        ],
        'document_indexed' => [
            'title' => 'Document ready: :title',
            'body' => 'Indexed and available to the assistant. Passages: :chunks.',
        ],
        'document_failed' => [
            'title' => 'Document could not be processed: :title',
            'body' => ':error',
        ],
        'recipient_opted_out' => [
            'title' => 'A recipient unsubscribed',
            'body' => ':email will no longer receive automated e-mails from this workspace.',
        ],
    ],

    'privacy' => [
        'export_requested' => 'Your export is being prepared. We will e-mail you a download link.',
        'workspace_export_requested' => 'The workspace export is being prepared. We will e-mail you a download link.',
        'export_in_progress' => 'An export is already being prepared. Please wait for the e-mail.',
        'export_expired' => 'This download link has expired. Request a new export.',
        'deletion_scheduled' => 'Your account will be deleted on :date. You can cancel until then.',
        'deletion_cancelled' => 'Account deletion cancelled.',
        'workspace_deletion_scheduled' => 'Workspace “:name” will be deleted on :date. You can cancel until then.',
        'workspace_deletion_cancelled' => 'Workspace deletion cancelled.',
        'settings_updated' => 'Privacy settings saved.',
        'transfer_ownership_first' => 'You are the only owner of “:workspace”. Make another member an owner before deleting your account.',
        'nothing_to_cancel' => 'There is no scheduled deletion to cancel.',
        'workspace_required' => 'Select a workspace first.',
        'legal_accepted' => 'Thank you. Your acceptance has been recorded.',
        'legal_acceptance_required' => 'Please review and accept the updated documents to continue.',
    ],

    'ai' => [
        'unavailable' => 'The AI service is unreachable. Check that it is running.',
        'failed' => 'The AI service rejected the request.',
        'settings_saved_reembed' => 'AI settings saved. Re-embedding queued for :count workspace(s).',
        'settings_saved' => 'AI settings saved.',
        'key_saved' => 'API key saved.',
        'key_cleared' => 'Stored API key removed.',
    ],

    'profile' => [
        'updated' => 'Profile updated.',
        'password_updated' => 'Password updated.',
    ],

    'errors' => [
        'not_a_member' => 'This person is not a member of the workspace.',
        'cannot_change_own_role' => 'You cannot change your own role.',
        'insufficient_role' => 'Your role does not allow this action.',
        'last_owner' => 'A workspace must keep at least one owner. Make someone else an owner first.',
        'already_member' => ':email is already a member of this workspace.',
        'invitation_used' => 'This invitation has already been used.',
        'invitation_expired' => 'This invitation has expired. Ask for a new one.',
        'transfer_ownership_first' => 'You are the only owner of “:workspace”. Make another member an owner before deleting your account.',
        'invitation_email_mismatch' => 'This invitation was sent to :email. Sign in with that address to accept it.',
    ],

];
