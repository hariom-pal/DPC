{{-- विभागीय पदोन्नति समिति की बैठक का कार्यवाही विवरण (2026-11-25), the Commission's own
     format, Class I to I and other than Class I to I. Every figure is from the database
     snapshot $d; a figure not on record prints as a dash. Expects: $d, $signatures,
     $final, $digest. --}}
@php
    $dash = '—';
    $v = $d['vacancies'];
    $c = $d['criteria'];
@endphp
<html><head><meta charset="utf-8">@include('documents.result._styles')</head><body>
<h1>विभागीय पदोन्नति समिति की बैठक का कार्यवाही विवरण</h1>
<div class="center">दिनांक {{ $d['date'] ?? $dash }}</div>
<div class="center">***********</div>

<div class="para"><span class="head">विभाग:-</span> {{ $d['department'] }}</div>
<div class="para"><span class="head">विषय -</span> {{ $d['from_post'] }} से {{ $d['to_post'] }} के पद पर पदोन्नति।</div>

<div class="para head">उपस्थिति:-</div>
@foreach ($d['members'] as $i => $member)
    <div class="para">({{ $i + 1 }}) {{ $member['name'] }} — {{ $member['chairman'] ? 'अध्यक्ष' : 'सदस्य' }}@if ($member['designation'] !== ''), {{ $member['designation'] }}@endif @if ($member['represents'] !== [])({{ implode(', ', $member['represents']) }})@endif</div>
@endforeach

<div class="para"><span class="head">01 प्राधिकार एवं सिद्धांत :-</span> यह पदोन्नति विभाग के सेवा भर्ती नियम@if ($d['recruitment_order']) (आदेश क्रमांक {{ $d['recruitment_order'] }})@endif के प्रावधानों के साथ पठित सामान्य प्रशासन विभाग के नियम ‘‘छत्तीसगढ़ लोक सेवा पदोन्नति नियम 2003’’ के अनुसार है तथा यह पदोन्नतियां योग्यता-सह-वरिष्ठता के सिद्धांत पर आधारित है।</div>

<div class="para"><span class="head">02 न्यायालयीन स्थगन:-</span>
    @if ($d['legal_stay']) विभाग ने सूचित किया है कि इस पदोन्नति के संबंध में न्यायालयीन स्थगन है।
    @else विभाग ने सत्यापित किया है कि इस पदोन्नति के संबंध में कोई न्यायालयीन स्थगन नहीं है। @endif
</div>

<div class="para"><span class="head">03 वरिष्ठता सूची:-</span> विभाग द्वारा {{ $d['from_post'] }} पद की दिनांक {{ $d['seniority_as_on'] ?? $dash }} की स्थिति दर्शाने वाली अंतिम वरिष्ठता सूची को पदोन्नति का आधार बनाया गया है। विभाग ने सत्यापित किया है कि इस वरिष्ठता सूची के आधार पर चयन के संबंध में कोई न्यायालयीन स्थगन अथवा ऐसा कोई विशिष्ट निर्देश नहीं हैं, जिसके पालन के अभाव में बैठक किया जाना युक्तियुक्त न हो।</div>

<div class="para"><span class="head">04 विचार क्षेत्र:-</span> योग्यता-सह-वरिष्ठता (मेरिट-कम-सिनीयारिटी) पर आधारित पदोन्नतियों के संबंध में सामान्य प्रशासन विभाग के नियम ‘‘छत्तीसगढ़ लोक सेवा (पदोन्नति) नियम-2003’’ के अनुसार विचार क्षेत्र में कुल रिक्तियों को दुगुना कर और उसमें चार जोड़ने का प्रावधान है, तदनुसार अधिकारियों के नामों पर विचार किया गया।@if ($d['zone']['size'] !== null) (रिक्तियां: {{ $d['zone']['vacancies'] ?? $dash }}, विचार क्षेत्र: {{ $d['zone']['size'] }} अधिकारी)@endif</div>
{{-- Class I to I and other than Class I to I: the Commission's two formats carry the same
     text, so both print it. What, if anything, differs between them is pending
     clarification (2026-11-26); `format` is recorded on the document meanwhile. --}}

<div class="para"><span class="head">05 संनिष्ठा प्रमाण पत्र:-</span> विचारक्षेत्र में आने वाले अधिकारियों के संबंध में विभाग ने संनिष्ठा प्रमाण-पत्र जारी किया है।</div>

<div class="para"><span class="head">06 अर्हकारी सेवा:-</span> पदोन्नति के लिये उन्हीं अधिकारियों के नामों पर विचार किया गया, जिन्होंने दिनांक {{ $d['qualifying_as_on'] }} की स्थिति में विभाग के भर्ती नियम के अनुसार {{ $d['from_post'] }} के पद पर {{ $d['qualifying_years'] ?? $dash }} वर्षों की सेवा पूर्ण कर ली हो।</div>

<div class="para"><span class="head">07</span> पदोन्नति की पात्रता अर्जित करने के वर्ष की 01 जनवरी को विचारक्षेत्र में आने वाले अधिकारियों के विरूद्ध अनुशासनात्मक कार्यवाही, आपराधिक प्रकरण पंजीबद्ध होने या नहीं होने के संबंध में प्रमाण पत्र प्रेषित किया गया है।</div>

<div class="para head">08 रिक्तियां:-</div>
<div class="para">1. वर्तमान स्पष्ट रिक्तियां : {{ $v['clear'] ?? $dash }}</div>
<div class="para">2. पदोन्नति/सेवानिवृत्ति/प्रतिनियुक्ति से दिनांक {{ $v['period_to'] ?? '31 दिसम्बर' }} तक होने वाली रिक्तियां : {{ $v['arising'] ?? $dash }}</div>
<div class="para">योग : {{ $v['total'] ?? $dash }}</div>

<div class="para head">09 रिक्त पदों का विवरण:-</div>
<table class="grid">
    <tr><th>सरल क्र.<br>(1)</th><th>पद कब से रिक्त है/होगा<br>(2)</th><th>रिक्त होने का कारण सेवानिवृत्त आदि<br>(3)</th><th>सेवानिवृत्त/पदोन्नत अधिकारी का नाम<br>(4)</th><th>अधिकारी का कोटा (पदोन्नति, सीधी भरती)<br>(5)</th><th>पदोन्नति हेतु कुल रिक्त पद संख्या<br>(6)</th></tr>
    @forelse ($v['entries'] as $i => $entry)
        <tr><td>{{ sprintf('%02d', $i + 1) }}</td><td>{{ $entry['since'] ?? $dash }}</td><td>{{ $entry['reason'] }}</td><td>{{ $entry['officer'] ?? $dash }}</td><td>{{ $entry['quota'] ?? $dash }}</td><td>{{ $entry['count'] }}</td></tr>
    @empty
        <tr><td colspan="6" class="center">{{ $dash }}</td></tr>
    @endforelse
    <tr><td colspan="5"><strong>योग</strong></td><td><strong>{{ $v['promotion_total'] ?? $dash }}</strong></td></tr>
</table>

<div class="para"><span class="head">10.</span> छत्तीसगढ़ लोक सेवा पदोन्नति नियम-2003 लागू होने के पश्चात् पूर्व में बैठक वर्ष {{ $d['previous_dpc_year'] ?? $dash }} को सम्पन्न हुई थी।</div>

<div class="para head">11. संवर्ग में वर्तमान स्थिति:-</div>
<div class="para">(अ) संवर्ग में कुल पद : {{ $v['sanctioned'] ?? $dash }}</div>
<div class="para">(ब) पदोन्नति कोटे का पद : {{ $v['promotion_posts'] ?? $dash }} @if ($v['percentage'] !== null)({{ rtrim(rtrim($v['percentage'], '0'), '.') }} प्रतिशत)@endif</div>
<div class="para">(स) भरे गये पद : {{ $v['filled'] ?? $dash }}</div>

<div class="para head">12. पदोन्नति हेतु भर्ती नियम एवं छ.ग. लोक सेवा पदोन्नति नियम 2003 के संदर्भ में वर्गीकरण-</div>
<div class="para head">(अ) न्यूनतम कसौटी:-</div>
<div class="para">समिति ने विचाराधीन पदोन्नति के लिये निम्नलिखित कसौटियां तय की —</div>
<div class="para">(1) संनिष्ठा संदेह से परे हो एवं संनिष्ठा प्रमाणित हो।</div>
<div class="para">(2) समिति ने तय किया कि {{ sprintf('%02d', $c['years']) }} वर्षों के गोपनीय रिकार्ड का समग्र मूल्यांकन @if ($c['qualifying_total'] !== null)न्यूनतम {{ $c['qualifying_total'] }} अंक@else निर्धारित न्यूनतम अंक@endif का हो तथा किसी भी वर्ष ‘‘{{ $c['disqualifying_grade'] }}’’ श्रेणी एवं प्रतिकूल टिप्पणी न हो।</div>
@if ($c['last_year_mandatory'] || $c['last_year_min_grade'] !== null)
    <div class="para">(3) उक्त {{ sprintf('%02d', $c['years']) }} वर्षों में से अंतिम वर्ष का गोपनीय प्रतिवेदन अनिवार्य रूप से उपलब्ध हो@if ($c['last_year_min_grade'] !== null) तथा वह कम से कम ‘‘{{ $c['last_year_min_grade'] }}’’ श्रेणी का होना चाहिये@endif।</div>
@endif
<div class="para">({{ $c['last_year_mandatory'] || $c['last_year_min_grade'] !== null ? 4 : 3 }}) उक्त {{ sprintf('%02d', $c['years']) }} वर्षों में से किसी भी वर्ष का अचल सम्पत्ति विवरण अप्राप्त होने से प्रकरण परिभ्रमण में रखा जायेगा।</div>

<div class="para head">(ब) {{ sprintf('%02d', $c['years']) }} वर्षों के गोपनीय प्रतिवेदनों के आधार पर समिति द्वारा की गई अनुशंसा निम्नानुसार है:-</div>
<table class="grid">
    <tr><th>स.क्र.</th><th>अधिकारी का नाम</th><th>समिति की अनुशंसा</th></tr>
    @forelse ($d['recommendations'] as $i => $row)
        <tr><td>{{ sprintf('%02d', $i + 1) }}</td><td>{{ $row['name'] }}</td><td>{{ $row['recommendation'] }}</td></tr>
    @empty
        <tr><td colspan="3" class="center">निरंक</td></tr>
    @endforelse
</table>
<div class="para">टीप - माननीय उच्च न्यायालय में याचिका क्रमांक WP(PIL) No. 91/2019, WPS No. 9778/2019 एवं अन्य याचिकाओं में पारित आदेश दिनांक 16.04.2024 के परिपालन में डीपीसी की कार्यवाही की गई।</div>

<div class="para"><span class="head">13. चयन सूचियां:-</span> कंडिका-12 (अ) अनुसार निर्धारित किये गये न्यूनतम मापदण्डों के आधार पर समिति ने चयन सूची निम्नानुसार प्रस्तावित की:-</div>
<table class="grid">
    <tr><th>वर्ष</th><th>स.क्र.</th><th>वरिष्ठता क्र.</th><th>अधिकारी का नाम</th></tr>
    @forelse ($d['selection'] as $i => $row)
        <tr><td>{{ $i === 0 ? $d['selection_year'] : '' }}</td><td>{{ $i + 1 }}</td><td>{{ $row['sn'] }}</td><td>{{ $row['name'] }}</td></tr>
    @empty
        <tr><td>{{ $d['selection_year'] }}</td><td colspan="3" class="center">निरंक</td></tr>
    @endforelse
</table>

<div class="para"><span class="head">14. रिक्तियों को अग्रणित किया जाना:-</span> {{ $d['carried_forward'] ?? $dash }}</div>

<div class="para"><span class="head">15. अनुपयुक्तता सूचियां:-</span> समिति के द्वारा कंडिका 12 (अ) में निर्धारित किये गये मापदण्डों को पूर्ण नहीं करने के कारण निम्नलिखित अधिकारियों को पदोन्नति हेतु अनुपयुक्त पाया गया:-</div>
<table class="grid">
    <tr><th>सरल क्र.</th><th>वरिष्ठता क्रमांक</th><th>अधिकारी का नाम</th></tr>
    @forelse ($d['unfit'] as $i => $row)
        <tr><td>{{ $i + 1 }}</td><td>{{ $row['sn'] }}</td><td>{{ $row['name'] }}</td></tr>
    @empty
        <tr><td colspan="3" class="center">निरंक</td></tr>
    @endforelse
</table>

<div class="para"><span class="head">16. बंद लिफाफा में रखी गयी अनुशंसाएं:-</span> निम्नलिखित अधिकारियों के विरूद्ध विभागीय जांच प्रकरण लंबित होने के कारण विभागीय पदोन्नति समिति की बैठक की अनुशंसाएं बंद लिफाफे में रखी गई है जो कि उनके विभागीय जांच प्रकरण समाप्त होने अथवा निर्णित होने के पश्चात् पूर्णतः निर्दोष पाये जाने पर ही विभाग द्वारा खोले जाकर अनुशंसा के अनुसार कार्यवाही की जावेगी:-</div>
<table class="grid">
    <tr><th>क्रं.</th><th>अधिकारी का नाम</th></tr>
    @forelse ($d['sealed'] as $i => $row)
        <tr><td>{{ sprintf('%02d', $i + 1) }}</td><td>{{ $row['name'] }}</td></tr>
    @empty
        <tr><td colspan="2" class="center">निरंक</td></tr>
    @endforelse
</table>

<div class="para"><span class="head">17. परिभ्रमण के प्रकरण:-</span> समिति ने निम्नलिखित अधिकारियों की पदोन्नति पर उनके नामों के समक्ष अंकित किए गए कारणों से परिभ्रमण में विचार करने का निर्णय लिया:-</div>
<table class="grid">
    <tr><th>स.क्र.</th><th>वरिष्ठता क्रमांक</th><th>अधिकारी का नाम</th><th>वे आधार जिनके कारण प्रकरण परिभ्रमण में रखे जाने का निर्णय लिया गया</th></tr>
    @forelse ($d['circulation'] as $i => $row)
        <tr><td>{{ sprintf('%02d', $i + 1) }}</td><td>{{ $row['sn'] }}</td><td>{{ $row['name'] }}</td><td>{{ implode('; ', $row['reasons']) }}</td></tr>
    @empty
        <tr><td colspan="4" class="center">निरंक</td></tr>
    @endforelse
</table>

<div class="para"><span class="head">18. ऐसे अधिकारियों के नामों पर विचार/अनुशंसा नहीं किया गया:-</span> समिति ने निम्नलिखित अधिकारियों के प्रकरणों पर उनके नाम के समक्ष बताए गये कारणों से चयन सूची में नाम रखे जाने के संबंध में विचार/अनुशंसा नहीं किया:-</div>
<table class="grid">
    <tr><th>स.क्र.</th><th>अधिकारी का नाम</th><th>समिति की अनुशंसा</th></tr>
    @forelse ($d['not_considered'] as $i => $row)
        <tr><td>{{ sprintf('%02d', $i + 1) }}</td><td>{{ $row['name'] }}</td><td>{{ $row['reason'] }}</td></tr>
    @empty
        <tr><td colspan="3" class="center">निरंक</td></tr>
    @endforelse
</table>

@include('documents.result._signatures', ['members' => $d['members']])

<div class="footer">{{ $d['dpc_code'] }}@if ($final) · पोर्टल पर समिति सदस्यों के हस्ताक्षर-अभिलेख सहित प्रति (प्रमाणपत्र-आधारित डिजिटल हस्ताक्षर नहीं) · मूल दस्तावेज़ SHA-256: {{ $digest }}@else · समिति सदस्यों के पोर्टल हस्ताक्षर हेतु@endif</div>
</body></html>
