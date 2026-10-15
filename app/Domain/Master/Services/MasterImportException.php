<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use RuntimeException;

/**
 * A bulk upload that was refused, carrying **every** reason rather than the first.
 *
 * An officer fixing a two-hundred-row spreadsheet one message at a time would
 * upload it twenty times. The service validates the whole file before writing
 * anything, so it always has the full list — this is what carries it back.
 */
class MasterImportException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0] ?? 'The file could not be loaded.');
    }
}
