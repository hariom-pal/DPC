<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * Auto-populates audit columns created_by / updated_by / deleted_by from the
 * authenticated user. No-ops when unauthenticated (console/seeders), leaving
 * the columns null. The consuming table must define these columns.
 */
trait Blameable
{
    public static function bootBlameable(): void
    {
        static::creating(function ($model): void {
            $id = Auth::id();
            if ($id !== null) {
                $model->created_by ??= $id;
                $model->updated_by ??= $id;
            }
        });

        static::updating(function ($model): void {
            $id = Auth::id();
            if ($id !== null) {
                $model->updated_by = $id;
            }
        });

        static::deleting(function ($model): void {
            $id = Auth::id();
            $softDeletes = in_array(
                SoftDeletes::class,
                class_uses_recursive($model),
                true,
            );

            if ($id !== null && $softDeletes && ! $model->isForceDeleting()) {
                $model->deleted_by = $id;
                $model->saveQuietly();
            }
        });
    }
}
