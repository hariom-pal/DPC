<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Eligibility\Services\DpcCalculationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The waiting list's size (business instruction, 2026-11-18): two for a main list of
 * one to seven; from eight, 25% of the main list, .5 and above rounded up, below .5
 * rounded down.
 */
final class WaitingListSizeTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function sizes(): array
    {
        return [
            'none selected' => [0, 0],
            '1 selected' => [1, 2],
            '7 selected' => [7, 2],
            '8 selected (2.0)' => [8, 2],
            '9 selected (2.25 down)' => [9, 2],
            '10 selected (2.5 up)' => [10, 3],
            '33 selected (8.25 down)' => [33, 8],
            '34 selected (8.5 up)' => [34, 9],
            '35 selected (8.75 up)' => [35, 9],
        ];
    }

    #[DataProvider('sizes')]
    public function test_the_waiting_list_size(int $selected, int $expected): void
    {
        $this->assertSame($expected, DpcCalculationService::waitingListSize($selected));
    }
}
