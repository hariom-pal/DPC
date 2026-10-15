<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Login, registration and verification screens
|--------------------------------------------------------------------------
|
| Change item 7: every user-visible string comes from a language file, and no
| screen carries hardcoded text.
|
| Keys are nested to mirror the screen they serve, so `authui.change_password.change_password` reads as the
| path to the label rather than as a flat identifier. Strings that appear on more
| than one screen live in `ui.php` instead — shared because they *are* shared,
| not because somebody guessed they might be.
|
|
*/

return [
    'role_switch' => [
        'now_acting_as' => 'You are now acting as :role.',
        'unknown' => 'That is not a role in this portal.',
    ],
    'change_password' => [
        'change_password' => 'Change Password',
        'confirm_new_password' => 'Confirm new password',
        'current_temporary_password' => 'Current (temporary) password',
        'hint_password' => 'Min 8 chars with upper &amp; lower case, a number and a symbol.',
        'new_password' => 'New password',
        'update_password' => 'Update Password',
        'you_are_using_a_system_issued_password_please_se' => 'You are using a system-issued password. Please set a new password to continue.',
    ],
    'login' => [
        'departmental_promotion_committee_portal' => 'Sign in · Departmental Promotion Committee Portal',
        'email_or_mobile' => 'Email or Mobile',
        'login_e_dpc_portal' => 'Login — e-DPC Portal',
        'new_department' => 'New department?',
        'officer_under_consideration' => 'An officer under consideration for promotion?',
        'password' => 'Password',
        'register_here' => 'Register here',
        'remember_me_on_this_device' => 'Remember me on this device',
        'signing_in' => 'Signing in',
        'trouble_signing_in_contact_your_department_s_nod' => 'Trouble signing in? Contact your department\'s nodal officer.',
        'view_your_own_data' => 'View your own data',
    ],
    'register' => [
        'administrative_head_level_2' => 'Administrative Head (Level-2)',
        'department_name' => 'Department Name',
        'department_registration_e_dpc_portal' => 'Department Registration — e-DPC Portal',
        'designation' => 'Designation',
        'district' => 'District',
        'dpc_authorized_officer_level_1' => 'Authorized Officer for the DPC (Level-1)',
        'email_gov_in' => 'Email (.gov.in)',
        'email_only_gov_id_department_contact_email' => 'Department Contact Email (.gov.in only)',
        'mobile' => 'Mobile',
        'mobile_2' => 'Mobile No.',
        'name' => 'Name',
        'pin' => 'PIN',
        'place' => 'Place',
        'register' => 'Register',
        'registration_of_department' => 'Registration of Department',
        'select_department' => '— Select Department —',
        'text' => 'Department registration',
        'what_happens_after_you_submit' => 'What happens after you submit',
        'what_happens_next' => 'Your details are verified by e-mail and mobile OTP. The Commission then approves the registration and issues credentials to the Authorized Officer and the Administrative Head.',
    ],
    'verify' => [
        'email_otp_sent_to' => 'Email OTP (sent to :email)',
        'mobile_otp_sent_to' => 'Mobile OTP (sent to :mobile)',
        'otp_verification' => 'OTP Verification',
        'verify' => 'Verify',
    ],
];
