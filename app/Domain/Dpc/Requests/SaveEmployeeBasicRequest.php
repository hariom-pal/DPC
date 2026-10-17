<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Section A of an officer's details: the fields the simplified Seniority List does
 * not carry. Authorization is the controller's (`update` on the proposal).
 */
class SaveEmployeeBasicRequest extends FormRequest
{
    /**
     * Authorised before validation, so a user who may not edit the proposal is
     * refused outright instead of being shown validation messages about it.
     */
    public function authorize(): bool
    {
        $proposal = $this->route('dpcProposal');

        return $proposal instanceof DpcProposal && ($this->user()?->can('update', $proposal) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reservation_category_id' => ['nullable', 'integer', Rule::exists('reservation_categories', 'id')],
            'qualification' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'date_of_posting_on_promotion_from_post' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
