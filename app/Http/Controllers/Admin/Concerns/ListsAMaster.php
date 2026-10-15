<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use App\Domain\Master\Services\MasterDataPortabilityService;
use App\Domain\Master\Support\MasterRegistry;
use App\Http\Controllers\Admin\MasterDataController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The `?q=` an admin master listing is filtered by (business instruction,
 * 2026-09-06: *"search and export to CSV should be there all over the admin"*).
 *
 * Export and bulk upload are handled entirely by
 * {@see MasterDataController}, because neither needs to
 * know anything about the screen. Search is the one of the three that has to reach
 * into the listing's own query, so it arrives here instead — one method, called from
 * each master's `index()`, reading which columns are searchable from the same
 * {@see MasterRegistry} entry the other two features read.
 *
 * A master with no registry entry is simply not filtered, rather than erroring: a
 * screen that has not been described yet keeps working, and gains search the moment
 * it is described.
 */
trait ListsAMaster
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function searched(Request $request, Builder $query, string $master): Builder
    {
        $definition = MasterRegistry::find($master);

        if ($definition === null) {
            return $query;
        }

        return app(MasterDataPortabilityService::class)
            ->search($query, $request->query('q'), $definition->searchable);
    }
}
