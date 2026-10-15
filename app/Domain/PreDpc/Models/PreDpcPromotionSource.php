<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Models;

use App\Domain\Master\Enums\ExperienceUnit;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Qualification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One post officers may be promoted **from**, under a Pre-DPC.
 *
 * **A promotion usually has more than one of these**, which is the whole reason
 * this table exists: Schedule-IV routinely fills a post from several feeders with a
 * share each — Deputy Director from Assistant Director, Senior Lecturer and
 * Research Officer. Collapsing that to a single pair during the change register of
 * 2026-08-24 lost it, and this restores it.
 *
 * **Each source carries its own terms, and that is not duplication.** The
 * qualifying service and the minimum qualification are set *per feeder* by the
 * recruitment rule — five years as an Assistant Director is a different condition
 * from five years as a Research Officer — so they belong on the source and not on
 * the order.
 *
 * `promotion_duration_years` is copied from the feeder post's own mapping when the
 * order is saved rather than read live, for the same reason every other figure on a
 * signed order is copied: the order states what it sanctioned, and a master edited
 * two years later must not change what a tribunal reads.
 *
 * @property int $id
 * @property int $pre_dpc_proposal_id
 * @property int $promotion_from_post_id
 * @property numeric-string $promotion_percentage
 * @property int $min_experience_years
 * @property ExperienceUnit $experience_unit
 * @property int|null $promotion_duration_years
 * @property int $sort_order
 * @property-read Collection<int, Qualification> $qualifications
 */
class PreDpcPromotionSource extends Model implements Auditable
{
    use AuditableTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pre_dpc_proposal_id',
        'promotion_from_post_id',
        'promotion_percentage',
        'min_experience_years',
        'experience_unit',
        'promotion_duration_years',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'experience_unit' => ExperienceUnit::class,
            'min_experience_years' => 'integer',
            'promotion_duration_years' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PreDpcProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(PreDpcProposal::class, 'pre_dpc_proposal_id');
    }

    /**
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function promotionFromPost(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class, 'promotion_from_post_id');
    }

    /**
     * Every qualification this source accepts (change register of 2026-08-26,
     * item 11).
     *
     * Plural for the same reason the recruitment rule's are: a Schedule-IV entry
     * accepts *any* of a list of degrees, and one column could hold one of them.
     *
     * @return BelongsToMany<Qualification, $this>
     */
    public function qualifications(): BelongsToMany
    {
        return $this->belongsToMany(
            Qualification::class,
            'pre_dpc_source_qualifications',
            'pre_dpc_promotion_source_id',
            'qualification_id',
        )->withPivot('sort_order')->orderBy('pre_dpc_source_qualifications.sort_order');
    }

    /**
     * The qualifications this source prescribes, as a sentence, or null when none
     * is prescribed.
     *
     * Joined with "/" because they are alternatives — "BE or BTech or MCA" — and
     * joining them with "and" would state the opposite of the rule.
     */
    public function qualificationLabel(): ?string
    {
        $names = $this->qualifications->pluck('name_en')->all();

        return $names === [] ? null : implode(' / ', $names);
    }

    /**
     * The qualifying service, as a sentence, or null when none is prescribed.
     */
    public function experienceLabel(): ?string
    {
        return $this->min_experience_years === 0
            ? null
            : $this->min_experience_years.' '.$this->experience_unit->labelFor($this->min_experience_years);
    }
}
