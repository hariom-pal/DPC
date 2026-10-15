<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Promotion Candidate portal — हिन्दी
|--------------------------------------------------------------------------
|
| Change item 7: the application is bilingual, and every user-visible string is
| served from here when the active locale is Hindi.
|
| The key structure mirrors `lang/en/candidate.php` exactly. A key absent from this
| file falls back to the English string, which is Laravel's own behaviour and is
| better than showing a raw key on a government screen.
|
*/

return [
    'index' => [
        'my_dpc' => 'मेरी डी.पी.सी.',
        'the_departmental_promotion_committee_proceedings' => 'वे विभागीय पदोन्नति समिति कार्यवाहियाँ जिनमें आपका नाम सम्मिलित है।',
        'you_are_not_currently_named_in_any_dpc_that_has' => 'आयोग को प्रस्तुत की गई किसी भी डी.पी.सी. में वर्तमान में आपका नाम सम्मिलित नहीं है। आपके विभाग द्वारा तैयार किया जा रहा प्रस्ताव यहाँ नहीं दिखता।',
    ],
    'show' => [
        'back' => '← वापस',
        'declared_on' => ':date को घोषित।',
        'declared_promoted' => ':date को घोषित। पदोन्नति आदेश आपके विभाग द्वारा जारी किया जाता है।',
        'employee_number' => 'कर्मचारी क्रमांक',
        'final_result' => 'अंतिम परिणाम',
        'if_any_of_this_is_wrong_it_is_your_department_s' => 'यदि इसमें कुछ त्रुटिपूर्ण है, तो सुधार आपके विभाग के अभिलेख में आवश्यक है — आयोग विभाग द्वारा प्रस्तुत सामग्री पर ही कार्रवाई करता है।',
        'my_details_as_my_department_filed_them' => 'मेरा विवरण, जैसा मेरे विभाग ने प्रस्तुत किया',
        'not_recommended_for_promotion_in_this_dpc' => 'इस डी.पी.सी. में पदोन्नति हेतु अनुशंसित नहीं',
        'nothing_recorded_yet' => 'अभी कुछ अभिलिखित नहीं है।',
        'progress' => 'प्रगति',
        'recommended_for_promotion' => 'पदोन्नति हेतु अनुशंसित',
        'seniority_number' => 'वरिष्ठता क्रमांक',
        'the_result_of_this_dpc_has_not_been_declared_yet' => 'इस डी.पी.सी. का परिणाम अभी घोषित नहीं हुआ है। आयोग द्वारा कार्यवृत्त प्रकाशित करने पर यह यहाँ प्रदर्शित होगा।',
        'your_data_heading' => 'आपके विभाग द्वारा आपके संबंध में अभिलिखित जानकारी',
        'your_data_lead' => 'यह स्क्रीन प्रक्रिया तथा उसकी वर्तमान स्थिति दिखाती है। आपके विभाग द्वारा अभिलिखित विवरण — आपकी तिथियाँ, वर्ग, अर्हता एवं वर्षवार प्रविष्टियाँ — देखने हेतु :link का उपयोग करें। इनमें कुछ भी त्रुटिपूर्ण हो तो स्वयं उपस्थित होकर अपने विभाग के समक्ष उठाएँ।',
        'your_data_link' => 'स्व-निरीक्षण',
        'your_entry_is_not_available_on_this_proposal' => 'इस प्रस्ताव में आपकी प्रविष्टि उपलब्ध नहीं है।',
    ],
    'timeline' => [
        'dpc_cgpsc_accept' => 'सदस्य ने डी.पी.सी. स्वीकार की',
        'dpc_cgpsc_allot' => 'आयोग ने डी.पी.सी. एक सदस्य को सौंपी',
        'dpc_cgpsc_schedule' => 'डी.पी.सी. की तिथि निर्धारित की गई',
        'dpc_proceeding_approve_result' => 'आयोग ने परिणाम अनुमोदित किया',
        'dpc_proceeding_open' => 'डी.पी.सी. की बैठक आयोजित हुई',
        'dpc_proposal_approve' => 'आपके विभाग ने प्रस्ताव अनुमोदित कर आयोग को अग्रेषित किया',
        'dpc_proposal_return' => 'प्रस्ताव सुधार हेतु प्राधिकृत अधिकारी को वापस भेजा गया',
        'dpc_proposal_submit' => 'आपके विभाग ने प्रस्ताव अनुमोदन हेतु प्रस्तुत किया',
    ],
];
