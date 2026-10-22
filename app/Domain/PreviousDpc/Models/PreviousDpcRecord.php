<?php

declare(strict_types=1);

namespace App\Domain\PreviousDpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A DPC held offline before the portal, entered by the Commission's staff (Coder
 * Instructions rule 1.1): its details, minutes and promotion order, the officers it
 * promoted, and — optional below 50 eligible employees — the final seniority list and
 * the working officers of the promotion post.
 *
 * @property int $id
 * @property string $uuid
 * @property int $department_id
 * @property int|null $promotion_from_post_id
 * @property int $promotion_to_post_id
 * @property int $dpc_year
 * @property Carbon $held_on
 * @property string $reference
 * @property string|null $order_number
 * @property Carbon|null $order_date
 * @property int $eligible_count
 * @property list<array{employee_number: string|null, name: string}> $promoted_officers
 * @property string|null $remarks
 * @property string|null $minutes_disk
 * @property string|null $minutes_path
 * @property string|null $minutes_original_name
 * @property string|null $minutes_sha256
 * @property string|null $seniority_list_disk
 * @property string|null $seniority_list_path
 * @property string|null $seniority_list_original_name
 * @property string|null $working_officers_disk
 * @property string|null $working_officers_path
 * @property string|null $working_officers_original_name
 * @property int|null $recorded_by
 */
class PreviousDpcRecord extends Model
{
    use HasUuids;

    /** Below this many eligible employees, the seniority list and working officers are optional (rule 1.1). */
    public const UPLOADS_REQUIRED_FROM = 50;

    public const FILES = ['minutes', 'seniority_list', 'working_officers'];

    /**
     * @var list<string>
     */
    protected $guarded = ['id', 'uuid'];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** Addressed by UUID in a URL, never by its sequential id (AD-8). */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dpc_year' => 'integer',
            'held_on' => 'date',
            'order_date' => 'date',
            'eligible_count' => 'integer',
            'promoted_officers' => 'array',
        ];
    }

    public function hasFile(string $file): bool
    {
        return in_array($file, self::FILES, true) && $this->getAttribute($file.'_path') !== null;
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function promotionFromPost(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class, 'promotion_from_post_id');
    }

    /**
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function promotionToPost(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class, 'promotion_to_post_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
