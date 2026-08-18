<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Models;

use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\Qualification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A feeder cadre named on a Pre-DPC post.
 *
 * **Two pairs of columns, each a reference beside the text it replaced.**
 * `feeder_cadre_id` / `feeder_name` and `qualification_id` / `min_qualification`
 * are both master reference plus legacy record: the screen writes the reference,
 * the text stays as what the department originally wrote. Read them through
 * {@see feederLabel()} and {@see qualificationLabel()}, never the raw text — see
 * those methods for why.
 *
 * @property int $id
 * @property int $pre_dpc_post_id
 * @property string $feeder_name
 * @property int|null $feeder_cadre_id
 * @property numeric-string $promotion_percentage
 * @property int $min_experience_years
 * @property int|null $qualification_id
 * @property string|null $min_qualification
 * @property int $sort_order
 */
class PreDpcPostFeeder extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'pre_dpc_post_id',
        'feeder_name',
        'feeder_cadre_id',
        'promotion_percentage',
        'min_experience_years',
        'qualification_id',
        'min_qualification',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'promotion_percentage' => 'decimal:2',
            'min_experience_years' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Cadre, $this>
     */
    public function feederCadre(): BelongsTo
    {
        return $this->belongsTo(Cadre::class, 'feeder_cadre_id');
    }

    /**
     * @return BelongsTo<Qualification, $this>
     */
    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }

    /**
     * What to display as the feeder cadre.
     *
     * Prefers the Cadre master and falls back to the legacy free text, so a
     * proposal written before the screen selected from the master still shows
     * the post the department named.
     */
    public function feederLabel(): string
    {
        if ($this->feeder_cadre_id !== null) {
            $cadre = $this->relationLoaded('feederCadre')
                ? $this->feederCadre
                : Cadre::query()->find($this->feeder_cadre_id);

            if ($cadre !== null) {
                return $cadre->name;
            }
        }

        return $this->feeder_name;
    }

    /**
     * What to display as the minimum qualification.
     *
     * Prefers the master row and falls back to the legacy free text, so a
     * proposal whose historic text matched no master entry still shows what was
     * required instead of showing nothing. `withTrashed()` because a
     * soft-deleted qualification must still render on the proposals that cite
     * it — the record of what was required does not disappear because the
     * qualification was later withdrawn from the master.
     */
    public function qualificationLabel(): ?string
    {
        if ($this->qualification_id !== null) {
            $qualification = $this->relationLoaded('qualification')
                ? $this->qualification
                : Qualification::withTrashed()->find($this->qualification_id);

            if ($qualification !== null) {
                return $qualification->name_en;
            }
        }

        return $this->min_qualification;
    }
}
