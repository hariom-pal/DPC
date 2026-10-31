<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Change item 7: the application is bilingual, and no screen carries hardcoded
 * text.
 *
 * **Three guarantees, each of which failed silently before this test existed.**
 * A missing Hindi key falls back to English, so a half-translated portal looks
 * fine in testing and wrong to the user who switched language. A string left in a
 * Blade template cannot be translated at all, and nothing would say so. And a
 * translation file that parses but whose key resolves to an *array* renders a
 * PHP notice on a government screen — which is exactly what happened once during
 * the extraction, on the 403 page.
 */
class BilingualCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every language group the application ships.
     *
     * Read from the filesystem rather than listed, so a new group cannot be added
     * without this test noticing it.
     *
     * @return list<string>
     */
    private function languageGroups(): array
    {
        return collect(File::files(lang_path('en')))
            ->map(fn ($file): string => $file->getFilenameWithoutExtension())
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function flatten(string $path): array
    {
        /** @var array<string, mixed> $tree */
        $tree = require $path;

        $walk = function (array $node, string $prefix = '') use (&$walk): array {
            $flat = [];
            foreach ($node as $key => $value) {
                $full = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
                if (is_array($value)) {
                    $flat += $walk($value, $full);
                } else {
                    $flat[$full] = (string) $value;
                }
            }

            return $flat;
        };

        return $walk($tree);
    }

    public function test_every_english_key_has_a_hindi_counterpart(): void
    {
        $missing = [];

        foreach ($this->languageGroups() as $group) {
            $hindiPath = lang_path("hi/{$group}.php");

            $this->assertFileExists($hindiPath, "There is no Hindi file for the `{$group}` group.");

            $english = $this->flatten(lang_path("en/{$group}.php"));
            $hindi = $this->flatten($hindiPath);

            foreach (array_keys($english) as $key) {
                if (! array_key_exists($key, $hindi)) {
                    $missing[] = "{$group}.{$key}";
                }
            }
        }

        $this->assertSame([], $missing, sprintf(
            '%d translation key(s) exist in English and not in Hindi. Laravel falls back to English for a '
            ."missing key, so a gap here shows an English string to a user who chose Hindi:\n  - %s",
            count($missing),
            implode("\n  - ", array_slice($missing, 0, 40)),
        ));
    }

    /**
     * A key must resolve to a string, in both locales.
     *
     * **This is not hypothetical.** During the extraction, `ui.dashboard` was both
     * a shared label and the prefix of the dashboard view's own keys, so `__()`
     * returned an array and every 403 page rendered a 500 instead. The suite
     * caught it only because 167 unrelated tests went red at once; this catches it
     * directly.
     */
    public function test_no_key_resolves_to_an_array_in_either_locale(): void
    {
        $offenders = [];

        foreach (['en', 'hi'] as $locale) {
            app()->setLocale($locale);

            foreach ($this->languageGroups() as $group) {
                foreach (array_keys($this->flatten(lang_path("{$locale}/{$group}.php"))) as $key) {
                    // A leaf whose parent path is also asked for by a view is the
                    // shape that breaks: check the *parents*, since the leaf itself
                    // is a string by construction.
                    $parts = explode('.', $key);

                    while (count($parts) > 1) {
                        array_pop($parts);
                        $parent = "{$group}.".implode('.', $parts);

                        if (is_array(__($parent)) && $this->isReferencedByAView($parent)) {
                            $offenders[] = "{$locale}: {$parent}";
                        }
                    }
                }
            }
        }

        app()->setLocale('en');

        $this->assertSame([], array_values(array_unique($offenders)), sprintf(
            'A translation key that a view asks for resolves to an array rather than a string. '
            ."`{{ __('…') }}` then renders a TypeError and the page 500s:\n  - %s",
            implode("\n  - ", array_unique($offenders)),
        ));
    }

    /**
     * Whether a view asks for this key **as a string**.
     *
     * `(array) __('landing.faq')` is deliberate and safe: the landing page loops
     * over the catalogue so a card cannot exist untranslated, and a group key is
     * exactly what it wants. What breaks is the same key rendered as text, so the
     * cast is what distinguishes a correct use from the fault this test is for.
     */
    private function isReferencedByAView(string $key): bool
    {
        static $sources = null;

        if ($sources === null) {
            $sources = '';
            foreach (File::allFiles(resource_path('views')) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $sources .= File::get($file->getPathname());
                }
            }
        }

        foreach ($this->offsetsOf($sources, "__('{$key}')") as $offset) {
            if (! str_ends_with(substr($sources, 0, $offset), '(array) ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function offsetsOf(string $haystack, string $needle): array
    {
        $offsets = [];
        $from = 0;

        while (($at = strpos($haystack, $needle, $from)) !== false) {
            $offsets[] = $at;
            $from = $at + 1;
        }

        return $offsets;
    }

    /**
     * Every Blade view compiles.
     *
     * The extraction rewrote 1,503 strings across 120 views, and two of them sat
     * inside a PHP string literal in a ternary — which produces a template that
     * parses as Blade and then fails as PHP. A compile check finds that class of
     * fault in one pass, where rendering finds it one screen at a time.
     */
    public function test_every_view_compiles(): void
    {
        $compiler = app('blade.compiler');
        $broken = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $php = $compiler->compileString(File::get($file->getPathname()));
            $temporary = tempnam(sys_get_temp_dir(), 'blade').'.php';
            File::put($temporary, $php);

            exec('php -l '.escapeshellarg($temporary).' 2>&1', $output, $status);
            File::delete($temporary);

            if ($status !== 0) {
                $broken[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $broken, sprintf(
            "%d view(s) compile to invalid PHP:\n  - %s",
            count($broken),
            implode("\n  - ", $broken),
        ));
    }

    /**
     * No view carries a hardcoded sentence.
     *
     * Deliberately narrow: it looks for element text that is a *sentence* — three
     * or more words of letters — rather than for any literal at all. A bare `—`,
     * a units suffix, or a Blade-interpolated fragment is not a translation
     * failure, and a check that flagged them would be turned off within a week.
     */
    public function test_no_view_carries_a_hardcoded_sentence(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $path = str_replace(base_path().'/', '', $file->getPathname());

            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            /*
             | `documents/` is exempt, and that exemption is a business decision
             | rather than an oversight: those templates render the **signed**
             | proceedings, promotion list and circulation note. Translating a
             | legally significant government order changes the language it is
             | issued in, which is the Commission's call. Recorded as a Pending
             | Business Question.
             */
            if (str_contains($path, 'views/documents/')) {
                continue;
            }

            $source = File::get($file->getPathname());

            // Strip Blade comments first: they are prose by design.
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

            /*
             | Inline tags are in the list, and were not at first. That omission
             | is what let the landing page keep a whole page of English prose,
             | and the admin sidebar keep nineteen untranslated nav labels, long
             | after the extraction was believed complete.
             */
            preg_match_all(
                '/<(h1|h2|h3|h4|h5|h6|p|label|button|th|dt|legend|a|span|div|dd|li|small|strong|em|td|summary|caption|figcaption)'
                .'\b[^<>]*>([^<>{}@]+)<\/\1>/',
                $source,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $text = trim($match[2]);

                if (preg_match('/(?:\b[A-Za-z]{2,}\b[^A-Za-z]*){3,}/', $text) === 1) {
                    $offenders[] = "{$path}: \"{$text}\"";
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            '%d hardcoded sentence(s) remain in Blade templates. Change item 7 requires every user-visible '
            ."string to come from a language file:\n  - %s",
            count($offenders),
            implode("\n  - ", array_slice($offenders, 0, 30)),
        ));
    }

    /**
     * No screen renders a word of static English.
     *
     * **This is the check that actually holds item 7, and it took three tries to
     * get right.** Reading the *template* for text between tags misses everything
     * the template does not spell out inline: a label ending in a required-field
     * asterisk, a nav link, a sentence broken across a `<strong>`. Two earlier
     * checks looked at exactly those places and passed while 283 strings were
     * still hardcoded, which is worse than having no check — it certified the
     * work as finished.
     *
     * So this compiles the view, deletes every PHP island, and looks at what is
     * left. What is left is precisely the text the page prints without asking the
     * catalogue: nothing else can survive that filter, and nothing that survives
     * it is translatable. There is no cleverness left to defeat.
     */
    public function test_no_view_renders_untranslated_text(): void
    {
        $compiler = app('blade.compiler');
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $path = str_replace(base_path().'/', '', $file->getPathname());

            if (! str_ends_with($file->getFilename(), '.blade.php') || str_contains($path, 'views/documents/')) {
                continue;
            }

            $html = (string) preg_replace(
                ['/<\?php.*?\?>/s', '/<\?php.*$/s', '/<(script|style)\b.*?<\/\1>/s'],
                '',
                $compiler->compileString(File::get($file->getPathname())),
            );

            preg_match_all('/>([^<>]+)</s', $html, $matches);

            foreach ($matches[1] as $text) {
                $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode($text)), " \t\n\r*:·—-|/,()");

                // A word is two or more letters together. That excludes the
                // punctuation, dashes, digits and symbols that carry no language,
                // and the handful of tokens that are the same in both — a file
                // format, a date pattern, a hash name.
                if (! preg_match('/[A-Za-z]{2}/', $text)) {
                    continue;
                }

                if (preg_match('/^(EN|HI|A-|A\+|CSV|XLSX|PDF|OK|N\/A|DD-MM-YYYY|SHA-256)$/', $text) === 1) {
                    continue;
                }

                $offenders[] = "{$path}: \"{$text}\"";
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "%d string(s) are rendered without passing through a language file:\n  - %s",
            count($offenders),
            implode("\n  - ", array_slice($offenders, 0, 40)),
        ));
    }

    /**
     * No view declares a sentence in PHP either.
     *
     * A string in a `@php` block or an `@section`/`@include` argument is invisible
     * to a check that reads element text — and that is precisely where the worst
     * of it hid: the dashboard's role tiles, the landing page's role cards, every
     * "Edit X" page title. The landing page went on naming a *Department
     * Administrator* for weeks after that role was deleted, because no test could
     * see the array it was named in.
     */
    public function test_no_view_declares_a_hardcoded_sentence_in_php(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $path = str_replace(base_path().'/', '', $file->getPathname());

            if (! str_ends_with($file->getFilename(), '.blade.php') || str_contains($path, 'views/documents/')) {
                continue;
            }

            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', File::get($file->getPathname()));

            foreach ($this->phpRegionsOf($source) as $region) {
                // Comments are prose by design; a key inside `__()` is not text.
                $region = (string) preg_replace(['/\/\*.*?\*\//s', '/\/\/[^\n]*/', "/__\(\s*'[^']*'/"], '', $region);

                preg_match_all('/\'((?:[^\'\\\\\n]|\\\\.)*)\'|"((?:[^"\\\\\n]|\\\\.)*)"/', $region, $literals, PREG_SET_ORDER);

                foreach ($literals as $literal) {
                    $text = trim($literal[1] !== '' ? $literal[1] : ($literal[2] ?? ''));

                    if ($this->looksLikeProse($text)) {
                        $offenders[] = "{$path}: \"{$text}\"";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "%d hardcoded sentence(s) are declared in PHP inside Blade templates:\n  - %s",
            count($offenders),
            implode("\n  - ", array_slice($offenders, 0, 30)),
        ));
    }

    /**
     * No view echoes an English label, nor puts one in an attribute a screen reader
     * announces (2026-10-31).
     *
     * The checks above read element text and multi-word prose, and so they missed
     * the one-word labels inside an echo — `{{ $user->is_active ? 'Deactivate' :
     * 'Activate' }}`, a page title "Edit District", "Open" or "Mark read" — and an
     * `aria-label="breadcrumb"`. Each of those stayed English for a user who chose
     * Hindi. A capitalised word in quotes inside `{{ }}` is a label by this
     * portal's own convention: values, keys, formats and classes are lower case.
     */
    public function test_no_view_echoes_an_english_label(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $path = str_replace(base_path().'/', '', $file->getPathname());

            if (! str_ends_with($file->getFilename(), '.blade.php') || str_contains($path, 'views/documents/')) {
                continue;
            }

            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', File::get($file->getPathname()));

            preg_match_all('/\{\{(.*?)\}\}/s', $source, $echoes);
            preg_match_all("/@section\(\s*'title'\s*,(.*?)\)\s*$/m", $source, $titles);

            foreach ([...$echoes[1], ...$titles[1]] as $echo) {
                $echo = (string) preg_replace("/__\(\s*'[^']*'/", '', $echo);

                preg_match_all("/'([A-Z][a-z]+(?: [A-Za-z()\-]+)*)'/", $echo, $literals);

                foreach ($literals[1] as $literal) {
                    if (! in_array($literal, ['Y', 'Asia/Kolkata'], true)) {
                        $offenders[] = "{$path}: '{$literal}'";
                    }
                }
            }

            preg_match_all('/\b(?:aria-label|title|placeholder)="([^"{]*[A-Za-z]{3,}[^"{]*)"/', $source, $attributes);

            foreach ($attributes[1] as $attribute) {
                $offenders[] = "{$path}: attribute \"{$attribute}\"";
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "%d English label(s) are echoed or announced without a language file:\n  - %s",
            count($offenders),
            implode("\n  - ", array_slice($offenders, 0, 40)),
        ));
    }

    /**
     * The `@php` blocks and directive arguments of a template.
     *
     * @return list<string>
     */
    private function phpRegionsOf(string $source): array
    {
        $regions = [];

        preg_match_all('/@php\b(.*?)@endphp/s', $source, $blocks);
        foreach ($blocks[1] as $block) {
            $regions[] = $block;
        }

        // Directive arguments need a balanced scan rather than a regex: the
        // arguments routinely contain `->` and nested parentheses.
        preg_match_all('/@(?:section|include|includeIf|includeWhen)\s*\(/', $source, $starts, PREG_OFFSET_CAPTURE);

        foreach ($starts[0] as [, $offset]) {
            $at = $offset + strlen($starts[0][0][0] ?? '');
            $at = strpos($source, '(', $offset) + 1;
            $depth = 1;
            $end = $at;

            while ($end < strlen($source) && $depth > 0) {
                $depth += match ($source[$end]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                $end++;
            }

            $regions[] = substr($source, $at, $end - $at - 1);
        }

        return $regions;
    }

    /**
     * Whether a literal is user-visible prose rather than an identifier.
     *
     * Three or more words, at least one space, and either a capital letter or
     * closing punctuation. That excludes CSS class lists, route names and SVG
     * path data — all of which are lowercase, unpunctuated, or start with `<` —
     * without excluding anything a translator would need.
     */
    private function looksLikeProse(string $text): bool
    {
        if ($text === '' || str_starts_with($text, '<') || ! str_contains($text, ' ')) {
            return false;
        }

        if (preg_match('/(?:\b[A-Za-z]{2,}\b[^A-Za-z]*){3,}/', $text) !== 1) {
            return false;
        }

        return preg_match('/[A-Z]/', $text) === 1 || in_array(substr($text, -1), ['.', '?', '!'], true);
    }
}
