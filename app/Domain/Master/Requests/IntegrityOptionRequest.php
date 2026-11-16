<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * An Integrity option (2026-11-14). The route group is
 * `role:admin`, like every Administrator master.
 */
class IntegrityOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['fit' => $this->boolean('fit')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('integrityOption')?->id;

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('integrity_options', 'code')->withoutTrashed()->ignore($id)],
            'name_en' => ['required', 'string', 'max:255'],
            'name_hi' => ['required', 'string', 'max:255'],
            'fit' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }
}
