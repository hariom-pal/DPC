<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * Source of administrative-area (district) data, aligned to the LG Directory.
 * A seed provider serves a static Chhattisgarh list in dev; a live LGD-API
 * provider replaces it later with no calling-code change.
 */
interface DirectoryProvider
{
    /**
     * @return list<array{lgd_code:?string,state_lgd_code:string,name_en:string,name_hi:string}>
     */
    public function districts(): array;
}
