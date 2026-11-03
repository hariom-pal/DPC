<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/**
 * Persists reference-master records and appends an Activity Timeline entry for
 * each create/update/delete (owen-it captures the field-level audit).
 */
class MasterCrudService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    public function create(Model $model, array $data): Model
    {
        // A deleted entry may be entered again (2026-11-02): the deleted row comes
        // back with the new details, keeping its id and every reference to it, rather
        // than a second row colliding with it on the unique index.
        $deleted = $this->deletedTwin($model, $data);

        if ($deleted !== null) {
            $deleted->restore();   // @phpstan-ignore method.notFound
            $deleted->fill($data)->save();
            $this->timeline->record('master.restored', $deleted, class_basename($deleted).' entered again');

            return $deleted;
        }

        $model->fill($data)->save();
        $this->timeline->record('master.created', $model, class_basename($model).' created');

        return $model;
    }

    /**
     * The deleted row holding one of the new entry's unique values, if any.
     *
     * Read off the table's own single-column unique indexes, so every soft-deleting
     * master is covered without a list.
     *
     * @param  array<string, mixed>  $data
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel|null
     */
    public function deletedTwin(Model $model, array $data): ?Model
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return null;
        }

        foreach (Schema::getIndexes($model->getTable()) as $index) {
            if (! $index['unique'] || $index['primary'] || count($index['columns']) !== 1) {
                continue;
            }

            $column = $index['columns'][0];
            $value = $data[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            /** @var TModel|null $twin */
            $twin = $model->newQuery()->onlyTrashed()->where($column, $value)->first();   // @phpstan-ignore method.notFound

            if ($twin !== null) {
                return $twin;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    public function update(Model $model, array $data): Model
    {
        $model->fill($data)->save();
        $this->timeline->record('master.updated', $model, class_basename($model).' updated');

        return $model;
    }

    public function delete(Model $model): void
    {
        $this->timeline->record('master.deleted', $model, class_basename($model).' deleted');
        $model->delete();
    }
}
