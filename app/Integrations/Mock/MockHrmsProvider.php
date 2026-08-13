<?php

declare(strict_types=1);

namespace App\Integrations\Mock;

use App\Integrations\Contracts\HrmsProvider;

/**
 * The provider used while `FEATURE_HRMS` is off — which is every environment
 * today.
 *
 * This is not a pretend HRMS. It is an honest statement that no HRMS is
 * connected: `isAvailable()` returns false and `findEmployee()` returns null,
 * so any future caller is told plainly to fall back to manual entry or Excel
 * import. It never fabricates employee data, because a plausible-looking
 * fake officer record flowing into a promotion proposal would be far worse
 * than an empty result.
 */
class MockHrmsProvider implements HrmsProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEmployee(string $employeeNumber): ?array
    {
        return null;
    }
}
