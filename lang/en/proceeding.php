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
        'not_scheduled' => 'This DPC has no sitting date yet.',
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
        'loyalty' => 'Integrity certificate',
        'property' => 'Property file',
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

        // 2026-11-18: no candidate is called; the DPC Chairman starts the meeting and
        // each member records an OK on each record.
        'meeting_initiate' => 'Initiate meeting',
        'meeting_restart' => 'Start a new meeting',
        'meeting_join' => 'Join meeting',
        'meeting_live' => 'Meeting started',
        'meeting_started_by' => 'by :name at :at',
        'meeting_none' => 'The meeting has not been started. The DPC Chairman starts it, and every member is sent the link to join.',
        'meeting_started' => 'The meeting has started. Every other member has been sent the link to join it.',
        'meeting_not_started' => 'The DPC Chairman has not started the meeting yet.',
        'meeting_server_missing' => 'No meeting server is configured. Ask the Administrator to set DPC_MEETING_BASE_URL to the meeting server of the Commission or NIC.',
        'meeting_only_chairman' => 'Only the DPC Chairman starts the meeting, while the DPC is with the committee.',
        'ok' => 'OK',
        'ok_hint' => 'Record OK once you have gone through this record.',
        'ok_heading' => 'OK',
        'ok_yours' => 'OK recorded',
        'ok_pending' => 'Not yet',
        'ok_count' => ':n of :of members',
        'ok_by' => 'OK by',
        'ok_recorded' => 'Your OK on :name is recorded.',
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
