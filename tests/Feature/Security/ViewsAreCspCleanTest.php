<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The Content-Security-Policy is enforced, and this is what keeps it enforceable
 * (**TD-29**, closed 2026-08-22).
 *
 * **Why a test that reads Blade source rather than a response.** CSP is applied
 * by the browser, not the server: a screen with an inline `onsubmit` returns 200
 * whether the policy is enforced or not, and the button simply stops working for
 * the user. No request-level assertion can see that. The only thing a test *can*
 * see is the markup, so that is what this checks — one inline handler reintroduced
 * anywhere under `resources/views` fails the build, on the commit that added it,
 * rather than in somebody's browser after release.
 *
 * **What was cleared to get here.** 6 views with inline `<script>` blocks and 21
 * inline event handlers across 20 views. The scripts became six files under
 * `public/js/forms/`, seeded from `data-` attributes the browser reads as data.
 * The handlers became two delegated listeners in `public/js/portal.js`, declared
 * in the markup as `data-confirm` and `data-submit-on-change`. Behaviour is
 * identical, and it was verified as identical in headless Chromium with the policy
 * enforced before this default was changed.
 *
 * **The asymmetry stays.** Inline *styles* are permitted — several views set
 * element widths inline, the report bars among them — and inline *scripts* are
 * not, because an inline-script allowance is what turns a reflected string into
 * executable code. This test polices only the script side.
 *
 * @see SecurityHeadersTest for the header itself
 */
class ViewsAreCspCleanTest extends TestCase
{
    /**
     * Every DOM event attribute a browser will execute. Written out rather than
     * matched with `on\w+`, because `on\w+` also hits legitimate attributes —
     * Bootstrap's `data-bs-*`, and any future `only`/`once` attribute — and a
     * check that cries wolf gets deleted.
     */
    private const EVENT_ATTRIBUTES = [
        'onabort', 'onblur', 'oncancel', 'oncanplay', 'onchange', 'onclick', 'onclose',
        'oncontextmenu', 'oncopy', 'oncut', 'ondblclick', 'ondrag', 'ondragend',
        'ondragenter', 'ondragleave', 'ondragover', 'ondragstart', 'ondrop',
        'onerror', 'onfocus', 'oninput', 'oninvalid', 'onkeydown', 'onkeypress',
        'onkeyup', 'onload', 'onloadeddata', 'onmousedown', 'onmouseenter',
        'onmouseleave', 'onmousemove', 'onmouseout', 'onmouseover', 'onmouseup',
        'onpaste', 'onplay', 'onpointerdown', 'onpointerup', 'onreset', 'onresize',
        'onscroll', 'onselect', 'onsubmit', 'ontoggle', 'ontouchend', 'ontouchstart',
        'onwheel',
    ];

    public function test_no_view_contains_an_inline_event_handler(): void
    {
        $offenders = [];

        foreach ($this->views() as $path => $contents) {
            foreach (self::EVENT_ATTRIBUTES as $attribute) {
                // `\s` before the name so that `data-on-click` or a word ending
                // in the attribute name is not a match; `=` after it so that
                // prose mentioning "onsubmit" in a Blade comment is not either.
                if (preg_match('/\s'.$attribute.'\s*=/i', $contents) === 1) {
                    $offenders[] = $path.' → '.$attribute;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            [
                'These views carry an inline event handler, which an enforced',
                "`script-src 'self'` refuses — the control silently stops working.",
                'Move the behaviour into public/js/ and declare it with a data- attribute:',
            ],
            $offenders,
        )));
    }

    public function test_no_view_contains_an_inline_script_block(): void
    {
        $offenders = [];

        foreach ($this->views() as $path => $contents) {
            // A `<script>` with no `src` is an inline script. Matched on the open
            // tag so that `<script src="…">` and `<script defer src="…">` pass.
            if (preg_match_all('/<script\b([^>]*)>/i', $contents, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $attributes) {
                if (! preg_match('/\bsrc\s*=/i', $attributes)) {
                    $offenders[] = $path;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), implode("\n", array_merge(
            ['These views contain an inline <script> block, which an enforced CSP refuses:'],
            $offenders,
        )));
    }

    /**
     * A `javascript:` URL is an inline script wearing an href.
     */
    public function test_no_view_contains_a_javascript_url(): void
    {
        $offenders = [];

        foreach ($this->views() as $path => $contents) {
            if (stripos($contents, 'javascript:') !== false) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * Every script the views load must exist on disk.
     *
     * The failure this catches is specific and quiet: extracting an inline block
     * to a file and mistyping the path leaves a screen that renders perfectly,
     * returns 200, and has no working buttons. There is no CSP violation to
     * report, and nothing else in the suite would notice.
     */
    public function test_every_script_a_view_loads_exists(): void
    {
        $missing = [];

        foreach ($this->views() as $path => $contents) {
            preg_match_all("/asset\(\s*'((?:js|vendor)\/[^']+\.js)'\s*\)/", $contents, $matches);

            foreach ($matches[1] as $asset) {
                if (! is_file(public_path($asset))) {
                    $missing[] = $path.' → public/'.$asset;
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['These views load a script that is not on disk:'],
            $missing,
        )));
    }

    /**
     * Every extracted form script is actually reachable from a view.
     *
     * The mirror of the test above: a file nobody loads is a file whose behaviour
     * silently is not there. Both directions have to hold or the extraction is
     * only half done.
     */
    public function test_every_extracted_form_script_is_loaded_by_a_view(): void
    {
        $loaded = [];

        foreach ($this->views() as $contents) {
            // `@versioned('js/…')` since 2026-10-29; `asset('js/…')` still counts.
            preg_match_all("/(?:asset|@versioned)\(\s*'(js\/[^']+\.js)'\s*\)/", $contents, $matches);
            $loaded = array_merge($loaded, $matches[1]);
        }

        $orphans = [];

        foreach (Finder::create()->files()->in(public_path('js'))->name('*.js') as $file) {
            $relative = 'js/'.str_replace(DIRECTORY_SEPARATOR, '/', (string) $file->getRelativePathname());

            if (! in_array($relative, $loaded, true)) {
                $orphans[] = 'public/'.$relative;
            }
        }

        $this->assertSame([], $orphans, implode("\n", array_merge(
            ['These scripts exist but no view loads them:'],
            $orphans,
        )));
    }

    /**
     * Every Blade file under `resources/views`, keyed by its path relative to
     * that directory.
     *
     * @return array<string, string>
     */
    private function views(): array
    {
        $views = [];

        /** @var SplFileInfo $file */
        foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            $views[(string) $file->getRelativePathname()] = (string) file_get_contents((string) $file->getRealPath());
        }

        $this->assertGreaterThan(100, count($views), 'The view finder found almost nothing — it is looking in the wrong place.');

        return $views;
    }
}
