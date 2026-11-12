<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use RuntimeException;

/**
 * An SMS refused before it reached the gateway because its text has no DLT Content
 * Template ID (2026-11-13): the operator would reject it, and no ID is ever guessed.
 */
final class DltTemplateMissing extends RuntimeException {}
