<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Self-Inspection & Assessment (S49–S51)
|--------------------------------------------------------------------------
|
| Change item 7: every user-visible string comes from a language file, and no
| screen carries hardcoded text.
|
| Keys are nested to mirror the screen they serve, so `selfinspection.identify.confirm_your_identity` reads as the
| path to the label rather than as a flat identifier. Strings that appear on more
| than one screen live in `ui.php` instead — shared because they *are* shared,
| not because somebody guessed they might be.
|
|
*/

return [
    'identify' => [
        'confirm_your_identity' => 'Self-Inspection · Confirm your identity',
        'continue' => 'Continue',
        'details_not_matching' => 'Details not matching? They must match what your <strong>department</strong> recorded, which may differ from your own papers — that is itself worth raising with them.',
        'more_information' => 'More information',
        'not_available' => 'Self-Inspection is not available at the moment.',
        'self_inspection' => 'Self-Inspection',
        'self_inspection_identify_yourself' => 'Self-Inspection — Identify yourself',
        'why_these_details' => 'Why these details',
        'you_do_not_have_an_account_on_this_portal_you_ar' => 'You do not have an account on this portal — you are not a departmental user.
                            So instead of a password, you confirm facts that your department recorded
                            about you on the proposal it forwarded.',
    ],
    'landing' => [
        'for_officers_only' => 'This screen is for the officer under consideration. Departmental users should :link instead.',
        'if_the_data_is_wrong' => 'If the data is wrong:',
        'if_wrong' => '<strong>If the data is wrong,</strong> raise it with your <strong>department</strong>, in person. The Chhattisgarh Public Service Commission does not consider representations about departmental data.',
        'lead' => 'If your department has forwarded a Departmental Promotion Committee proposal that includes you, you may view <strong>the data it recorded about you</strong> before the Committee sits.',
        'self_inspection_amp_assessment' => 'Self-Inspection & Assessment',
        'self_inspection_assessment_e_dpc_portal' => 'Self-Inspection & Assessment — e-DPC Portal',
        'self_inspection_is_currently_closed' => 'Self-Inspection is currently closed.',
        'sign_in' => 'sign in',
        'text' => 'Self-Inspection · departmental officers and staff may view the data recorded about them',
        'the_data_it_recorded_about_you' => 'the data it recorded about you',
        'the_facility_is_opened_by_the_commission_while_d' => 'The facility is opened by the Commission while departmental proposals are
                                    under consideration. Please try again later, or ask your department.',
        'view_my_data' => 'View my data',
    ],
    'partials' => [
        'notice' => [
            'if_you_have_an_objection_to_this_data' => 'If you have an objection to this data',
            'in_person_at_your_department' => 'in person at your department',
            'in_person_note' => '<strong>in person at your department</strong>. The Chhattisgarh Public Service Commission will not consider any representation in this matter; such representations are consigned to file.',
            'no_date_is_shown_because_the_sitting_for_this_co' => 'No date is shown because the sitting for this Committee has not been scheduled yet.
            Raise any correction with your department as soon as you can — once the Committee has
            sat, the data it considered cannot be changed.',
            'on_or_before' => '<strong>on or before :date</strong>,',
            'raise_objection' => 'Raise any objection to this data',
            'text' => 'by attending your department in person',
            'this_date_has_passed_raise_the_matter_with_your' => 'This date has passed. Raise the matter with your department immediately; a correction
            may no longer be possible.',
        ],
        'record' => [
            'your_department_has_recorded_no_year_wise_cr_loy' => 'Your department has recorded no year-wise CR, loyalty or immovable-property entries against
        your name on this proposal. If that is not correct, raise it with them.',
        ],
    ],
    'print' => [
        'chhattisgarh_public_service_commission_online_dp' => 'Self-Inspection · Chhattisgarh Public Service Commission — Online DPC',
        'disclaimer' => 'This is a copy of the data supplied by the department for consideration by the Departmental Promotion Committee. <strong>It is not a promotion order, a result or a decision</strong>, and it carries no mark, remark or assessment. It is unsigned and is issued for the officer\'s own inspection.',
        'it_is_not_a_promotion_order_a_result_or_a_decisi' => 'It is not a promotion order, a result, or a decision of any
        kind',
        'print_this_page' => 'Print this page',
        'printed_at' => 'Printed :at',
        'self_inspection_amp_assessment_officer_s_own_dat' => 'Self-Inspection & Assessment — Officer\'s own data',
        'title' => '<title>Self-Inspection — :name</title>',
    ],
    'show' => [
        'choose_proposal' => 'You appear on :count proposals currently under consideration. Choose which one to view:',
        'close' => 'Close',
        'my_data_as_recorded_for_this_dpc' => 'My data as recorded for this DPC',
        'print' => 'Print',
        'self_inspection_my_data' => 'Self-Inspection — My data',
        'this_screen_shows_the_data_your_department_suppl' => 'This screen shows the data your department supplied. It does not show marks, remarks
                or any promotion decision — those are made by the Committee, which considers this data
                at its sitting.',
    ],
    /* Mobile-and-OTP identification (business instruction, 2026-09-07). The officer
       proves they hold the number their department recorded on the Seniority List,
       which is a stronger claim than reciting facts that circulate on one. */
    'otp' => [
        'sms_unavailable' => 'Sign-in by mobile OTP is not available at the moment because the SMS service is not yet running. Please contact your department, or try again later.',
        'mobile_label' => 'Your mobile number',
        'mobile_hint' => 'The ten-digit number your department recorded for you. A one-time code will be sent to it.',
        'send_code' => 'Send me a code',
        'code_label' => 'The six-digit code',
        'code_hint' => 'It is valid for a few minutes. Enter it exactly as received.',
        'code_sent' => 'If that number is on a list under consideration, a code has been sent to :mobile.',
        'code_sent_to' => 'A code was sent to :mobile, if that number is on a list under consideration.',
        'wrong_number' => 'Use a different number',
        'failed' => 'That code did not work. It may have expired, or the number may not be on any list currently under consideration. Ask for a new code and try again.',
    ],
];
