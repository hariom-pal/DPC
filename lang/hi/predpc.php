<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pre-DPC Proposal screens — हिन्दी
|--------------------------------------------------------------------------
|
| Change item 7: the application is bilingual, and every user-visible string is
| served from here when the active locale is Hindi.
|
| The key structure mirrors `lang/en/predpc.php` exactly. A key absent from this
| file falls back to the English string, which is Laravel's own behaviour and is
| better than showing a raw key on a government screen.
|
*/

return [
    'declaration_required' => 'आगे बढ़ने हेतु घोषणा पर टिक करें।',
    'summary' => [
        'title_edit' => 'प्री-डीपीसी प्रस्ताव संपादित करें',
        'title_new' => 'नया प्री-डीपीसी प्रस्ताव',
        'number' => 'प्री-डीपीसी क्रमांक',
    ],
    'form' => [
        '1_the_promotion_exercise' => '1. पदोन्नति प्रक्रिया',
        '2_taken_from_the_posts' => '2. पदों से प्राप्त',
        '2_terms_this_exercise_sanctions' => '2. इस प्रक्रिया द्वारा स्वीकृत शर्तें',
        '3_promotion_from_posts' => '3. पदोन्नति-पूर्व पद',
        '4_government_order' => '4. अद्यतन विभागीय भर्ती नियम',
        'publish_date' => 'प्रकाशन तिथि',
        'rules_document' => 'अद्यतन विभागीय भर्ती नियम (PDF)',
        'rules_document_hint' => 'PDF, अधिकतम 10 MB।',
        'rules_document_current' => 'अपलोड किया गया:',
        'all_fields_required' => '* चिह्नित प्रत्येक फ़ील्ड आवश्यक है।',
        'share_total_ok' => 'फीडर पदों का अंश पदोन्नति कोटे का पूर्ण 100% है।',
        'share_total_short' => 'फीडर पदों का अंश :total% है। यह ठीक 100% होना चाहिए — :short% शेष है, अतः प्रस्ताव सहेजा नहीं जा सकता।',
        'share_total_over' => 'फीडर पदों का अंश :total% है, जो पदोन्नति कोटे से :over% अधिक है। यह ठीक 100% होना चाहिए।',
        'add_source' => 'पदोन्नति-पूर्व पद जोड़ें',
        'attributes_unavailable' => 'लोड नहीं हो सका — आदेश सहेजते समय पद से ही लिया जाएगा',
        'back_to_list' => 'सूची पर वापस',
        'both_are_required_before_the_proposal_can_be_sub' => 'प्रस्ताव को अनुमोदन हेतु प्रस्तुत करने से पूर्व दोनों आवश्यक हैं।',
        'derived_hint' => 'ऊपर दोनों पद चुनें और ये स्वयं भर जाएँगे। वेतनमान एवं वर्ग <strong>पदोन्नति-पश्चात</strong> पद से आते हैं — पदोन्नति किसी पद <em>में</em> होती है, उसी पद के वेतनमान पर — तथा पदोन्नति अवधि <strong>पदोन्नति-पूर्व</strong> पद से आती है, जो वह अर्हकारी सेवा है जो अधिकारी के पास उस पद पर पहले से होनी चाहिए।',
        'direct_recruitment_label' => '<label class="form-label" for="direct_recruitment_pct">सीधी भर्ती % <span class="text-danger">*</span></label>',
        'dpc_year_label' => '<label class="form-label" for="dpc_year">डी.पी.सी. वर्ष <span class="text-danger">*</span></label>',
        'experience_unit' => 'अनुभव इकाई',
        'experience_unit_label' => '<label class="form-label" for="experience_unit">इकाई <span class="text-danger">*</span></label>',
        'government_order_number' => 'अद्यतन विभागीय भर्ती नियम',
        'hint_from_post' => 'वह पद जिससे अधिकारियों की पदोन्नति <strong>होती है</strong>।',
        'hint_shares_total' => 'दोनों का योग ठीक 100% होना चाहिए।',
        'hint_to_post' => 'वह पद जिसमें अधिकारियों की पदोन्नति <strong>की जाती है</strong>।',
        'its_pay_scale_and_class' => 'उसका वेतनमान एवं वर्ग',
        'min_experience' => 'न्यूनतम अनुभव',
        'min_experience_label' => '<label class="form-label" for="min_experience_years">न्यूनतम अनुभव <span class="text-danger">*</span></label>',
        'min_qualification' => 'न्यूनतम अर्हता',
        'qualifications_hint' => 'इस फीडर के लिए स्वीकार्य सभी अर्हताएँ चुनें। यदि कोई निर्धारित नहीं है तो रिक्त छोड़ें।',
        'no_posts_explained' => 'प्री-डी.पी.सी. अधिकारियों की पदोन्नति एक विभागीय पद से दूसरे में करती है, अतः प्रस्ताव तैयार करने से पूर्व दोनों पद विभागीय पद मास्टर में होने चाहिए। इन्हें पंजीकृत करने हेतु सी.जी.पी.एस.सी. प्रशासक से अनुरोध करें।',
        'none_prescribed' => '— कोई निर्धारित नहीं —',
        'not_entered_read_from_the_department_post_master' => 'प्रविष्ट नहीं — विभागीय पद मास्टर से प्राप्त',
        'out_of' => 'से',
        'post_not_live' => 'इस विभाग का प्रचलित पद नहीं है',
        'promotion_from_post' => 'पदोन्नति-पूर्व पद',
        'promotion_from_post_label' => '<label class="form-label" for="promotion_from_post_id">पदोन्नति-पूर्व पद <span class="text-danger">*</span></label>',
        'promotion_label' => '<label class="form-label" for="promotion_recruitment_pct">पदोन्नति % <span class="text-danger">*</span></label>',
        'promotion_to_post_label' => '<label class="form-label" for="promotion_to_post_id">पदोन्नति-पश्चात पद <span class="text-danger">*</span></label>',
        'remove_source' => 'यह पदोन्नति-पूर्व पद हटाएँ',
        'save_draft' => 'प्रारूप सहेजें',
        'select_a_post_first' => 'पहले एक पद चुनें',
        'share' => 'अंश',
        'sources_lead' => 'वे पद जिनसे अधिकारियों की पदोन्नति होती है। एक पदोन्नति कई पदों से हो सकती है।',
        'sources_share_note' => 'प्रत्येक पद के सामने उसकी अपनी विभागीय पद मैपिंग में अभिलिखित वेतनमान एवं वर्ग दिखाया जाता है — पदोन्नति पात्रता उसी के आधार पर निर्धारित होती है। सभी अंशों का योग पदोन्नति कोटे के ठीक 100% होना चाहिए।',
        'title_edit' => 'प्री-डी.पी.सी. संपादन',
        'title_new' => 'नवीन प्री-डी.पी.सी.',
        'to_post_attributes' => 'वेतनमान: :scale · वर्ग: :class',
        'your_department_has_no_posts_registered_yet' => 'आपके विभाग का कोई पद अभी पंजीकृत नहीं है।',
    ],
    'index' => [
        'exercise' => 'प्रक्रिया',
        'pre_dpc_no' => 'पूर्व-डी.पी.सी. क्र.',
        'promotion_from_post' => 'पदोन्नति-पूर्व पद',
        'promotion_to_post' => 'पदोन्नति-पश्चात पद',
        'pre_dpc_proposals' => 'पूर्व-डी.पी.सी. प्रस्ताव',
    ],
    'show' => [
        'active_for_this_exercise' => 'इस प्रक्रिया हेतु प्रभावशील',
        'amend_new_version' => 'संशोधन (नया संस्करण)',
        'amends' => 'संशोधित करता है',
        'approve_amp_sign' => 'अनुमोदित करें एवं हस्ताक्षर करें',
        'approve_and_digitally_sign_this_proposal' => 'इस प्रस्ताव को अनुमोदित कर डिजिटल हस्ताक्षर करें?',
        'approved_by' => ':at को :actor द्वारा (हस्ताक्षर #:id)',
        'class_of_promotion_post' => 'पदोन्नति वाले पद का वर्ग',
        'pay_scale_of_promotion_post' => 'पदोन्नति वाले पद का वेतनमान',
        'class_of_feeder_posts' => 'पदोन्नति स्रोत पद(पदों) का वर्ग',
        'delete_draft' => 'प्रारूप हटाएँ',
        'delete_draft_confirm' => 'क्या यह प्रारूप प्री-डीपीसी हटाना है? यह सूची से हट जाएगा किंतु अभिलेख में सुरक्षित रहेगा; केवल प्रारूप ही हटाया जा सकता है।',
        'draft_deleted' => 'प्रारूप :number हटा दिया गया।',
        'government_order_no' => 'अद्यतन विभागीय भर्ती नियम',
        'publish_date' => 'प्रकाशन तिथि',
        'minimum_experience' => 'न्यूनतम अनुभव',
        'no_actions_available_in_this_state' => 'इस स्थिति में कोई कार्रवाई उपलब्ध नहीं है।',
        'no_sources_recorded' => 'अभी तक कोई पदोन्नति-पूर्व पद अभिलिखित नहीं किया गया है।',
        'pay_scale_of_feeder_posts' => 'पदोन्नति स्रोत पद(पदों) का वेतनमान',
        'promotion_from_posts' => 'पदोन्नति-पूर्व पद',
        'promotion_from_posts_lead' => 'नीचे प्रत्येक पद का अपना वेतनमान एवं वर्ग दर्शाया गया है, क्योंकि पदोन्नति पात्रता अधिकारी के वर्तमान पद के आधार पर निर्धारित होती है।',
        'recruitment_split' => 'भर्ती विभाजन',
        'sanctioned_terms' => 'स्वीकृत शर्तें',
        'split_value' => 'सीधी भर्ती :direct% / पदोन्नति :promotion%',
        'version_of_parent' => '(संस्करण :number)',
    ],
    'validation' => [
        'only_a_draft_can_be_deleted' => 'केवल प्रारूप प्री-डीपीसी ही हटाया जा सकता है। इसके स्थान पर निरस्त करें — प्रस्तुत प्रस्ताव अभिलेख में बना रहता है।',
        'qualification_required' => 'इस फीडर हेतु स्वीकार्य कम से कम एक अर्हता चुनें।',
        'year_before_earliest' => 'पदोन्नति में आरक्षण 2019 से समाप्त (नियम 1.4): :year से पूर्व के वर्ष की DPC इस सॉफ़्टवेयर में नहीं ली जाती।',
        'feeder_not_below_post' => ':from से :to पर पदोन्नति संभव नहीं है। पदोन्नति निम्न श्रेणी से उच्च श्रेणी की ओर ही होती है। :reason',
        'na_stands_alone' => '"NA" का अर्थ है कोई योग्यता आवश्यक नहीं, इसलिए इसे किसी अन्य योग्यता के साथ नहीं चुना जा सकता।',
        'pay_scale_not_mapped' => ':post पर कोई वेतनमान मैप नहीं है। पहले पद मैपिंग में वेतनमान मैप करें।',
        'pay_scale_no_entry_pay' => 'वेतनमान मास्टर में :scale का प्रारंभिक वेतन दर्ज नहीं है।',
        'pay_scale_not_higher' => 'पदोन्नति केवल उच्च वेतनमान में ही होती है: :from (:from_scale) से :to (:to_scale) पर पदोन्नति संभव नहीं है।',
    ],
];
