<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ReservationRosterRequest extends FormRequest
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
        $id = $this->route('reservationRoster')?->id;

        return [
            'department_id' => ['required', Rule::exists('departments', 'id')],
            'cadre_id' => ['required', Rule::exists('cadres', 'id')],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('reservation_rosters', 'name')
                    ->where('department_id', (int) $this->input('department_id'))
                    ->where('cadre_id', (int) $this->input('cadre_id'))
                    ->ignore($id),
            ],
            'size' => ['required', 'integer', 'min:1', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }
}
