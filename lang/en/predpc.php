<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pre-DPC Proposal screens
|--------------------------------------------------------------------------
|
| Change item 7: every user-visible string comes from a language file, and no
| screen carries hardcoded text.
|
| Keys are nested to mirror the screen they serve, so `predpc.form.1_the_promotion_exercise` reads as the
| path to the label rather than as a flat identifier. Strings that appear on more
| than one screen live in `ui.php` instead — shared because they *are* shared,
| not because somebody guessed they might be.
|
|
*/

return [
    'declaration_required' => 'Tick the declaration to go on.',
    'summary' => [
        'title_edit' => 'Edit Pre-DPC Proposal',
        'title_new' => 'New Pre-DPC Proposal',
        'number' => 'Pre-DPC No.',
    ],
    'form' => [
        '1_the_promotion_exercise' => '1. The promotion exercise',
        '2_taken_from_the_posts' => '2. Taken from the posts',
        '2_terms_this_exercise_sanctions' => '2. Terms this exercise sanctions',
        '3_promotion_from_posts' => '3. Promotion From Posts',
        '4_government_order' => '4. Adyatan Vibhagiy Bharti Niyam (Updated Departmental Recruitment Rules)',
        'publish_date' => 'Publish Date',
        'rules_document' => 'Adyatan Vibhagiy Bharti Niyam (PDF)',
        'rules_document_hint' => 'PDF, up to 10 MB.',
        'rules_document_current' => 'Uploaded:',
        'all_fields_required' => 'Every field marked * is required.',
        'share_total_ok' => 'The feeder shares total 100% of the promotion quota.',
        'share_total_short' => 'The feeder shares total :total%. They must total exactly 100% — :short% is unaccounted for, so the proposal cannot be saved.',
        'share_total_over' => 'The feeder shares total :total%, which is :over% more than the promotion quota. They must total exactly 100%.',
        'add_source' => 'Add Promotion From Post',
        'attributes_unavailable' => 'Could not be loaded — read from the post itself when the order is saved',
        'back_to_list' => 'Back to list',
        'both_are_required_before_the_proposal_can_be_sub' => 'Both are required before the proposal can be submitted for approval.',
        'derived_hint' => 'Choose both posts above and these fill in on their own. The Pay Scale and Class come from the Promotion <strong>To</strong> Post — a promotion is <em>into</em> a post, at that post\'s scale — and the Promotion Duration from the Promotion <strong>From</strong> Post, being the qualifying service an officer must already have on it.',
        'direct_recruitment_label' => '<label class="form-label" for="direct_recruitment_pct">Direct Recruitment % <span class="text-danger">*</span></label>',
        'dpc_year_label' => '<label class="form-label" for="dpc_year">DPC Year <span class="text-danger">*</span></label>',
        'experience_unit' => 'Experience unit',
        'experience_unit_label' => '<label class="form-label" for="experience_unit">Unit <span class="text-danger">*</span></label>',
        'government_order_number' => 'Adyatan Vibhagiy Bharti Niyam',
        'hint_from_post' => 'The post officers are promoted <strong>out of</strong>.',
        'hint_shares_total' => 'The two must total exactly 100%.',
        'hint_to_post' => 'The post officers are promoted <strong>into</strong>.',
        'its_pay_scale_and_class' => 'Its Pay Scale & Class',
        'min_experience' => 'Minimum experience',
        'min_experience_label' => '<label class="form-label" for="min_experience_years">Min. Experience <span class="text-danger">*</span></label>',
        'min_qualification' => 'Min. Qualification',
        'qualifications_hint' => 'Select every qualification this feeder accepts. Leave empty if none is prescribed.',
        'no_posts_explained' => 'A Pre-DPC promotes officers from one Department Post into another, so both must exist in the Department Post master before a proposal can be drafted. Ask the CGPSC Administrator to register them.',
        'none_prescribed' => '— None prescribed —',
        'not_entered_read_from_the_department_post_master' => 'Not entered — read from the Department Post master',
        'out_of' => 'out of',
        'post_not_live' => 'Not a live post of this department',
        'promotion_from_post' => 'Promotion From Post',
        'promotion_from_post_label' => '<label class="form-label" for="promotion_from_post_id">Promotion From Post <span class="text-danger">*</span></label>',
        'promotion_label' => '<label class="form-label" for="promotion_recruitment_pct">Promotion % <span class="text-danger">*</span></label>',
        'promotion_to_post_label' => '<label class="form-label" for="promotion_to_post_id">Promotion To Post <span class="text-danger">*</span></label>',
        'remove_source' => 'Remove this Promotion From Post',
        'save_draft' => 'Save draft',
        'select_a_post_first' => 'Select a post first',
        'share' => 'Share',
        'sources_lead' => 'The post or posts officers are promoted from. A promotion may draw on several.',
        'sources_share_note' => 'Each post shows the Pay Scale and Class recorded on its own Department Post Mapping — that is what promotion eligibility is judged on. The shares must total exactly 100% of the promotion quota between them.',
        'title_edit' => 'Edit Pre-DPC',
        'title_new' => 'New Pre-DPC',
        'to_post_attributes' => 'Pay Scale: :scale · Class: :class',
        'your_department_has_no_posts_registered_yet' => 'Your department has no posts registered yet.',
    ],
    'index' => [
        'exercise' => 'Exercise',
        'pre_dpc_no' => 'Pre-DPC No.',
        'promotion_from_post' => 'Promotion From Post',
        'promotion_to_post' => 'Promotion To Post',
        'pre_dpc_proposals' => 'Pre-DPC Proposals',
    ],
    'show' => [
        'active_for_this_exercise' => 'Active for this exercise',
        'amend_new_version' => 'Amend (new version)',
        'amends' => 'Amends',
        'approve_amp_sign' => 'Approve & Sign',
        'approve_and_digitally_sign_this_proposal' => 'Approve and digitally sign this proposal?',
        'approved_by' => 'on :at by :actor (signature #:id)',
        'class_of_promotion_post' => 'Class of the Promotion To Post',
        'pay_scale_of_promotion_post' => 'Pay Scale of the Promotion To Post',
        'class_of_feeder_posts' => 'Class of the Promotion From Post(s)',
        'delete_draft' => 'Delete Draft',
        'delete_draft_confirm' => 'Delete this draft Pre-DPC? It is removed from the list and kept on the record; only a draft can be deleted.',
        'draft_deleted' => 'Draft :number deleted.',
        'government_order_no' => 'Adyatan Vibhagiy Bharti Niyam',
        'publish_date' => 'Publish Date',
        'minimum_experience' => 'Minimum Experience',
        'no_actions_available_in_this_state' => 'No actions available in this state.',
        'no_sources_recorded' => 'No Promotion From Post has been recorded yet.',
        'pay_scale_of_feeder_posts' => 'Pay Scale of the Promotion From Post(s)',
        'promotion_from_posts' => 'Promotion From Posts',
        'promotion_from_posts_lead' => 'Each post below shows its own Pay Scale and Class, because promotion eligibility is judged on the officer’s current post.',
        'recruitment_split' => 'Recruitment Split',
        'sanctioned_terms' => 'Sanctioned Terms',
        'split_value' => 'Direct :direct% / Promotion :promotion%',
        'version_of_parent' => '(version :number)',
    ],
    /* The Pre-DPC form's cross-field rules, which need a sentence rather than a
       field-level message. */
    'validation' => [
        'only_a_draft_can_be_deleted' => 'Only a draft Pre-DPC can be deleted. Cancel it instead — a proposal that has been submitted stays on the record.',
        'qualification_required' => 'Choose at least one qualification this feeder accepts.',
        'year_before_earliest' => 'Reservation in promotion ended in 2019 (rule 1.4): a DPC for a year before :year is not taken in this software.',
        'feeder_not_below_post' => ':from cannot feed :to. A promotion goes from a lower grade to a higher one. :reason',
        'na_stands_alone' => '"NA" means no qualification is required, so it cannot be chosen with another qualification.',
        'pay_scale_not_mapped' => 'No pay scale is mapped to :post. Map it under Post Mapping first.',
        'pay_scale_no_entry_pay' => 'The pay scale :scale has no entry pay in the Pay Scale master.',
        'pay_scale_not_higher' => 'A promotion is only to a higher pay scale: :from (:from_scale) cannot be promoted to :to (:to_scale).',
    ],
];
