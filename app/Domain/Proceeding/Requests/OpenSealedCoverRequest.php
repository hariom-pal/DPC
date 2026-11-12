<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Requests;

use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcSealedCoverOpening;
use App\Domain\Proceeding\Services\SealedCoverService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The DPC Chairman opens a sealed cover (2026-11-05): the competent authority's
 * decision, its order, and the evidence. Only the DPC's own Chairman, refused
 * before the form is validated; the service checks again.
 */
class OpenSealedCoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proceeding = $this->route('proceeding');

        return $proceeding instanceof DpcProceeding && $proceeding->proposal !== null
            && app(SealedCoverService::class)->isDpcChairman($proceeding->proposal, $this->user());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'sn' => ['required', 'integer', 'min:1'],
            'outcome' => ['required', Rule::in([DpcSealedCoverOpening::CLEARED, DpcSealedCoverOpening::REJECTED])],
            'opened_on' => ['required', 'date', 'before_or_equal:today'],
            'authority_name' => ['required', 'string', 'max:255'],
            'authority_order_number' => ['required', 'string', 'max:120'],
            'authority_order_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
        ];
    }
}
