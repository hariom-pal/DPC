<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Master\Models\ClassMaster;
use Illuminate\Support\Collection;

/**
 * The Class Master's labels, read once per request (change item 1).
 *
 * **Why this is a scoped container binding and not a static on the model.** It
 * was a static, and the proposal-detail performance test found the problem: a
 * static outlives the request, so the first page in a process paid for the query
 * and every later page in the same process read a memo that the database had
 * possibly moved on from. Under a long-lived worker — Octane, or a queue worker
 * rendering a document — an administrator's rename would not appear until the
 * process was recycled.
 *
 * `$app->scoped()` is the right primitive: one instance per request, discarded
 * between them, and flushed by Octane on every request boundary. See
 * `AppServiceProvider` for the binding.
 *
 * **The memo still matters.** `ServiceClass::label()` is called from roughly
 * thirty view sites, several inside loops over a hundred officers; without it a
 * promotion list would issue a query per cell.
 */
class ClassLabelRepository
{
    /**
     * @var Collection<string, ClassMaster>|null
     */
    private ?Collection $rows = null;

    /**
     * The label for a class code in the active locale.
     *
     * Falls back to `Class-<code>` when the master holds no row — which is what a
     * migrated-but-unseeded database and a unit test that never touches the master
     * both look like. A label is presentation, so deriving one is safe in a way
     * that deriving a business value never is.
     */
    public function labelFor(string $code): string
    {
        $row = $this->rows()->get($code);

        if ($row === null) {
            return 'Class-'.$code;
        }

        return app()->getLocale() === 'hi' && $row->name_hi !== null && $row->name_hi !== ''
            ? $row->name_hi
            : $row->name_en;
    }

    /**
     * Discard the memo.
     *
     * Called by `ClassMaster`'s model events, so an administrator who renames a
     * class sees the new name on the very next screen within the same request.
     */
    public function flush(): void
    {
        $this->rows = null;
    }

    /**
     * `withTrashed()` deliberately: a class withdrawn from the master must still
     * render on the historical proposals that cite it. The record of what class a
     * post was does not disappear because the class was later retired.
     *
     * @return Collection<string, ClassMaster>
     */
    private function rows(): Collection
    {
        return $this->rows ??= ClassMaster::withTrashed()->get()->keyBy('code');
    }
}
