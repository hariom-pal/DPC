{{--
    Resolves a logo file by name, whatever format it is in.

    Official artwork arrives as PNG far more often than SVG, and it gets
    replaced by people who should not have to edit a Blade template to do it.
    So every reference goes through here: drop `cgpsc-logo.png`, `.svg`,
    `.webp` or `.jpg` into `public/images/logo/` and it is picked up.

    A neutral `*.fallback.svg` is used only if no real asset is present, so a
    fresh checkout still renders rather than showing a broken image.

    Usage:
      @include('partials.logo', ['which' => 'cgpsc', 'class' => 'gov-logo', 'alt' => '…'])
--}}
@php
    $which = $which ?? 'cgpsc';
    $dir = 'images/logo/';
    $file = null;

    foreach (['svg', 'png', 'webp', 'jpg', 'jpeg'] as $ext) {
        if (is_file(public_path($dir.$which.'-logo.'.$ext))) {
            $file = $dir.$which.'-logo.'.$ext;
            break;
        }
    }

    $file ??= is_file(public_path($dir.$which.'-logo.fallback.svg'))
        ? $dir.$which.'-logo.fallback.svg'
        : null;
@endphp

@if ($file)
    <img src="{{ asset($file) }}"
         alt="{{ $alt ?? '' }}"
         class="{{ $class ?? '' }}"
         @isset($style) style="{{ $style }}" @endisset
         loading="{{ $loading ?? 'eager' }}"
         decoding="async">
@endif
