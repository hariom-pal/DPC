@props(['fit', 'title' => null])
{{-- Fit and Unfit are shown by colour alone, never in words (business instruction,
     2026-11-18): green for Fit, red for Unfit. The words stay for screen readers only. --}}
@if ($fit === null)
    <span {{ $attributes }}>—</span>
@else
    <span {{ $attributes->merge(['class' => 'fit-mark '.($fit ? 'fit-mark-fit' : 'fit-mark-unfit')]) }}
          role="img" aria-label="{{ $fit ? __('ui.fit_mark.fit') : __('ui.fit_mark.unfit') }}"
          @if ($title) title="{{ $title }}" @endif
          data-fit="{{ $fit ? 'fit' : 'unfit' }}"></span>
@endif
