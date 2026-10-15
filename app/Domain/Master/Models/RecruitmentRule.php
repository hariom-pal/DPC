<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $post_id
 * @property int $version
 * @property string $government_order_number
 * @property numeric-string $direct_recruitment_pct
 * @property numeric-string $promotion_recruitment_pct
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string|null $go_document_disk
 * @property string|null $go_document_path
 * @property MasterStatus $status
 */
class RecruitmentRule extends Model implements Auditable
{
    use IsMaster;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'post_id',
        'version',
        'government_order_number',
        'direct_recruitment_pct',
        'promotion_recruitment_pct',
        'effective_from',
        'effective_to',
        'go_document_disk',
        'go_document_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'direct_recruitment_pct' => 'decimal:2',
            'promotion_recruitment_pct' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status === MasterStatus::Draft;
    }

    /**
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class);
    }

    /**
     * @return HasMany<RecruitmentRuleSource, $this>
     */
    public function promotionSources(): HasMany
    {
        return $this->hasMany(RecruitmentRuleSource::class)->orderBy('sort_order');
    }
}
