<?php
return [
    'send_invitation' => 'Send invitation email',
    'optional_password' => 'Temporary password (optional)',
    'invitation_subject' => 'You have been invited to :business',
    'invitation_body' => ':name, your account for :business is ready. Sign in to choose your password and get started.',
    'invitation_action' => 'Accept invitation',
    'invitation_queued' => 'Account created. The invitation is queued for delivery.',
    'invitation_created' => 'Account created. They can sign in with their email address.',
    'errors' => [
        'validation_failed' => 'Check the details and try again.',
        'version_conflict' => 'This record changed while you were editing. Refresh and review the latest details.',
        'conflict' => 'That business address is already in use. Choose another.',
        'identity_unavailable' => 'That email address is already connected to an account.',
        'last_owner' => 'Invite another owner before deactivating this account.',
        'self_deactivation' => 'You cannot deactivate your own account.',
        'already_reviewed' => 'This request has already been reviewed.',
        'export_too_large' => 'Narrow the search before exporting more than 5,000 businesses.',
        'not_found' => 'This record is no longer available.',
    ],
    'last_owner' => 'Invite another owner before deactivating this account.',
    'self_deactivation' => 'You cannot deactivate your own account.',
];
