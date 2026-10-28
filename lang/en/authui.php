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
        'aadhaar' => 'Aadhaar No.',
        'aadhaar_hint' => 'Used only to send the OTP. CGPSC does not store Aadhaar.',
        'aadhaar_otp' => 'Aadhaar OTP (sent to the mobile linked with Aadhaar)',
        'administrative_head_level_2' => 'Administrative Head (Level-2) (AH)',
        'change' => 'Change',
        'confirm_role_change' => 'I confirm this change of seat. The officer\'s present seat in this department will become vacant.',
        'department_details_hint' => 'From the Department Master. To correct them, contact the CGPSC Administrator.',
        'department_email' => 'Department Contact Email',
        'department_name' => 'Department Name',
        'department_registration_e_dpc_portal' => 'Department Registration — e-DPC Portal',
        'designation' => 'Designation',
        'district' => 'District',
        'dpc_authorized_officer_level_1' => 'Authorized Officer for the DPC (Level-1) (AO)',
        'email' => 'E-mail Address (.gov.in only)',
        'email_otp' => 'E-mail OTP',
        'errors' => [
            'already_seated' => 'This officer already holds this seat in this department.',
            'both_seats_first_time' => 'A department registering for the first time registers both the Administrative Head and the Authorized Officer.',
            'department_incomplete' => 'The Department Master does not yet hold this department\'s district, place, PIN and contact e-mail. Ask the CGPSC Administrator to complete them.',
            'email_otp_invalid' => 'The e-mail OTP is incorrect or has expired.',
            'gov_email' => 'The e-mail must be an official .gov.in address.',
            'inactive' => 'This officer\'s account is deactivated. Contact the CGPSC Administrator.',
            'mobile_taken' => 'This mobile number belongs to another account on the portal.',
            'name_required' => 'Enter the officer\'s name.',
            'no_seat' => 'Fill at least one officer.',
            'not_verified' => 'Verify this officer\'s OTPs again: the verification has expired or the details were changed after it.',
            'role_change' => ':name is at present the :current of this department. Registering them as :new leaves their present seat vacant. Tick the confirmation to continue.',
            'send_otp_first' => 'Send the OTPs for this Aadhaar number first.',
        ],
        'existing_officer' => 'Already on the portal: the name is as recorded.',
        'mobile' => 'Mobile No. (linked with Aadhaar)',
        'name' => 'Name',
        'new_officer' => 'New officer: enter the name.',
        'otp_sent' => 'OTPs sent to the Aadhaar-linked mobile and to the e-mail.',
        'pin' => 'PIN',
        'place' => 'Place',
        'register' => 'Register',
        'registered' => 'Registration complete for :department. A new officer receives login credentials by e-mail; an officer already on the portal signs in with their existing password.',
        'registration_of_department' => 'Registration of Department',
        'seat_optional_hint' => 'This department is registered. Fill only the seat that is changing hands; the officer now in it is released.',
        'select_department' => '— Select Department —',
        'send_otp' => 'Send OTP',
        'text' => 'Department registration',
        'verified' => 'OTPs verified.',
        'verified_badge' => 'Verified',
        'verify_otp' => 'Verify OTP',
        'what_happens_after_you_submit' => 'What happens after you submit',
        'what_happens_next' => 'Each officer verifies an Aadhaar OTP and an e-mail OTP. On registration a new officer receives login credentials by e-mail; an officer already on the portal keeps their account and password.',
    ],
    'verify' => [
        'email_otp_sent_to' => 'Email OTP (sent to :email)',
        'mobile_otp_sent_to' => 'Mobile OTP (sent to :mobile)',
        'otp_verification' => 'OTP Verification',
        'verify' => 'Verify',
        'resend' => 'Resend OTP',
        'mobile_waived' => 'Mobile OTP is waived for this account (SMS gateway unavailable); the mobile stays recorded as not verified.',
    ],
    // OTP limits and delivery (business decision, 2026-10-26).
    'otp' => [
        'challenge_sent' => 'Enter the one-time passwords just sent to you to complete sign-in. They are valid for 5 minutes.',
        'too_many_attempts' => 'Too many incorrect OTPs. The codes have been withdrawn; sign in again to receive new ones.',
        'account_inactive' => 'This account is deactivated. Please contact the CGPSC administrator.',
        'sign_in_again' => 'Your session has ended. Please sign in again.',
        'no_channel' => 'This account has no e-mail or mobile number that can receive a one-time password. Please contact your Department Admin.',
        'invalid_pair' => 'One of the OTPs is incorrect or has expired. Nothing was used up: correct the code and try again.',
        'invalid' => 'The OTP is incorrect or has expired. Request a new one if needed.',
        'resent' => 'A new OTP has been sent.',
        'cooldown' => 'Please wait :seconds seconds before requesting another OTP.',
        'hourly_limit' => 'Too many OTPs were requested in the last hour. Try again later.',
        'delivery_failed' => 'The OTP e-mail could not be sent. Please try again in a few minutes; if it keeps failing, contact the CGPSC Administrator.',
        'verified_login' => 'Verification complete. You may now log in.',
    ],
    // Account notices: never the password (business decision, 2026-10-26).
    'credentials' => [
        'created' => [
            'subject' => 'ODPC Portal — your account',
            'message' => 'An ODPC Portal account has been created for you. Login ID: :login. Your administrator will give you a temporary password, which you must change at first login: :url',
        ],
        'reset' => [
            'subject' => 'ODPC Portal — password reset',
            'message' => 'The password of your ODPC Portal account (:login) has been reset by your administrator. Ask them for the new temporary password and change it at first login: :url. If you did not expect this, contact your administrator.',
        ],
    ],
];
