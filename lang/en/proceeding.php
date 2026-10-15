<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The sitting, from a committee member's side
|--------------------------------------------------------------------------
|
| Business instruction of 2026-09-07. A committee member holds no role, so
| these screens sit outside the /cgpsc group and are authorised by a seat on
| the proposal's own committee.
|
*/

return [
    'sitting' => [
        'title' => 'My DPC Sittings',
        'lead' => 'The DPCs you sit on. Open one to see its candidates.',
        'none' => 'You are not on any DPC committee yet. When a department seats you on one, it appears here.',
        'open_candidates' => 'Open candidate list',
        'candidates' => 'Candidates',
        'scheduled_for' => 'Sitting: :when',
        'in_session' => 'In session now',
        'not_scheduled' => 'This DPC has no sitting date yet. Calling opens once the date and time are set.',
        'opens_at' => 'Calling opens at :when, on the day of the sitting.',
        'window_open' => 'The sitting is open. You can call candidates now.',
        'not_open' => 'The sitting has not been opened yet. The member this DPC is allotted to opens it.',
        'not_seated' => 'You are not on this DPC\'s committee.',
        'call_first' => 'Call this candidate before the committee first.',

        'status' => 'Status',
        'waiting_badge' => 'Not called',
        'called_badge' => 'Called',
        'concluded_badge' => 'Concluded',
        'open_record' => 'Open record',
        'back_to_candidates' => 'Back to the candidate list',

        'record' => 'Record',
        'chart' => 'Evaluation Chart',
        'chart_empty' => 'No evaluation data has been entered for this officer yet.',
        'loyalty' => 'Loyalty certificate',
        'property' => 'Property return',
        'gaps' => 'Recorded gaps',
        'joined_service' => 'Joined service',
        'posted_on_feeder' => 'Posted on present post',
        'posted_on_promotion_post' => 'Posted on promotion post',
        'tribal_years' => 'Tribal area service (years)',
        'other_category_seat' => 'on another category\'s seat',
        'result' => 'Calculated result',
        'score' => 'Score',
        'eligible' => 'Eligible',
        'super_seat' => 'Super Seat',
        'list' => 'List',
        'remark' => 'Remark',
        'cr_record' => 'Confidential Reports',
        'cr_grade' => 'CR Grade',
        'tribal_area' => 'Tribal area service',

        'call' => 'Call this candidate',
        'call_again' => 'Call again',
        'called' => ':name has been called before the committee.',
        'called_by' => 'Called by :name at :at',
        'conclude' => 'Mark concluded',
        'concluded' => 'The committee has concluded :name.',
        'concluded_by' => 'Concluded by :name at :at',
        'remarks' => 'Remarks',
        'remarks_hint' => 'Anything the committee wants on the record beside this candidate',
    ],
];
