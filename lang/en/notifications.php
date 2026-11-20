<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Notification centre
|--------------------------------------------------------------------------
|
| Change item 7: every user-visible string comes from a language file, and no
| screen carries hardcoded text.
|
| Keys are nested to mirror the screen they serve, so `notifications.index.new` reads as the
| path to the label rather than as a flat identifier. Strings that appear on more
| than one screen live in `ui.php` instead — shared because they *are* shared,
| not because somebody guessed they might be.
|
|
*/

return [
    'index' => [
        'new' => 'New',
        'notifications_are_also_sent_by_sms_and_e_mail_on' => 'Notifications are also sent by SMS and e-mail once those gateways are
                connected. This list does not depend on them.',
    ],
    'registration' => [
        'posted' => [
            'subject' => 'You are registered as :role of :department',
            'message' => 'You have been registered on the ODPC Portal as the :role of :department. Sign in with your existing password.',
        ],
        'replaced' => [
            'subject' => 'Your seat as :role of :department has been taken',
            'message' => 'Another officer has been registered as the :role of :department on the ODPC Portal. You no longer hold that seat; you can still sign in, and a fresh registration posts you again.',
        ],
    ],
    'promotion_order' => [
        'subject' => 'Promotion order :number issued',
        'message' => 'Promotion order :number dated :date for the post of :post has been issued. Open the order: :link',
    ],
    'stage' => [
        'reminder_subject' => 'ODPC Portal — :dpc: deadline :due',
        'reminder_body' => ':dpc is with you (:stage): :action. The deadline is :due. Open it: :url',
        'overdue_subject' => 'ODPC Portal — :dpc: deadline passed',
        'overdue_body' => ':dpc is with you (:stage): :action. The deadline of :due has passed. Open it: :url',
    ],
    'sitting' => [
        'meeting_subject' => 'ODPC Portal — :dpc: the DPC meeting has started',
        'meeting_body' => 'The DPC Chairman (:chairman) has started the meeting of :dpc, held on :when. Sign in to the e-DPC Portal at that time and join it: :url',
        // Business clarification of 2026-09-05: the message the committee and the
        // candidates receive when the DPC Allottee fixes or moves the sitting.
        'subject_candidate_scheduled' => 'ODPC Portal — the DPC for your promotion is fixed',
        'subject_candidate_rescheduled' => 'ODPC Portal — the date of the DPC for your promotion has changed',
        'sms_candidate_scheduled' => 'Dear :name, the DPC (:dpc) considering your promotion will meet on :date at :time. ODPC Portal.',
        'sms_candidate_rescheduled' => 'Dear :name, the DPC (:dpc) considering your promotion has been moved to :date at :time. ODPC Portal.',
        'subject_scheduled' => 'ODPC Portal — :dpc has been scheduled',
        'subject_rescheduled' => 'ODPC Portal — :dpc has been re-scheduled',
        'sms_scheduled' => 'ODPC: :dpc is scheduled for :date at :time. :instructions',
        'sms_rescheduled' => 'ODPC: :dpc has been MOVED to :date at :time. Please note the new date. :instructions',
        'joining_instructions' => 'Please attend on time. Committee members should sign in to the e-DPC Portal to take up the sitting; officers under consideration need not attend and may check their own record through Self-Inspection. Do not share this message.',
    ],
    'candidate_verification' => [
        'opened' => [
            'subject' => 'ODPC: verify your record for :dpc by :deadline',
            'message' => 'ODPC: your record for :dpc is ready for your verification. Sign in at :url with your registered mobile number and OTP, check each section, and agree or raise a grievance by :deadline. One final submission only.',
        ],
        'grievance_review' => [
            'subject' => 'ODPC: :count candidate grievance(s) on :dpc to review',
            'message' => 'ODPC: the candidate verification of :dpc has closed with :count grievance(s). Review and decide each at :url.',
        ],
        'correction_assigned' => [
            'subject' => 'ODPC: correction assigned on :dpc',
            'message' => 'ODPC: the Administrative Head has opened :section of :candidate on :dpc for your correction. Correct it and submit it back at :url.',
        ],
        'correction_submitted' => [
            'subject' => 'ODPC: correction submitted on :dpc',
            'message' => 'ODPC: the Authorized Officer has submitted the correction of :section of :candidate on :dpc. Review it and decide the grievance at :url.',
        ],
        'grievance_resolved' => [
            'subject' => 'ODPC: your grievance on :dpc is decided',
            'message' => 'ODPC: the Administrative Head has decided your grievance on :section of :dpc. See the decision at :url.',
        ],
        'record_corrected' => [
            'subject' => 'ODPC: your record on :dpc was corrected',
            'message' => 'ODPC: :section of your record on :dpc was corrected after your grievance. See the corrected information at :url.',
        ],
        'secretary_ready' => [
            'subject' => 'ODPC: :dpc is verified and with you',
            'message' => 'ODPC: the candidate verification of :dpc is complete, with no grievance outstanding. It is with you at :url.',
        ],
    ],
];
