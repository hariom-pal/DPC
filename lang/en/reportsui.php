<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reports screen
|--------------------------------------------------------------------------
|
| Change item 7: every user-visible string comes from a language file, and no
| screen carries hardcoded text.
|
| Keys are nested to mirror the screen they serve, so `reportsui.index.by_department` reads as the
| path to the label rather than as a flat identifier. Strings that appear on more
| than one screen live in `ui.php` instead — shared because they *are* shared,
| not because somebody guessed they might be.
|
|
*/

return [
    'index' => [
        'all_departments' => 'Every department, across the Commission.',
        'by_department' => 'By department',
        'by_the_date_the_department_filed_the_proposal_fo' => 'By the date the department filed the proposal for, not the day it was entered.',
        'circulation_notes_signed' => 'Circulation notes signed',
        'circulations_raised' => '<span>Circulation notes raised <span class="text-muted">S45–S48</span></span>',
        'corrections_and_circulations' => 'Corrections and circulations',
        'counted_from_records' => 'Counted from stored records; nothing here is recalculated.',
        'departments_with_no_dpc_yet_are_omitted_rather_t' => 'Departments with no DPC yet are omitted rather than listed as zeroes.',
        'dpcs_by_year' => 'DPCs by year',
        'export_pipeline_csv' => 'Export pipeline (CSV)',
        'forwarded' => 'Forwarded',
        'no_dpc_has_been_raised_yet' => 'No DPC has been raised yet.',
        'results_approved' => 'Results approved',
        'reviews_raised' => '<span>Review DPCs raised <span class="text-muted">S35–S44</span></span>',
        's35_s44' => '· S35–S44',
        's45_s48' => '· S45–S48',
        'scoped_lead' => ':department — your department\'s own DPCs.',
        'the_stages_of_the_process_in_the_order_the_proce' => 'The stages of the process, in the order the process runs them.
                        A DPC appears against every stage it has reached, so these do not sum to a total.',
        'the_two_flows_that_act_on_a_dpc_after_it_has_con' => 'The two flows that act on a DPC after it has concluded.',
        'where_the_dpcs_are' => 'Where the DPCs are',
    ],
];
