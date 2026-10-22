<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Domain\Master\Models\SealedCoverReason;
use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * A Sealed Cover reason (Coder Instructions rule 2.2). The route group is
 * `role:admin`, like every Administrator master.
 */
class SealedCoverReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['requires_document' => $this->boolean('requires_document')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('sealedCoverReason')?->id;

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('sealed_cover_reasons', 'code')->ignore($id)],
            'name_en' => ['required', 'string', 'max:255'],
            'name_hi' => ['required', 'string', 'max:255'],
            'effect' => ['required', Rule::in(SealedCoverReason::effects())],
            'requires_document' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }
}
