<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Report the state of the language catalogue in a translator's terms
 * (suggestion 12).
 *
 * **The checks already exist; the interface did not.** `BilingualCoverageTest`
 * holds every guarantee change item 7 asks for — key parity, no key resolving to
 * an array, every view compiling, and no screen rendering text that did not come
 * from the catalogue. But it runs in the suite, which means the person most likely
 * to need the answer has to run PHPUnit and read its output to find out whether
 * their file is complete. That is the wrong front door.
 *
 * No new logic lives here. This asks the same questions and prints the answers.
 */
class CheckLanguageCatalogueCommand extends Command
{
    protected $signature = 'lang:check
        {--locale=hi : The locale to compare against English}';

    protected $description = 'Report missing, extra and untranslated keys in the language catalogue';

    public function handle(): int
    {
        $locale = (string) $this->option('locale');

        if (! File::isDirectory(lang_path($locale))) {
            $this->error("There is no `lang/{$locale}` directory.");

            return self::FAILURE;
        }

        $missing = [];
        $extra = [];
        $identical = [];

        foreach ($this->groups() as $group) {
            $english = $this->flatten(lang_path("en/{$group}.php"));
            $target = File::exists(lang_path("{$locale}/{$group}.php"))
                ? $this->flatten(lang_path("{$locale}/{$group}.php"))
                : [];

            foreach ($english as $key => $value) {
                if (! array_key_exists($key, $target)) {
                    $missing[] = "{$group}.{$key}";

                    continue;
                }

                // Not a fault — a code, a number or a proper noun is often the
                // same in both — but it is where an untranslated string hides,
                // so it is reported separately rather than counted as missing.
                if ($target[$key] === $value && preg_match('/[A-Za-z]{4}/', $value) === 1) {
                    $identical[] = "{$group}.{$key}";
                }
            }

            foreach (array_keys($target) as $key) {
                if (! array_key_exists($key, $english)) {
                    $extra[] = "{$group}.{$key}";
                }
            }
        }

        $this->report("Missing from {$locale}", $missing, 'Laravel falls back to English, so these show an English string to a reader who chose '.$locale.'.');
        $this->report("In {$locale} but not in English", $extra, 'These are read by nothing — a key is looked up by its English path.');
        $this->report('Identical in both', $identical, 'Often correct. Worth a look: this is where an untranslated string hides.');

        if ($missing !== [] || $extra !== []) {
            return self::FAILURE;
        }

        $this->info('The catalogue is complete.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $keys
     */
    private function report(string $heading, array $keys, string $why): void
    {
        $this->newLine();
        $this->line("<options=bold>{$heading}: ".count($keys).'</>');

        if ($keys === []) {
            return;
        }

        $this->line("<fg=gray>{$why}</>");

        foreach (array_slice($keys, 0, 40) as $key) {
            $this->line("  - {$key}");
        }

        if (count($keys) > 40) {
            $this->line('  … and '.(count($keys) - 40).' more.');
        }
    }

    /**
     * @return list<string>
     */
    private function groups(): array
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
        $tree = require $path;

        if (! is_array($tree)) {
            return [];
        }

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
}
