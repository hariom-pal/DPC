<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * How e-mail (SMTP) or SMS goes out, kept by the CGPSC Administrator (2026-11-13).
 * One row per channel. The password or API key is encrypted and kept out of the
 * audit trail; every other change is audited.
 *
 * @property int $id
 * @property string $channel
 * @property bool $is_enabled
 * @property array<string, mixed>|null $settings
 * @property string|null $secret
 * @property int|null $updated_by
 */
class NotificationGateway extends Model implements Auditable
{
    use AuditableTrait;

    public const SMTP = 'smtp';

    public const SMS = 'sms';

    /**
     * @var list<string>
     */
    protected $fillable = ['channel', 'is_enabled', 'settings', 'secret', 'updated_by'];

    /**
     * Never written to the audit trail, and never sent to a page.
     *
     * @var list<string>
     */
    protected $auditExclude = ['secret'];

    /**
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'array',
            'secret' => 'encrypted',
        ];
    }

    public function setting(string $name, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$name] ?? $default;
    }
}
