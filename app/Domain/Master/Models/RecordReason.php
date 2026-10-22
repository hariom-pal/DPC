<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Why a mandatory record is missing (change register of 2026-08-27, item 16).
 *
 * > *Create a configurable Reason Master. Suggested values — Record Not Available,
 * > Officer on Deputation, Department Delay, Court Case, Record Lost, Newly
 * > Appointed, Other. If "Other" is selected, Remarks become mandatory.*
 *
 * **A master rather than free text, because of the question it lets the Commission
 * ask.** "How many promotions were held last year because a department had not
 * written the CRs" is answerable from a coded reason and unanswerable from a text
 * box. That is the whole purpose of this table; the remarks are for the particulars.
 *
 * **`requires_remarks` is a column, not a comparison against the code `other`.** Two
 * things follow from that and both are the point: an Administrator can mark *Court
 * Case* as needing the case number without a deployment, and a Commission that
 * renames or replaces "Other" does not silently switch the rule off.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $name_en
 * @property string $name_hi
 * @property bool $requires_remarks
 * @property int $sort_order
 * @property MasterStatus $status
 */
class RecordReason extends Model implements Auditable
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
        'code',
        'name_en',
        'name_hi',
        'requires_remarks',
        'sort_order',
        // Rule 3.2: the document this reason is justified with, and a second where it needs two.
        'document_label_en',
        'document_label_hi',
        'second_document_label_en',
        'second_document_label_hi',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_remarks' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The reasons an officer may choose now.
     *
     * Narrower than "not deleted": a withdrawn reason stays attached to the gaps
     * that already cite it — withdrawing it does not rewrite why a record was
     * missing two years ago — but it must not be selectable on a new one.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query
            ->where('status', MasterStatus::Active->value)
            ->orderBy('sort_order')
            ->orderBy('name_en');
    }

    /**
     * The label in the reader's own language.
     *
     * Falls back to English when the Hindi is blank, for the reason the notice board
     * does the same: a reason issued in English first should still be usable rather
     * than showing an empty option.
     */
    /** Rule 3.2: whether a gap with this reason needs a second document (e.g. the reinstatement order). */
    public function requiresSecondDocument(): bool
    {
        return $this->second_document_label_en !== null && $this->second_document_label_en !== '';
    }

    public function documentLabel(): ?string
    {
        return app()->getLocale() === 'hi' && ($this->document_label_hi ?? '') !== '' ? $this->document_label_hi : $this->document_label_en;
    }

    public function secondDocumentLabel(): ?string
    {
        return app()->getLocale() === 'hi' && ($this->second_document_label_hi ?? '') !== '' ? $this->second_document_label_hi : $this->second_document_label_en;
    }

    public function label(): string
    {
        if (app()->getLocale() === 'hi' && $this->name_hi !== '') {
            return $this->name_hi;
        }

        return $this->name_en;
    }
}
