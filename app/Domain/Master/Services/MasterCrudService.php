<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Database\Eloquent\Model;

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
        $model->fill($data)->save();
        $this->timeline->record('master.created', $model, class_basename($model).' created');

        return $model;
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
