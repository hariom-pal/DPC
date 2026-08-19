{{-- S51's notice, verbatim from the deck.

     "यदि उक्त डाटा के सम्बन्ध में आपको किसी प्रकार की आपत्ति हो तो दिनांक 25 जून 2026
      या उसके पूर्व विभाग में स्वयं उपस्थित होकर समाधान करवा लेवें. छत्तीसगढ़ लोक सेवा
      आयोग द्वारा उक्त सम्बन्ध में किसी भी प्रकार के अभ्यावेदन पर विचार नहीं किया जाएगा
      और ऐसे अभ्यावेदन सीधे नस्तीबद्ध कर दिए जाएँगे."

     The date is the only part derived — see ObjectionDeadlineResolver. **When
     none can be derived the sentence is rendered without it**, because the rest
     of the notice is true regardless and a screen whose purpose is to show an
     officer the record must not invent a deadline for them.

     This is also the reason there is no RAISE OBJECTION button anywhere: the
     notice *is* the objection process, and it is conducted in person at the
     department (BD-20).

     Expects: $deadlineText (string|null), $deadlinePassed (bool) --}}

<div class="alert {{ $deadlinePassed ? 'alert-secondary' : 'alert-warning' }} mb-0" role="note">
    <strong class="d-block mb-2">
        यदि डाटा में आपत्ति हो / If you have an objection to this data
    </strong>

    <p class="mb-2" lang="hi">
        यदि उक्त डाटा के सम्बन्ध में आपको किसी प्रकार की आपत्ति हो तो
        @if ($deadlineText !== null)
            <strong>दिनांक {{ $deadlineText }} या उसके पूर्व</strong>
        @endif
        <strong>विभाग में स्वयं उपस्थित होकर</strong> समाधान करवा लेवें.
        छत्तीसगढ़ लोक सेवा आयोग द्वारा उक्त सम्बन्ध में किसी भी प्रकार के अभ्यावेदन पर
        विचार नहीं किया जाएगा और ऐसे अभ्यावेदन सीधे नस्तीबद्ध कर दिए जाएँगे.
    </p>

    <p class="small mb-0">
        Raise any objection to this data
        @if ($deadlineText !== null)
            <strong>on or before {{ $deadlineText }}</strong>,
        @endif
        <strong>in person at your department</strong>. The Chhattisgarh Public Service Commission
        will not consider any representation in this matter; such representations are consigned
        to file.
    </p>

    @if ($deadlineText === null)
        <p class="small text-body-secondary mt-2 mb-0">
            No date is shown because the sitting for this Committee has not been scheduled yet.
            Raise any correction with your department as soon as you can — once the Committee has
            sat, the data it considered cannot be changed.
        </p>
    @elseif ($deadlinePassed)
        <p class="small fw-semibold mt-2 mb-0">
            This date has passed. Raise the matter with your department immediately; a correction
            may no longer be possible.
        </p>
    @endif
</div>
