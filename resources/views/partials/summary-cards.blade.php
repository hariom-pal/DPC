{{--
    Dashboard summary cards.

    One group per role the signed-in user holds. A card states a count and links
    to the screen that governs it — it never claims an action is available,
    because only that screen can decide that.

    Expects: $group = ['title', 'subtitle', 'cards' => [['label','value','hint','href','tone'], ...]]
--}}
@php
    $tones = [
        'action' => ['border-warning-subtle', 'text-warning-emphasis'],
        'warn' => ['border-danger-subtle', 'text-danger-emphasis'],
        'good' => ['border-success-subtle', 'text-success-emphasis'],
        'muted' => ['border-light-subtle', 'text-body-emphasis'],
    ];
@endphp

<div class="gov-card p-4 h-100">
    <div class="d-flex justify-content-between align-items-baseline mb-1 gap-2 flex-wrap">
        <h2 class="h6 mb-0">{{ $group['title'] }}</h2>
    </div>
    <p class="small text-body-secondary mb-3">{{ $group['subtitle'] }}</p>

    <div class="row g-3">
        @foreach ($group['cards'] as $card)
            @php([$border, $figure] = $tones[$card['tone']] ?? $tones['muted'])
            <div class="col-6 col-xl-4">
                @if ($card['href'])
                    <a href="{{ $card['href'] }}"
                       class="gov-card p-3 h-100 d-block text-decoration-none border {{ $border }}">
                @else
                    <div class="gov-card p-3 h-100 border {{ $border }}">
                @endif
                    <div class="fs-3 fw-semibold lh-1 {{ $figure }}">{{ number_format($card['value']) }}</div>
                    <div class="small fw-semibold mt-2">{{ $card['label'] }}</div>
                    <div class="small text-body-secondary">{{ $card['hint'] }}</div>
                @if ($card['href'])
                    </a>
                @else
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
