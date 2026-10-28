<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Csc;

use RuntimeException;

/**
 * CSC answered with a `res_code` other than "10", so no UIDAI answer exists.
 *
 * NOT a failed authentication: the request never reached a verdict, so the
 * officer is neither verified nor refused and must be told the service failed,
 * not that the code was wrong.
 */
final class CscRejectedException extends RuntimeException
{
    public function __construct(
        public readonly string $responseCode,
        public readonly ?string $responseMessage,
    ) {
        parent::__construct(sprintf(
            'CSC rejected the request with code [%s]%s',
            $responseCode,
            $responseMessage !== null && $responseMessage !== '' ? ': '.$responseMessage : '.',
        ));
    }
}
