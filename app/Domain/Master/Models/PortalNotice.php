<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A notice on the public landing page (suggestion 6).
 *
 * @property int $id
 * @property string $uuid
 * @property string $title_en
 * @property string|null $title_hi
 * @property string $body_en
 * @property string|null $body_hi
 * @property Carbon $published_from
 * @property Carbon|null $published_until
 * @property int $sort_order
 * @property MasterStatus $status
 */
class PortalNotice extends Model implements Auditable
{
    use HasUuids;
    use IsMaster;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title_en',
        'title_hi',
        'body_en',
        'body_hi',
        'published_from',
        'published_until',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_from' => 'date',
            'published_until' => 'date',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The notices a visitor should see now.
     *
     * `published_until` being null means "until further notice", which is how most
     * government notices are issued — so it is treated as open-ended rather than as
     * missing data.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLive(Builder $query): Builder
    {
        $today = Carbon::today();

        return $query
            ->where('status', MasterStatus::Active->value)
            ->whereDate('published_from', '<=', $today)
            ->where(fn (Builder $q) => $q
                ->whereNull('published_until')
                ->orWhereDate('published_until', '>=', $today))
            ->orderBy('sort_order')
            ->orderByDesc('published_from');
    }

    /**
     * The title in the reader's language, falling back to English.
     *
     * A notice may be published in English before its translation exists, and a
     * blank heading is worse than an untranslated one.
     */
    public function title(?string $locale = null): string
    {
        return $this->localised($this->title_hi, $this->title_en, $locale);
    }

    public function body(?string $locale = null): string
    {
        return $this->localised($this->body_hi, $this->body_en, $locale);
    }

    private function localised(?string $hindi, string $english, ?string $locale): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'hi' && $hindi !== null && $hindi !== '' ? $hindi : $english;
    }
}
