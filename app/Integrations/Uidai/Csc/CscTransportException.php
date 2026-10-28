<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Csc;

use RuntimeException;

/**
 * CSC could not be reached, did not answer in time, or answered with something
 * that is not a CSC envelope.
 *
 * Distinct from {@see CscRejectedException}: there CSC answered and said no.
 * Here we do not know whether UIDAI saw the request at all.
 */
final class CscTransportException extends RuntimeException {}
