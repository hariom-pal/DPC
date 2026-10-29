<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A public asset's URL with its file's modification time attached (2026-10-29).
 *
 * The portal's scripts and stylesheets are plain files under `public/`, served
 * with long browser caching. Without a version in the URL a deployment that
 * changes `portal.js` or a form script keeps running the old copy in every
 * browser that has it cached — a release that looks as if nothing changed.
 * Used in views as `@versioned('js/portal.js')`.
 */
final class AssetVersion
{
    /** @var array<string, string> */
    private static array $urls = [];

    public static function url(string $path): string
    {
        return self::$urls[$path] ??= self::build($path);
    }

    private static function build(string $path): string
    {
        $file = public_path($path);
        $version = is_file($file) ? (string) filemtime($file) : null;

        return asset($path).($version !== null ? '?v='.$version : '');
    }
}
