<?php

declare(strict_types=1);

namespace App\Integrations\HRMS;

use App\Integrations\Contracts\HrmsProvider;
use RuntimeException;

/**
 * Production HRMS provider — the target implementation for when CGPSC
 * supplies the HRMS API contract and credentials.
 *
 * Unimplemented by directive: the API does not exist yet. Like
 * CscDigiSignProvider, it throws rather than degrading quietly, so enabling
 * `FEATURE_HRMS` before the integration is written fails immediately and
 * visibly instead of returning empty results that would look like "this
 * officer is not in HRMS".
 *
 * When the API arrives, only this class, `config/features.php`, and the
 * credentials change. See docs/integration/HRMS.md.
 */
class RealHrmsProvider implements HrmsProvider
{
    public function name(): string
    {
        return 'hrms';
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
        throw new RuntimeException(
            'HRMS integration is enabled but not yet implemented. Provide the HRMS API contract and credentials, '
            .'or set FEATURE_HRMS=false to continue with manual entry and Excel import.'
        );
    }
}
