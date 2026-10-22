<?php

declare(strict_types=1);

namespace App\Domain\PromotionOrder\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rule 4.3: the Administrative Head of the proposal's department issues the order,
 * giving its number and date.
 */
class IssuePromotionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proposal = $this->route('dpcProposal');
        $user = $this->user();

        return $proposal instanceof DpcProposal && $user !== null
            && $user->hasRole(Role::AdministrativeHead->value)
            && (int) $user->department_id === (int) $proposal->department_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_number' => ['required', 'string', 'max:120'],
            'order_date' => ['required', 'date', 'before_or_equal:today'],
            'confirm' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'order_number' => (string) __('dpc.promotion_orders.order_number'),
            'order_date' => (string) __('dpc.promotion_orders.order_date'),
        ];
    }
}
