<?php

declare(strict_types=1);

// The one-time temporary password screen (business decision, 2026-10-26).
return [
    'issued' => [
        'title' => 'Temporary password',
        'created_heading' => 'Account created for :name',
        'reset_heading' => 'New temporary password for :name',
        'shown_once' => 'This password is shown only now. It is not stored anywhere in readable form and cannot be displayed again. Copy it and hand it over securely.',
        'previous_invalid' => 'The previous password no longer works, and the officer has been signed out everywhere.',
        'login_id' => 'Login ID',
        'temporary_password' => 'Temporary password',
        'copy' => 'Copy password',
        'copied' => 'Copied',
        'must_change' => 'The officer must change this password at first login. The e-mail (and the mobile, unless waived) is verified by OTP at that login.',
        'done' => 'Done',
    ],
];
