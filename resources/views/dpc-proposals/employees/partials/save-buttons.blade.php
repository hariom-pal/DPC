{{-- Save, and "Save & Next" (2026-11-14): the second saves and moves on to the next
     section. `$only = 'next'` gives just a "Next" link for a section with no form of
     its own to save. --}}
@php
    $only = $only ?? null;
@endphp
<div class="d-flex flex-wrap gap-2" data-save-buttons>
    @if ($only !== 'next')
        <button class="btn btn-success btn-sm">{{ __('dpc.employee_details.save_section') }}</button>
        @if ($next)
            <button class="btn btn-primary btn-sm" name="_next" value="{{ $next }}">{{ __('dpc.employee_details.save_and_next') }}</button>
        @endif
    @elseif ($next)
        <a class="btn btn-outline-primary btn-sm" href="#section-{{ $next }}" data-next-section>{{ __('dpc.employee_details.next_section') }} →</a>
    @endif
</div>
