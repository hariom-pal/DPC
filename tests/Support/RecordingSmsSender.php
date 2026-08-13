<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Integrations\Contracts\SmsSender;

/**
 * Records the mobiles the application actually tried to message, so a test can
 * assert who a workflow notified rather than only what event it published.
 *
 * A static collector rather than a captured reference: PHP does not permit
 * by-reference constructor promotion, and the container may resolve this more
 * than once per test.
 */
class RecordingSmsSender implements SmsSender
{
    /** @var list<array{mobile: string, message: string}> */
    public static array $sent = [];

    public static function reset(): void
    {
        self::$sent = [];
    }

    /**
     * @return list<string>
     */
    public static function mobiles(): array
    {
        return array_values(array_map(static fn (array $row): string => $row['mobile'], self::$sent));
    }

    public function send(string $mobile, string $message): void
    {
        self::$sent[] = ['mobile' => $mobile, 'message' => $message];
    }
}
