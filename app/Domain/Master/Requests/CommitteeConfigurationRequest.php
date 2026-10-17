<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Domain\Committee\Enums\MemberCategory;
use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * The DPC Committee Rules, as the Administrator states them.
 *
 * The size is entered as members **besides the CGPSC Chairman**, the way the rule is
 * written; {@see toAttributes()} stores it as the whole-committee count the column
 * has always held.
 */
class CommitteeConfigurationRequest extends FormRequest
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
        return [
            'members_min' => ['required', 'integer', 'min:1', 'max:50'],
            'members_max' => ['required', 'integer', 'gte:members_min', 'max:50'],
            'required_categories' => ['nullable', 'array'],
            'required_categories.*' => ['string', 'distinct', Rule::enum(MemberCategory::class)],
            'requires_reserved_category' => ['required', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }

    /**
     * SC, ST and OBC are each one member's single social category, so a committee
     * required to cover all three needs at least that many members.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $social = array_intersect(
                (array) $this->input('required_categories', []),
                array_map(fn (MemberCategory $category): string => $category->value, MemberCategory::socialCategories()),
            );

            if (! $validator->errors()->has('members_max') && count($social) > (int) $this->input('members_max')) {
                $validator->errors()->add('members_max', __('admin.committee_rules.errors.too_few_for_categories', ['count' => count($social)]));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'members_min' => __('admin.committee_rules.members_min'),
            'members_max' => __('admin.committee_rules.members_max'),
            'required_categories' => __('admin.committee_rules.required_categories'),
            'requires_reserved_category' => __('admin.committee_rules.requires_reserved_category'),
            'effective_from' => __('admin.committee_rules.effective_from'),
            'effective_to' => __('admin.committee_rules.effective_to'),
        ];
    }

    /**
     * The validated rules as stored: the Chairman's seat added to both bounds.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $data = $this->validated();

        return [
            'min_members' => (int) $data['members_min'] + 1,
            'max_members' => (int) $data['members_max'] + 1,
            'required_categories' => array_values($data['required_categories'] ?? []),
            'requires_reserved_category' => (bool) $data['requires_reserved_category'],
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'status' => $data['status'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['requires_reserved_category' => $this->boolean('requires_reserved_category')]);
    }
}
