<?php

declare(strict_types=1);

namespace App\Domain\Master\Support;

use App\Domain\Master\Models\ReservationCategory;

/**
 * Whether promotion is reserved category-wise, and the one pool when it is not.
 *
 * Today there is no category-wise reservation in promotion — only PH (rule 2.1),
 * which is the PH Reservation Policy. So officers and vacancies are not asked for a
 * category: every vacancy belongs to one pool (Unreserved) and one Main / Waiting
 * list is drawn. `dpc.reservation.category_wise` turns category-wise lists back on.
 */
final class ReservationMode
{
    public function categoryWise(): bool
    {
        return (bool) config('dpc.reservation.category_wise', false);
    }

    public function poolCode(): string
    {
        return (string) config('dpc.reservation.pool_category', 'UR');
    }

    /**
     * The pool's category row, whatever its status: it is inactive in the master
     * because nobody chooses it, yet every vacancy is recorded against it.
     */
    public function poolCategoryId(): ?int
    {
        $id = ReservationCategory::query()->where('code', $this->poolCode())->value('id');

        return $id === null ? null : (int) $id;
    }
}
