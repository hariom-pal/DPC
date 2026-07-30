<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ReservationCategoryRequest extends FormRequest
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
        $id = $this->route('reservationCategory')?->id;

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('reservation_categories', 'code')->ignore($id)],
            'name_en' => ['required', 'string', 'max:255'],
            'name_hi' => ['required', 'string', 'max:255'],
            'is_reserved' => ['required', 'boolean'],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_reserved' => $this->boolean('is_reserved')]);
    }
}
