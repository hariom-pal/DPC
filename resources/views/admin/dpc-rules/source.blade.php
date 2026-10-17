{{-- Where the rules in force come from: a recorded version, or the built-in rules. --}}
@if ($source === null)
    <div class="alert alert-light border small py-2">{{ __('dpcrules.built_in') }}</div>
@else
    <div class="alert alert-light border small py-2">
        {{ __('dpcrules.from_version', ['year' => $source->effective_from_year, 'order' => $source->order_number, 'date' => $source->order_date->format('d-m-Y')]) }}
        @if ($label) — <strong>{{ $label }}</strong>@endif
        · <a href="{{ $document }}">{{ $source->original_name }}</a>
    </div>
@endif
