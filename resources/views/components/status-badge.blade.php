@props(['status'])

<span {{ $attributes->merge(['class' => 'badge '.$status->badgeClass()]) }}>{{ $status->label() }}</span>
