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
 * @property string $status draft, active or inactive (2026-11-13)
 * @property int|null $updated_by
 */
class MessageTemplate extends Model implements Auditable
{
    use AuditableTrait;

    public const SMS = 'sms';

    public const EMAIL = 'email';

    /** Saved but not in use: the message goes in the portal's default wording. */
    public const DRAFT = 'draft';

    /** The message goes in this wording. */
    public const ACTIVE = 'active';

    /** The message is not sent. */
    public const INACTIVE = 'inactive';

    public const STATUSES = [self::DRAFT, self::ACTIVE, self::INACTIVE];

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'channel', 'subject', 'body', 'dlt_template_id', 'status', 'updated_by'];

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
