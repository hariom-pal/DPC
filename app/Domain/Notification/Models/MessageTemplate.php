<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Notification\Templates\MessageTemplateCatalog;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The Administrator's wording for one message on one channel (2026-11-12). See
 * {@see MessageTemplateCatalog} for the messages.
 *
 * @property int $id
 * @property string $key
 * @property string $channel
 * @property string|null $subject
 * @property string $body
 * @property string|null $dlt_template_id
 * @property bool $is_active
 * @property int|null $updated_by
 */
class MessageTemplate extends Model implements Auditable
{
    use AuditableTrait;

    public const SMS = 'sms';

    public const EMAIL = 'email';

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'channel', 'subject', 'body', 'dlt_template_id', 'is_active', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
