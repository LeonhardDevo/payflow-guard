<?php

declare(strict_types=1);

return [

    'common' => [
        'greeting' => 'Hello!',
        'greeting_error' => 'Something went wrong',
        'there' => 'there',
        'regards' => 'Best regards,',
        'team' => 'The :app team',
        'trouble_clicking' => 'If the “:action” button does not work, copy and paste this link into your browser:',
        'rights' => 'All rights reserved.',
        'automated' => 'This is an automated message — replies to this address are not monitored.',
        'sender' => 'Sent by :company, :address.',
        'privacy' => 'Privacy Policy',
        'terms' => 'Terms of Service',
    ],

    'verify' => [
        'subject' => 'Confirm your email for :app',
        'greeting' => 'Hi :name,',
        'intro' => 'Welcome to :app! Please confirm your email address so we can keep your account secure.',
        'action' => 'Confirm email',
        'outro' => 'If you did not create an account, you can safely ignore this email.',
    ],

    'reset' => [
        'subject' => 'Reset your :app password',
        'greeting' => 'Hi :name,',
        'intro' => 'We received a request to reset the password for your account.',
        'action' => 'Choose a new password',
        'expires' => '{1} This link expires in :count minute.|[2,*] This link expires in :count minutes.',
        'outro' => 'If you did not request a password reset, no further action is required — your password stays the same.',
    ],

    'invitation' => [
        'subject' => ':inviter invited you to :workspace on :app',
        'heading' => 'Join :workspace',
        'intro' => ':inviter has invited you to collaborate in the “:workspace” workspace on :app.',
        'role_label' => 'Your role',
        'expires_label' => 'Invitation valid until',
        'action' => 'Accept invitation',
        'outro' => 'If you were not expecting this invitation, you can ignore this email.',
    ],

    'escalation' => [
        'subject' => 'A question needs your answer in :workspace',
        'intro' => ':name asked the assistant in “:workspace”, but no approved document answers it:',
        'action' => 'Open unanswered questions',
    ],

    'privacy' => [
        'greeting' => 'Hi :name,',
        'cancel_action' => 'Review or cancel',
        'not_you' => 'If you did not request this, cancel it right away and change your password.',
        'export_ready' => [
            'subject' => 'Your :app data export is ready',
            'heading' => 'Your data export is ready',
            'intro' => 'The copy of your personal data in :app you requested is ready to download.',
            'intro_workspace' => 'The export of the workspace “:workspace” you requested is ready to download.',
            'action' => 'Download export',
            'expires' => 'For your security the link works only while you are signed in and expires on :date.',
            'outro' => 'The archive contains JSON files and the files you uploaded.',
        ],
        'account_deletion' => [
            'subject' => 'Your :app account is scheduled for deletion',
            'heading' => 'Account deletion scheduled',
            'intro' => 'Your :app account and all its personal data will be permanently deleted on :date. Until then you can sign in and cancel the deletion.',
        ],
        'workspace_deletion' => [
            'subject' => 'Workspace “:workspace” is scheduled for deletion',
            'heading' => 'Workspace “:workspace” will be deleted',
            'intro' => 'The workspace “:workspace”, its documents, AI history and files will be permanently deleted on :date. An owner can cancel this until then.',
        ],
        'opt_out' => [
            'notice' => 'You receive this automated message because a :app customer sent it to you.',
            'action' => 'Unsubscribe',
        ],
    ],

    'billing' => [
        'payment_failed' => [
            'subject' => 'Payment failed for :workspace on :app',
            'heading' => 'We could not process your payment',
            'intro' => 'The latest payment for the :plan plan of the workspace “:workspace” did not go through. Your plan stays active while we retry.',
            'amount' => 'Amount due: :amount.',
            'next_attempt' => 'We will try to charge your payment method again on :date.',
            'no_retry' => 'No further automatic attempts are scheduled — please update your payment method.',
            'action' => 'Update payment method',
            'invoice' => 'You can also pay the invoice directly:',
            'invoice_link' => 'open invoice',
            'outro' => 'If the payment keeps failing, the subscription will be canceled and the workspace will move to the free plan.',
        ],
        'trial_ending' => [
            'subject' => 'Your :app trial for :workspace ends soon',
            'heading' => 'Your trial ends soon',
            'intro' => 'The free trial of the :plan plan for the workspace “:workspace” ends on :date.',
            'renews' => 'After that the subscription renews automatically with the payment method on file. You can change the plan or cancel at any time before then.',
            'cancels' => 'The subscription is set to cancel at the end of the trial, so the workspace will move to the free plan.',
            'action' => 'Review billing',
        ],
    ],
];
