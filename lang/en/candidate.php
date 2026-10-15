<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Promotion Candidate portal
|--------------------------------------------------------------------------
|
| Change item 7: every user-visible string comes from a language file, and no
| screen carries hardcoded text.
|
| Keys are nested to mirror the screen they serve, so `candidate.index.my_dpc` reads as the
| path to the label rather than as a flat identifier. Strings that appear on more
| than one screen live in `ui.php` instead — shared because they *are* shared,
| not because somebody guessed they might be.
|
|
*/

return [
    'index' => [
        'my_dpc' => 'My DPC',
        'the_departmental_promotion_committee_proceedings' => 'The Departmental Promotion Committee proceedings you have been named in.',
        'you_are_not_currently_named_in_any_dpc_that_has' => 'You are not currently named in any DPC that has been filed with the Commission.
                                        A proposal your department is still drafting does not appear here.',
    ],
    'show' => [
        'back' => '← Back',
        'declared_on' => 'Declared on :date.',
        'declared_promoted' => 'Declared on :date. The promotion order is issued by your department.',
        'employee_number' => 'Employee Number',
        'final_result' => 'Final result',
        'if_any_of_this_is_wrong_it_is_your_department_s' => 'If any of this is wrong, it is your department\'s record that needs correcting — the
                            Commission acts on what the department filed.',
        'my_details_as_my_department_filed_them' => 'My details, as my department filed them',
        'not_recommended_for_promotion_in_this_dpc' => 'Not recommended for promotion in this DPC',
        'nothing_recorded_yet' => 'Nothing recorded yet.',
        'progress' => 'Progress',
        'recommended_for_promotion' => 'Recommended for promotion',
        'seniority_number' => 'Seniority Number',
        'the_result_of_this_dpc_has_not_been_declared_yet' => 'The result of this DPC has not been declared yet. It appears here once the Commission
                            publishes its proceedings.',
        'your_data_heading' => 'The data your department recorded about you',
        'your_data_lead' => 'This screen shows the exercise and where it has reached. To check the particulars your department recorded — your dates, category, qualification and year-by-year entries — use :link. If any of it is wrong, raise it with your department in person.',
        'your_data_link' => 'Self-Inspection',
        'your_entry_is_not_available_on_this_proposal' => 'Your entry is not available on this proposal.',
    ],
    'timeline' => [
        'dpc_cgpsc_accept' => 'The Member accepted the DPC',
        'dpc_cgpsc_allot' => 'The Commission assigned the DPC to a Member',
        'dpc_cgpsc_schedule' => 'The DPC was scheduled',
        'dpc_proceeding_approve_result' => 'The Commission approved the result',
        'dpc_proceeding_open' => 'The DPC was convened',
        'dpc_proposal_approve' => 'Your department approved the proposal and forwarded it to the Commission',
        'dpc_proposal_return' => 'The proposal was returned to the Authorized Officer for correction',
        'dpc_proposal_submit' => 'Your department submitted the proposal for approval',
    ],
];
