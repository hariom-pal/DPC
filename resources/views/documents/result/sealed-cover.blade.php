{{-- गोपनीय बंद लिफाफे की अनुशंसा (2026-11-25): one page for one officer kept in a sealed
     cover, the Commission's own format. Expects: $d, $signatures, $final, $digest. --}}
<html><head><meta charset="utf-8">@include('documents.result._styles')</head><body>
<h1>(गोपनीय बंद लिफाफे की अनुशंसा)</h1>
<div class="center">विभागीय पदोन्नति समिति की बैठक दिनांक {{ $d['date'] ?? '—' }}</div>
<div class="para"><span class="head">विभाग:-</span> {{ $d['department'] }}</div>
<div class="para"><span class="head">विषय:-</span> {{ $d['from_post'] }} से {{ $d['to_post'] }} के पद पर पदोन्नति।</div>
<div class="center">---0---</div>

<div class="para">विभागीय पदोन्नति समिति ने श्री/श्रीमती {{ $d['name'] }} (वरिष्ठता क्रमांक {{ $d['sn'] }}), {{ $d['from_post'] }} के नाम पर {{ $d['to_post'] }} के पद पर पदोन्नति के लिये विचार किया। श्री/श्रीमती {{ $d['name'] }} के विरूद्ध {{ $d['reason'] }} होने के कारण प्रकरण बंद लिफाफा में रखा गया है@if ($d['circulation'] !== []) एवं {{ implode('; ', $d['circulation']) }} होने से प्रकरण परिभ्रमण में रखा गया है@endif।
श्री/श्रीमती {{ $d['name'] }} के विभागीय जांच प्रकरण में पूर्णतः दोषमुक्त पाये जाने पर बंद लिफाफा खोला जाकर@if ($d['circulation'] !== []) परिभ्रमण के माध्यम से@endif प्रकरण का निराकरण किया जाएगा।</div>

@include('documents.result._signatures', ['members' => $d['members']])

<div class="footer">{{ $d['dpc_code'] }} · वरिष्ठता क्रमांक {{ $d['sn'] }}@if ($final) · पोर्टल पर समिति सदस्यों के हस्ताक्षर-अभिलेख सहित प्रति (प्रमाणपत्र-आधारित डिजिटल हस्ताक्षर नहीं) · मूल दस्तावेज़ SHA-256: {{ $digest }}@else · समिति सदस्यों के पोर्टल हस्ताक्षर हेतु@endif</div>
</body></html>
