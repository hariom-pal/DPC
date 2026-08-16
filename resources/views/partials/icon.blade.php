{{--
    Inline SVG icons.

    Inline rather than an icon font or a CDN sprite, for two reasons: the
    project has no Node build step by decision, and a government portal must
    render completely on a machine with no internet access. An icon that
    silently fails to load is worse than no icon.

    Usage: @include('partials.icon', ['name' => 'shield', 'size' => 20])
--}}
@php
    $size = $size ?? 22;
    $cls = $class ?? '';
    $paths = [
        'shield' => '<path d="M12 3l7 3v6c0 4.4-3 8.3-7 9-4-0.7-7-4.6-7-9V6l7-3z"/><path d="M9.5 12.5l1.8 1.8 3.4-3.6"/>',
        'paperless' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
        'audit' => '<path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h5"/><circle cx="16.5" cy="15.5" r="2.5"/><path d="M18.4 17.4L20 19"/>',
        'signature' => '<path d="M3 17c3 0 3-9 6-9s3 9 6 9 3-4 6-4"/><path d="M3 21h18"/>',
        'workflow' => '<rect x="3" y="3" width="6" height="5" rx="1"/><rect x="15" y="16" width="6" height="5" rx="1"/><rect x="9" y="9.5" width="6" height="5" rx="1"/><path d="M6 8v2.5a1 1 0 0 0 1 1h2M18 16v-2.5a1 1 0 0 0-1-1h-2"/>',
        'roles' => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><circle cx="17.5" cy="10" r="2.5"/><path d="M15 20a5 5 0 0 1 6.5-4.8"/>',
        'user-plus' => '<circle cx="10" cy="8" r="3.5"/><path d="M3 20a7 7 0 0 1 12.3-4.6"/><path d="M18 14v6M15 17h6"/>',
        'login' => '<path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/><path d="M10 17l-5-5 5-5"/><path d="M5 12h11"/>',
        'admin' => '<path d="M12 3l8 3.5v5c0 4.6-3.4 8.6-8 9.5-4.6-.9-8-4.9-8-9.5v-5L12 3z"/><circle cx="12" cy="11" r="2.2"/><path d="M8.5 16.5a4 4 0 0 1 7 0"/>',
        'book' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H19v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H19v3H6.5A2.5 2.5 0 0 1 4 20.5z"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.5a2.5 2.5 0 0 1 4.8.9c0 1.7-2.4 2-2.4 3.6"/><path d="M12 17.2h.01"/>',
        'flow-check' => '<circle cx="12" cy="12" r="9"/><path d="M8.2 12.4l2.6 2.6 5-5.4"/>',
        'bell' => '<path d="M18 15V10a6 6 0 1 0-12 0v5l-2 3h16z"/><path d="M10 21a2 2 0 0 0 4 0"/>',
        'eye' => '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.8"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14-4.5L4 9"/><path d="M4 5v4h4"/><path d="M4 13a8 8 0 0 0 14 4.5L20 15"/><path d="M20 19v-4h-4"/>',
        'circulate' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'publish' => '<path d="M12 16V4"/><path d="M8 8l4-4 4 4"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'lock' => '<rect x="4.5" y="10" width="15" height="10" rx="2"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10"/>',
        'transparency' => '<circle cx="12" cy="12" r="9"/><path d="M12 3v18M3 12h18"/>',
        'building' => '<path d="M3 21h18"/><path d="M5 21V8l7-5 7 5v13"/><path d="M9 21v-5h6v5"/><path d="M9 11h2M13 11h2"/>',
        'users' => '<circle cx="9" cy="9" r="3.2"/><path d="M3 19a6 6 0 0 1 12 0"/><path d="M16 6.5a3 3 0 0 1 0 5.5"/><path d="M17.5 19a5.5 5.5 0 0 0-2-4"/>',
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2.5h8a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1.1 1A16 16 0 0 1 4 5.1 1 1 0 0 1 5 4z"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5L12 13l8.5-6.5"/>',
        'inbox' => '<path d="M3 13h5l1.5 3h5L16 13h5"/><path d="M5 5h14l2 8v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4z"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
        'arrow-down' => '<path d="M12 5v14"/><path d="M6 13l6 6 6-6"/>',
    ];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24"
     fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
     class="{{ $cls }}" aria-hidden="true" focusable="false">{!! $paths[$name] ?? $paths['flow-check'] !!}</svg>
