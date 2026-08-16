<?php

declare(strict_types=1);

namespace App\Http\View\Composers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Live counts for the public landing page — presentation only.
 *
 * A view composer rather than a controller, matching the pattern the Review
 * banner already uses: the `/` route is a closure returning a view, and the
 * landing page's numbers are a presentation concern, not a business one.
 * **No business logic, no policy, no workflow.**
 *
 * **The numbers are real.** Illustrative figures on a screen shown to CGPSC
 * would be a claim about the system's use, and a wrong one.
 *
 * **Nothing here may break the landing page.** Every read is guarded: a
 * missing table (a fresh checkout before `migrate`), an unavailable database,
 * anything — the page still renders and the counters show zero. The home page
 * is the first thing anyone sees, and it failing because a count failed would
 * be the worst possible first impression.
 */
class PortalStatisticsComposer
{
    /**
     * Cached briefly so a landing page that is refreshed during a
     * demonstration does not run six aggregate queries each time.
     */
    private const TTL_SECONDS = 300;

    public function compose(View $view): void
    {
        $view->with('portalStats', $this->statistics());
    }

    /**
     * @return array<string, int>
     */
    private function statistics(): array
    {
        try {
            /** @var array<string, int> $stats */
            $stats = Cache::remember('portal.statistics', self::TTL_SECONDS, fn (): array => [
                'departments' => $this->count('departments'),
                'users' => $this->count('users'),
                'dpcs' => $this->count('dpc_proposals'),
                'reviews' => $this->count('dpc_review_links'),
                'circulations' => $this->count('dpc_circulations'),
                'documents' => $this->count('dpc_proceeding_documents')
                    + $this->count('dpc_circulation_documents'),
            ]);

            return $stats;
        } catch (Throwable) {
            // Cache or database unavailable. The page renders; the tiles show
            // zero. Never a 500 on the front door.
            return $this->zeroes();
        }
    }

    private function count(string $table): int
    {
        try {
            return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, int>
     */
    private function zeroes(): array
    {
        return [
            'departments' => 0, 'users' => 0, 'dpcs' => 0,
            'reviews' => 0, 'circulations' => 0, 'documents' => 0,
        ];
    }
}
