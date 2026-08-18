<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Carbon;

/**
 * Lets the demo seeders walk a DPC forward through time.
 *
 * Every service in this application stamps its records with `now()` — that is
 * the point of them. Seeding a whole journey in one real second would produce
 * a legally-shaped record whose Activity Timeline shows twenty events at the
 * same instant, and the timeline is one of the screens being demonstrated.
 *
 * So the demo seeders move the clock instead: each step is written at the date
 * it would plausibly have happened, and the timeline, allotment history,
 * scheduling history and proceeding record all read as a real case file.
 *
 * **Seeder-only.** Nothing in `app/` uses this, and the clock is always
 * restored, so no application behaviour depends on it.
 */
final class DemoClock
{
    /**
     * Run a step as though it happened at the given moment.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    public static function at(string $moment, callable $step): mixed
    {
        Carbon::setTestNow(Carbon::parse($moment, config('dpc.timezone')));

        try {
            return $step();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Restore real time. Called once at the end of seeding as a belt-and-braces
     * guard: `at()` already restores it on every path, including exceptions.
     */
    public static function release(): void
    {
        Carbon::setTestNow();
    }
}
