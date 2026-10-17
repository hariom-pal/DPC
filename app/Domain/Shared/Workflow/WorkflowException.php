<?php

declare(strict_types=1);

namespace App\Domain\Shared\Workflow;

use RuntimeException;

/**
 * A business rule refused the action. `$field`, when given, names the form field the
 * rule is about, so the screen can show the message beside that field.
 */
class WorkflowException extends RuntimeException
{
    public function __construct(string $message = '', public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
