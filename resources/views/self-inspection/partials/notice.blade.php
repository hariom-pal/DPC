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
        {{ __('selfinspection.partials.notice.if_you_have_an_objection_to_this_data') }}
    </strong>

    <p class="mb-2" lang="hi">
        यदि उक्त डाटा के सम्बन्ध में आपको किसी प्रकार की आपत्ति हो तो
        @if ($deadlineText !== null)
            <strong>दिनांक {{ $deadlineText }} या उसके पूर्व</strong>
        @endif
        <strong>{{ __('selfinspection.partials.notice.text') }}</strong> समाधान करवा लेवें.
        छत्तीसगढ़ लोक सेवा आयोग द्वारा उक्त सम्बन्ध में किसी भी प्रकार के अभ्यावेदन पर
        विचार नहीं किया जाएगा और ऐसे अभ्यावेदन सीधे नस्तीबद्ध कर दिए जाएँगे.
    </p>

    <p class="small mb-0">
{{ __('selfinspection.partials.notice.raise_objection') }}
        @if ($deadlineText !== null)
{!! __('selfinspection.partials.notice.on_or_before', ['date' => e($deadlineText)]) !!}
        @endif
        {!! __('selfinspection.partials.notice.in_person_note') !!}
    </p>

    @if ($deadlineText === null)
        <p class="small text-body-secondary mt-2 mb-0">
            {{ __('selfinspection.partials.notice.no_date_is_shown_because_the_sitting_for_this_co') }}
        </p>
    @elseif ($deadlinePassed)
        <p class="small fw-semibold mt-2 mb-0">
            {{ __('selfinspection.partials.notice.this_date_has_passed_raise_the_matter_with_your') }}
        </p>
    @endif
</div>
