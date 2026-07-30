<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CadreRequest extends FormRequest
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
        $id = $this->route('cadre')?->id;

        return [
            'department_id' => ['required', Rule::exists('departments', 'id')],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('cadres', 'name')->where('department_id', (int) $this->input('department_id'))->ignore($id),
            ],
            'class_level' => ['required', new Enum(ServiceClass::class)],
            'pay_scale_id' => ['nullable', Rule::exists('pay_scales', 'id')],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }
}
