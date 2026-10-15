<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reports screen — हिन्दी
|--------------------------------------------------------------------------
|
| Change item 7: the application is bilingual, and every user-visible string is
| served from here when the active locale is Hindi.
|
| The key structure mirrors `lang/en/reportsui.php` exactly. A key absent from this
| file falls back to the English string, which is Laravel's own behaviour and is
| better than showing a raw key on a government screen.
|
*/

return [
    'index' => [
        'all_departments' => 'आयोग स्तर पर प्रत्येक विभाग।',
        'by_department' => 'विभाग-वार',
        'by_the_date_the_department_filed_the_proposal_fo' => 'उस तिथि के अनुसार जिसके लिए विभाग ने प्रस्ताव प्रस्तुत किया, प्रविष्टि के दिन के अनुसार नहीं।',
        'circulation_notes_signed' => 'हस्ताक्षरित परिभ्रमण टिप्पणियाँ',
        'circulations_raised' => '<span>प्रस्तुत परिभ्रमण टीप <span class="text-muted">S45–S48</span></span>',
        'corrections_and_circulations' => 'संशोधन एवं परिभ्रमण',
        'counted_from_records' => 'संग्रहीत अभिलेखों से गणना; यहाँ कुछ भी पुनः गणना नहीं किया जाता।',
        'departments_with_no_dpc_yet_are_omitted_rather_t' => 'जिन विभागों की अभी कोई डी.पी.सी. नहीं है, उन्हें शून्य के रूप में सूचीबद्ध करने के स्थान पर छोड़ दिया गया है।',
        'dpcs_by_year' => 'वर्ष-वार डी.पी.सी.',
        'export_pipeline_csv' => 'प्रक्रिया निर्यात (सी.एस.वी.)',
        'forwarded' => 'अग्रेषित',
        'no_dpc_has_been_raised_yet' => 'अभी कोई डी.पी.सी. प्रारंभ नहीं की गई है।',
        'results_approved' => 'अनुमोदित परिणाम',
        'reviews_raised' => '<span>प्रस्तुत पुनरीक्षण डी.पी.सी. <span class="text-muted">S35–S44</span></span>',
        's35_s44' => '· S35–S44',
        's45_s48' => '· S45–S48',
        'scoped_lead' => ':department — आपके विभाग की डी.पी.सी.।',
        'the_stages_of_the_process_in_the_order_the_proce' => 'प्रक्रिया के चरण, उसी क्रम में जिसमें प्रक्रिया उन्हें चलाती है। एक डी.पी.सी. उन सभी चरणों के सामने दिखती है जहाँ तक वह पहुँची है, अतः इनका योग कुल नहीं होता।',
        'the_two_flows_that_act_on_a_dpc_after_it_has_con' => 'वे दो प्रवाह जो किसी डी.पी.सी. के समाप्त होने के पश्चात उस पर कार्य करते हैं।',
        'where_the_dpcs_are' => 'डी.पी.सी. की वर्तमान स्थिति',
    ],
];
