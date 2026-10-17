<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReservationRoster;
use App\Enums\MasterStatus;
use Illuminate\Database\Seeder;

/**
 * An illustrative 100-point roster for each seeded post — **inactive**, and not read
 * by any calculation: only PH reservation is in force in promotion. Kept so the
 * Reservation Roster screen has something to show.
 *
 * The allocation spreads each category's share evenly over the cycle; it is not a
 * statutory roster.
 */
class ReservationRosterSeeder extends Seeder
{
    /** @var array<string, int> */
    private const SHARES = ['ST' => 32, 'SC' => 13, 'OBC' => 27, 'EWS' => 10];

    public function run(): void
    {
        $categories = ReservationCategory::query()->pluck('id', 'code');

        if (! isset($categories['UR'])) {
            return;
        }

        foreach (DepartmentPost::query()->get() as $post) {
            $roster = ReservationRoster::query()->updateOrCreate(
                ['department_id' => $post->department_id, 'post_id' => $post->id, 'name' => '100-Point Roster (Illustrative)'],
                ['size' => 100, 'effective_from' => '2026-01-01', 'effective_to' => null, 'status' => MasterStatus::Inactive->value],
            );

            if ($roster->points()->exists()) {
                continue;
            }

            $allocation = $this->allocation();

            for ($point = 1; $point <= 100; $point++) {
                $roster->points()->create([
                    'point_no' => $point,
                    'reservation_category_id' => $categories[$allocation[$point] ?? 'UR'] ?? $categories['UR'],
                ]);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function allocation(): array
    {
        $allocation = [];

        foreach (self::SHARES as $code => $share) {
            $spacing = 100 / $share;

            for ($i = 0; $i < $share; $i++) {
                $point = (int) round($i * $spacing) + 1;

                while ($point <= 100 && isset($allocation[$point])) {
                    $point++;
                }

                if ($point <= 100) {
                    $allocation[$point] = $code;
                }
            }
        }

        return $allocation;
    }
}
