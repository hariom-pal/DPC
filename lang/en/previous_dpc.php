<?php

declare(strict_types=1);

/*
 | Coder Instructions rule 1.1: DPCs held offline, entered by the Commission's staff.
 */
return [
    'title' => 'Previous DPCs held offline',
    'lead' => 'Rule 1.1: a DPC held before this portal, entered by the Commission\'s staff so that a department\'s reconciliation can cite it.',
    'new' => 'Record a previous DPC',
    'form_lead' => 'Enter the previous DPC in the same detail as a proposal: the posts, the year and date, the reference and minutes, the promotion order and the officers promoted. The final seniority list and the working officers of the promotion post are required when :count or more employees were eligible, optional below that.',
    'open' => 'Open',
    'none' => 'No previous DPC has been recorded.',
    'recorded' => 'Previous DPC recorded.',
    'recorded_by' => 'Recorded by',
    'officers_help' => 'One officer per line: Employee ID, Name (the Employee ID may be left out).',
    'optional_below' => 'Optional when fewer than :count employees were eligible.',
    'minutes_required' => 'The minutes of the previous DPC are required.',
    'uploads_required' => 'With :count or more eligible employees, the final seniority list and the working officers are required (rule 1.1).',
    'store_failed' => 'A document could not be stored. Try again.',
    'timeline' => 'Previous DPC :reference (:year) recorded.',
    'fields' => [
        'department_id' => 'Department',
        'promotion_from_post_id' => 'Promotion from post',
        'promotion_to_post_id' => 'Promotion to post',
        'dpc_year' => 'DPC year',
        'held_on' => 'Held on',
        'reference' => 'Reference / minutes no.',
        'order_number' => 'Promotion order no.',
        'order_date' => 'Promotion order date',
        'eligible_count' => 'Employees eligible',
        'promoted_officers' => 'Officers promoted',
        'minutes' => 'Minutes (PDF)',
        'seniority_list' => 'Final seniority list (PDF)',
        'working_officers' => 'Working officers of the promotion post (PDF)',
    ],
    'verification' => [
        'record' => 'Recorded by the CGPSC staff',
        'record_help' => 'Choose the previous DPC the Commission\'s staff recorded; its reference, date and minutes are used, nothing is uploaded again.',
        'none' => '— or enter the reference and upload the minutes below —',
        'invalid' => 'Choose a previous DPC recorded for this department and post.',
    ],
];
