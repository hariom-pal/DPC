<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class DistrictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $id = $this->route('district')?->id;

        return [
            'name_en' => ['required', 'string', 'max:255', Rule::unique('districts', 'name_en')->ignore($id)],
            'name_hi' => ['required', 'string', 'max:255'],
            'state_lgd_code' => ['required', 'string', 'max:20'],
            'lgd_code' => ['nullable', 'string', 'max:20', Rule::unique('districts', 'lgd_code')->ignore($id)],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }
}
