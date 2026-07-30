<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class PayScaleRequest extends FormRequest
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
        $id = $this->route('payScale')?->id;

        return [
            'label' => ['required', 'string', 'max:255', Rule::unique('pay_scales', 'label')->ignore($id)],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('pay_scales', 'code')->ignore($id)],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }
}
