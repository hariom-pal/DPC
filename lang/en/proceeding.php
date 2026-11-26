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
    // 2026-11-24: the DPC Chairman opens the DPC to the committee members.
    'member_view' => [
        'heading' => 'Committee members\' view',
        'closed' => 'The committee members do not see this DPC yet. Open it to them; it opens to them on its own on :date.',
        'open' => 'Open to the committee members since :date.',
        'open_auto' => 'Open to the committee members since :date (opened on its own).',
        'activate' => 'Activate view for committee members',
        'activated' => 'The DPC is now open to the committee members.',
        'not_yours' => 'Only the DPC Chairman opens the DPC to the members, while it is still closed to them.',
        'timeline_activated' => ':name opened the DPC to the committee members.',
        'timeline_auto' => 'The DPC opened to the committee members on its own on :date: the DPC Chairman\'s days passed.',
    ],
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
        'ok_after_accept' => 'Agree and Disagree are recorded at the sitting, once the DPC Chairman has accepted the DPC.',
        'meeting_only_chairman' => 'Only the DPC Chairman starts the meeting, while the DPC is with the committee.',
        'edit_record' => 'Edit this record',
        'edit_record_help' => 'As DPC Chairman you may change anything in this candidate\'s record today, yourself. Each change is recorded, and every member\'s Agree on this candidate is to be given again.',
        'chairman_editing' => 'You are editing this record as DPC Chairman at the sitting. Each change is recorded on the timeline, and every member\'s Agree on this candidate is to be given again.',
        'ok_pending' => 'Not yet',
        // 2026-11-25: one Agree on the whole DPC, then Disagree by candidate.
        'agree_heading' => 'Your Agree on this DPC',
        'agree' => 'Agree',
        'agree_hint' => 'One Agree covers every candidate of this DPC. Then, against any candidate you do not agree on, choose Disagree and give your remark for the DPC Chairman.',
        'agree_changed' => ':count candidate(s) changed since your Agree: Agree again to cover them.',
        'agreed_yours' => 'You agreed',
        'agreed_short' => 'Agreed',
        'disagree_hint' => 'Against any candidate you do not agree on, choose Disagree in the list below and give your remark.',
        'agree_column' => 'Agree / Disagree',
        'agreed_count' => 'Agreed: :n of :of members',
        'disagree' => 'Disagree',
        'disagree_remark' => 'Your remark for the DPC Chairman',
        'disagree_submit' => 'Record Disagree',
        'disagree_confirm' => 'Record Disagree on this candidate? It cannot be withdrawn.',
        'disagreed_yours' => 'You disagreed',
        'disagree_recorded' => 'Your Disagree on :name is recorded and sent to the DPC Chairman.',
        'agree_recorded' => 'Your Agree is recorded on :count candidate(s).',
        'already_agreed' => 'Your Agree is already recorded on every candidate.',
        'agree_first' => 'Record your Agree on the DPC first; then you may disagree on a candidate.',
        'already_disagreed' => 'Your Disagree on this candidate is already recorded. It cannot be changed.',
        'disagree_remark_required' => 'Give your remark: it says why you disagree.',
        'disagreements_heading' => 'Members who disagree',
        'agree_on_list' => 'Record your Agree on the DPC from the candidate list.',
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
