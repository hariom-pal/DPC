<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Domain\Master\Models\ReservationRoster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReservationRosterPointRequest extends FormRequest
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
        $roster = $this->route('reservationRoster');
        $size = $roster instanceof ReservationRoster ? $roster->size : 1000;
        $rosterId = $roster instanceof ReservationRoster ? $roster->id : 0;

        return [
            'point_no' => [
                'required', 'integer', 'min:1', 'max:'.$size,
                Rule::unique('reservation_roster_points', 'point_no')->where('reservation_roster_id', $rosterId),
            ],
            'reservation_category_id' => ['required', Rule::exists('reservation_categories', 'id')],
        ];
    }
}
