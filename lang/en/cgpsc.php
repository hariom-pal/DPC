<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Commission-side screens — allotment, scheduling, proceedings, circulation
|--------------------------------------------------------------------------
|
| Change item 7: every user-visible string comes from a language file, and no
| screen carries hardcoded text.
|
| Keys are nested to mirror the screen they serve, so `cgpsc.allotments.history.allotment_history` reads as the
| path to the label rather than as a flat identifier. Strings that appear on more
| than one screen live in `ui.php` instead — shared because they *are* shared,
| not because somebody guessed they might be.
|
|
*/

return [
    'allotments' => [
        'history' => [
            'allotment_history' => 'Allotment History',
            'allotted' => 'Allotted',
            'back_to_allotment' => '← Back to allotment',
            'by_on' => 'by :actor on :at',
            'default_dpc_date' => '· default DPC date :date',
            'lead' => 'This history is read <strong>entirely from timeline records</strong> — not reconstructed from the current allotment. Every allotment and re-allotment ever recorded appears below, in order, with the detail captured at the moment it happened.',
            'previously' => '(previously <s>:name</s>)',
            'reallotted' => 'Re-allotted',
            // The automatic return when a completion deadline passes
            // (business clarification, 2026-09-05).
            'returned' => 'Returned to the Chairman',
            'not_concluded_by' => '— not concluded by :date',
            'this_dpc_has_not_been_allotted_yet' => 'This DPC has not been allotted yet.',
        ],
        'index' => [
            'allot' => 'Allot',
            'no_allottable_member' => 'No member of this committee holds a portal account, so there is nobody to allot it to. Add the member as an account on the DPC Committee screen first.',
            'allot_to' => 'Allot to…',
            'allotment_of_dpc' => 'Allotment of DPC',
            'allotted_by' => 'allotted :date by :actor',
            'default_dpc_date_optional' => 'Default DPC date (optional)',
            'complete_by' => 'Must be concluded by',
            'complete_by_hint' => 'The date this DPC must be concluded by. If it is not, the DPC returns to the Chairman for re-allotment.',
            // Business clarification of 2026-09-05: the CGPSC Chairman names who
            // presides in the same act as allotting.
            'dpc_chairman' => 'DPC Chairman',
            'dpc_chairman_choose' => 'Who will preside over this DPC…',
            'dpc_chairman_none' => 'Not yet named',
            'dpc_chairman_no_committee' => 'This DPC has no committee on record, so nobody can be named to preside. The department composes the committee before forwarding.',
            'overdue' => 'Overdue',
            'dpc_allotment_report' => 'DPC Allotment Report',
            'dpc_allotted_to' => 'DPC Allotted To',
            'no_dpc_has_been_allotted_yet' => 'No DPC has been allotted yet.',
            'no_proposal_has_been_forwarded_to_the_commission' => 'No proposal has been forwarded to the Commission yet.',
            'not_yet_allotted' => 'Not yet allotted',
            'proposals_approved_by_their_department_and_forwa' => 'Proposals approved by their department and forwarded to the Commission.
                Allotting never overwrites an earlier allotment — re-allotting records a new one and keeps the old.',
            'proposals_awaiting_action' => 'Proposals Awaiting Action',
            're_allot' => 'Re-allot',
            're_allot_to' => 'Re-allot to…',
            'reallot' => 'Re-allot',
            'reason_for_re_allotment_required' => 'Reason for re-allotment (required)',
            'was' => '<span class="text-muted small">(was <s>:name</s>)</span>',
        ],
    ],
    'circulations' => [
        'documents' => [
            'circulation_documents' => 'Circulation documents',
            'content_hash' => 'Content hash',
            'current_documents' => 'Current documents',
            'document_history' => 'Document history',
            'download' => 'Download',
            'generate_documents' => 'Generate documents',
            'generator' => 'Generator',
            'hash' => 'Hash',
            'heading' => '<h1 class="h4 mb-1">Documents — Circulation :code</h1>',
            'lead' => 'The prescribed circulation note and the revised promotion list, for origin DPC <strong>:code</strong>.',
            'none_generated' => 'No document has been generated for this circulation yet.',
            'not_published' => 'Not published',
            'publish_to_the_department' => 'Publish to the department',
            'regenerating_never_overwrites_a_new_version_is_a' => 'Regenerating never overwrites — a new version is a new record, so what was issued and when
            stays answerable.',
            'verify' => 'Verify',
        ],
        'index' => [
            'awaiting_the_commission' => 'Awaiting the Commission',
            'circulation_dpcs_commission' => 'Circulation DPCs — Commission',
            'departments_asking_the_commission_to_consent_to' => 'Departments asking the Commission to consent to promotions it reserved, now that the missing
            record has been supplied.',
            'nothing_awaiting' => 'Nothing is awaiting the Commission.',
            'promotions_released' => 'Promotions released',
            'recently_signed' => 'Recently signed',
        ],
        'show' => [
            'after' => 'after',
            'before' => 'before',
            'circulation_dpc_commission' => 'Circulation DPC — Commission',
            'consent' => 'Consent',
            'dated' => 'dated :date',
            'decision' => 'Decision',
            'ground_held_on' => 'Ground held on',
            'heading' => '<h1 class="h4 mb-1">Circulation :code</h1>',
            'lead' => 'The DPC is <strong>not re-run</strong>. No officer\'s marks are recalculated and no other officer\'s position changes. What this note decides is whether a promotion the Commission <strong>reserved</strong> now proceeds.',
            'list' => 'List',
            'not_re_run' => 'not re-run',
            'not_yet_decided' => 'Not yet decided',
            'officers_on_this_note' => 'Officers on this note',
            'origin_dpc' => 'Origin DPC <strong>:code</strong>',
            'origin_result_missing' => 'The origin DPC\'s approved result could not be located.',
            'received' => 'Received',
            'released_at' => 'Released :at',
            'remark_columns' => '<th>Remark — <em>before</em></th><th>Remark — <em>after</em></th>',
            'return_to_department' => 'Return to department',
            'return_to_the_department_for_further_evidence' => 'Return to the department for further evidence',
            'run_provenance' => 'Approved calculation run <code>:uuid</code>. Marks and positions are as approved at the original sitting and are identical in both columns.',
            'sign_the_circulation_note_releases_the_consented' => 'Sign the circulation note — releases the consented promotions',
            'the_dpc_result_before_and_after' => 'The DPC result — before and after',
            'what_the_department_supplied' => 'What the department supplied',
        ],
    ],
    'proceedings' => [
        'corrections' => [
            'every_correction_made_during_this_sitting_in_the' => 'Every correction made during this sitting, in the order it was made, with what the value was
                before, what it became, why, and who made it. Withdrawn corrections remain here — nothing is
                ever removed. This history is read from the workflow record itself, not from the current state
                of the chart.',
            'no_corrections_have_been_recorded_in_this_sittin' => 'No corrections have been recorded in this sitting.',
            'withdrawn' => 'Withdrawn',
        ],
        'documents' => [
            'a_re_issued_document_never_replaces_the_one_alre' => 'A re-issued document never replaces the one already given out. Earlier versions stay
                            exactly as they were.',
            'back_to_result' => 'Back to Result',
            'cannot_be_published_to_the_department' => 'cannot be published to the department',
            'dpc_documents' => 'DPC Documents',
            'no_documents_have_been_generated_for_this_dpc_ye' => 'No documents have been generated for this DPC yet.',
            'previous_versions' => 'Previous Versions',
            'proceedings_amp_promotion_list' => 'Proceedings & Promotion List',
            'provisional_explained' => '<strong>This result is provisional.</strong> A business rule affecting it has not been settled, so the documents <strong>cannot be published to the department</strong>. They may still be generated and read here.',
            'publish_to_department' => 'Publish to Department',
            'the_commission_s_decision_was_taken_at_approval' => 'The Commission\'s decision was taken at approval. These documents render it — generating or
                re-issuing one cannot change what was decided.',
        ],
        'history' => [
            'every_step_of_this_dpc_s_proceeding_in_the_order' => 'Every step of this DPC\'s proceeding, in the order it happened, with the person who took it as
                they were named at the time. This history is read from the workflow record itself, not from
                the current state of the DPC — renaming a user or changing a record later cannot rewrite it.',
            'nothing_has_been_recorded_for_this_proceeding_ye' => 'Nothing has been recorded for this proceeding yet.',
            'opened_at' => 'Opened :at.',
            'reference' => 'Reference <code>:uuid</code>',
            'scheduled_for' => 'Scheduled for :date :time.',
        ],
        'index' => [
            'allotted_to' => 'Allotted To',
            'another_member' => 'another Member',
            'awaiting_proceeding' => 'Awaiting Proceeding',
            'conducted_by' => 'Conducted by :actor',
            'dpc_date_amp_time' => 'DPC Date & Time',
            'dpcs_that_have_been_scheduled_and_are_ready_to_b' => 'DPCs that have been scheduled and are ready to be conducted. The sitting is opened by the
                member the DPC is allotted to. A Chairman who wishes to conduct a DPC re-allots it to
                himself first, so that one member remains accountable for the proceeding.',
            'no_dpcs_are_awaiting_a_proceeding' => 'No DPCs are awaiting a proceeding.',
            'not_scheduled' => 'Not scheduled',
            'not_scheduled_2' => 'Not Scheduled',
            'online_dpc_proceedings' => 'Online DPC — Proceedings',
            'open_proceeding' => 'Open Proceeding',
            'open_workspace' => 'Open Workspace',
        ],
        'result' => [
            'affected_officers' => 'Affected: :officers.',
            'approval_note' => 'Approving pins this calculation permanently. A later calculation cannot change what was approved, and an approved result cannot be edited by any role — a correction afterwards is a Review DPC.',
            'back_to_workspace' => 'Back to Workspace',
            'calculate_again' => 'CALCULATE AGAIN',
            'calculated_at' => 'Calculated At',
            'calculation' => 'Calculation',
            'cancel_dpc' => 'CANCEL DPC',
            'closed_by' => 'closed by :actor, :at',
            'corrections_have_been_recorded_since_this_result' => 'Corrections have been recorded since this result was calculated.',
            'current' => 'Current',
            'documents' => 'Documents',
            'dpc_result_approval' => 'DPC Result — Approval',
            'every_calculation_ever_run_for_this_dpc_nothing' => 'Every calculation ever run for this DPC. Nothing is overwritten — recalculating creates
                            a new record and leaves the previous one untouched.',
            'every_officer_on_the_chart_has_a_result_an_offic' => 'Every officer on the chart has a result. An officer appears here when they were
                                not eligible, fell outside the zone of consideration, or their category had no
                                vacancy — so an absence from both lists is explained rather than silent.',
            'holds_explained' => 'These officers took part in the DPC, but the promotion action in respect of each is <strong>reserved</strong> until the matter noted is resolved. A hold is not a finding on merit — an officer can be top-scoring and still held.',
            'main_list' => 'MAIN LIST',
            'no_officers_on_this_list' => 'No officers on this list.',
            'not_calculated_yet' => 'This DPC has not been calculated yet.',
            'not_listed' => 'Not Listed',
            'officers_considered' => 'Officers Considered',
            'online_dpc_approval' => 'Online DPC — Approval',
            'promotion_as_on' => '<h2 class="h6 mb-1">PROMOTION AS ON :date</h2>',
            'promotions_held_cir' => 'Promotions Held (CIR)',
            'provisional_explained' => '<strong>This result is provisional.</strong> A business rule that would affect it has not been settled, so the system has applied the printed rule and flagged the cases rather than guessing.',
            'release' => 'Release',
            'rule_version' => 'Rule Version',
            'run_at' => 'Run At',
            'source' => 'Source',
            'status' => 'Status',
            'subheading' => ':from → :to, :department · DPC ID <code>:code</code>',
            'waiting_list' => 'WAITING LIST',
            'why_this_dpc_is_being_cancelled' => 'Why this DPC is being cancelled',
        ],
        'show' => [
            'cat' => 'Cat',
            'closed_by_at' => 'Closed By / At',
            'corrected_to' => 'Corrected To',
            'corrected_value' => 'Corrected value',
            'corrections_are_recorded_beside_the_department_s' => 'Corrections are recorded beside the department\'s entry, never over it.',
            'corrections_explained' => '<strong>Corrections are recorded beside the department\'s entry, never over it.</strong> A corrected value is shown with the department\'s original struck through. Nothing you do here changes what the department submitted.',
            'corrections_in_force' => 'Corrections in Force',
            'department_entry' => 'Department Entry',
            'dpc_id' => '<div class="text-muted">DPC ID</div>',
            'dpc_proceeding_action' => 'DPC Proceeding — Action',
            'edit' => 'EDIT',
            'evaluation_sheet_amp_final_seniority_list' => 'Evaluation Sheet & Final Seniority List',
            'grade_y_n_or_years' => 'Grade, Y/N, or years',
            'hint_clear_entry' => 'Leave empty to clear the entry.',
            'immovable_property' => '<th colspan=":span">Immovable Property</th>',
            'loyalty' => '<th colspan=":span">Loyalty</th>',
            'no_chart_rows' => 'This proposal has no evaluation chart rows.',
            'not_year_specific_tribal' => 'Not year-specific (Tribal)',
            'not_yet_closed' => 'Not yet closed',
            'online_dpc_action' => 'Online DPC — Action',
            'opened_by_at' => 'Opened By / At',
            'qual' => 'Qual',
            'reason_required' => 'Reason (required)',
            'reference' => '<div>Reference <code>:uuid</code></div>',
            'result_amp_approval' => 'Result & Approval',
            'save' => 'SAVE',
            'scheduled_for' => 'Scheduled For',
            'submission_fingerprint' => 'Department submission fingerprint <code>:hash…</code> — recorded when the sitting opened, so the entries acted on are provable.',
            'tribal' => 'Tribal',
            'what_is_being_corrected' => 'What is being corrected',
            'why_this_correction_is_being_made' => 'Why this correction is being made',
            'year_wise_cr' => '<th colspan=":span">Year-wise CR</th>',
        ],
    ],
    'schedules' => [
        // Business clarification of 2026-09-05: the sitting is bounded by the
        // deadline the CGPSC Chairman set, on scheduling and on rescheduling.
        'beyond_deadline' => 'This DPC must be concluded by :deadline, so it cannot be held on :chosen. Choose an earlier date, or ask the CGPSC Chairman to extend the deadline.',
        'history' => [
            'accepted' => 'Accepted',
            'accepted_the_dpc' => 'accepted the DPC',
            'back_to_scheduling' => '← Back to scheduling',
            'by_on' => 'by :actor on :at',
            'date_at_time' => '<strong>:date</strong> at <strong>:time</strong>',
            'lead' => 'Read <strong>entirely from timeline records</strong> — not reconstructed from the current schedule. Every acceptance, scheduling and re-scheduling ever recorded appears below, with the detail captured at the moment it happened.',
            'previously' => '(previously <s>:date at :time</s>)',
            'rescheduled' => 'Re-scheduled',
            'scheduled' => 'Scheduled',
            'scheduling_history' => 'Scheduling History',
            'this_dpc_has_not_been_accepted_or_scheduled_yet' => 'This DPC has not been accepted or scheduled yet.',
        ],
        'index' => [
            'decline_dpc' => 'Decline this DPC',
            'decline_reason_placeholder' => 'Why you cannot take this DPC — the Chairman allots it again from this',
            'decline_reason_required' => 'State why you are declining this DPC.',
            'declined' => 'DPC declined. It has gone back to the CGPSC Chairman with your reason.',
            'dpc_date_and_time' => 'Date and time of the DPC',
            'on_or_before' => 'On or before :date, the date this DPC must be concluded by.',
            'complete_by' => 'Complete By',
            'accept_dpc' => 'Accept DPC',
            'acceptance_of_dpc_and_scheduling' => 'Acceptance of DPC and Scheduling',
            'acceptance_scheduling_of_dpc' => 'Acceptance & Scheduling of DPC',
            'allotted_to' => '<div class="small text-muted mt-1">Allotted to :name</div>',
            'allotted_to_you' => 'Allotted to You',
            'date_at_time' => '<strong>:date</strong> at <strong>:time</strong>',
            'done' => 'DONE',
            'dpc_date' => 'DPC Date',
            'dpc_time' => 'DPC Time',
            'dpcs_allotted_to_you_accept_a_dpc_then_set_the_d' => 'DPCs allotted to you. Accept a DPC, then set the date and time it will be held.
                The default DPC date shown is the Chairman\'s guidance — it is informational and does not restrict
                the date you may choose.',
            'move' => 'Move',
            'no_dpc_has_been_allotted_to_you_yet' => 'No DPC has been allotted to you yet.',
            'no_dpc_has_been_scheduled_yet' => 'No DPC has been scheduled yet.',
            'reason_for_re_scheduling_required' => 'Reason for re-scheduling (required)',
            'reschedule' => 'Re-schedule',
            'schedule' => 'Schedule',
            'scheduling' => 'Scheduling',
            'scheduling_report' => 'Scheduling Report',
            'was' => '(was <s>:date :time</s>)',
        ],
    ],
];
