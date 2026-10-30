<?php

declare(strict_types=1);

// eSign verification for the AO's submission and the AH's approval (2026-10-27).
return [
    'button' => [
        'submit' => 'eSign and submit for approval',
        'approve' => 'eSign and approve',
        'approve_dpc' => 'eSign, approve and forward to CGPSC',
    ],
    'page' => [
        'title' => 'eSign',
        'title_pre-dpc-submit' => 'eSign and submit the Pre-DPC',
        'title_pre-dpc-approve' => 'eSign and approve the Pre-DPC',
        'title_dpc-approve' => 'eSign and approve the DPC',
        'title_dpc-submit' => 'eSign and submit the DPC proposal',
        'lead' => 'Every submission and approval is signed with a fresh eSign; an earlier eSign never counts for this one.',
        'what' => 'What you are signing',
        'document' => 'Document',
        'signer' => 'Signing as',
        'name_rule' => 'The name on your eSign must match your portal name, :name. If it does not, nothing moves on and your Department Admin is told.',
        'consent' => 'I consent to sign this document with my eSign and confirm I have read it.',
        'proceed' => 'Proceed to eSign',
        'simulated' => 'Development / UAT: the eSign is simulated on the next screen. No real eSign takes place.',
    ],
    'consent_required' => 'Give your consent to proceed to eSign.',
    'done' => [
        'submit' => 'eSigned and submitted for approval.',
        'approve' => 'eSigned and approved.',
    ],
    'simulator' => [
        'title' => 'Simulated eSign',
        'warning' => 'Development and testing only — this is not an eSign provider. Enter the name the eSign certificate would carry.',
        'name' => 'Name on the eSign',
        'name_help' => 'Change it to test a name that does not match the portal name.',
        'sign' => 'Sign',
        'cancel' => 'Cancel eSign',
    ],
    'issue' => [
        'badge' => 'eSign issue',
        'title' => 'eSign issue — not forwarded',
        'name_mismatch' => 'The eSign of :officer on :date carried the name ":asserted", which does not match the portal name ":portal".',
        'other' => 'The eSign of :officer on :date did not go through: :reason',
        'next' => 'The Department Admin can choose another officer or correct this officer\'s details; the officer then signs again.',
    ],
    'errors' => [
        'esign_failed' => 'The eSign did not complete. Nothing was submitted or approved; try again.',
        'name_mismatch' => 'The name on the eSign does not match your name registered on the portal. Nothing was submitted or approved.',
        'not_correlated' => 'The eSign response does not belong to this document and request. Nothing was submitted or approved.',
        'replayed' => 'This eSign transaction has already been used. Sign again.',
        'not_current_officer' => 'You are no longer the officer allotted to this proposal in this role.',
        'unavailable' => 'Digital signing is unavailable on this environment. Contact the system administrator.',
    ],
];
