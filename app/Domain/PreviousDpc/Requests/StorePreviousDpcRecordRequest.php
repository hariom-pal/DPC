<?php

declare(strict_types=1);

namespace App\Domain\PreviousDpc\Requests;

use App\Domain\PreviousDpc\Models\PreviousDpcRecord;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rule 1.1: a DPC held offline, entered by the Commission's staff.
 */
class StorePreviousDpcRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(Role::CgpscStaff->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $department = (int) $this->input('department_id');
        $post = fn () => Rule::exists('department_posts', 'id')->where('department_id', $department);
        $file = ['file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)];
        $uploadsRequired = fn (): bool => (int) $this->input('eligible_count') >= PreviousDpcRecord::UPLOADS_REQUIRED_FROM;

        return [
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'promotion_from_post_id' => ['nullable', 'integer', $post()],
            'promotion_to_post_id' => ['required', 'integer', $post()],
            'dpc_year' => ['required', 'integer', 'min:1950', 'max:'.((int) now()->format('Y'))],
            'held_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['required', 'string', 'max:120'],
            'order_number' => ['nullable', 'string', 'max:120'],
            'order_date' => ['nullable', 'date', 'before_or_equal:today'],
            'eligible_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'promoted_officers' => ['required', 'string', 'max:20000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'minutes' => ['required', ...$file],
            // Optional below 50 eligible employees (rule 1.1).
            'seniority_list' => [Rule::requiredIf($uploadsRequired), 'nullable', ...$file],
            'working_officers' => [Rule::requiredIf($uploadsRequired), 'nullable', ...$file],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_map(static fn (string $key): string => (string) __('previous_dpc.fields.'.$key), array_combine(
            ['department_id', 'promotion_from_post_id', 'promotion_to_post_id', 'dpc_year', 'held_on', 'reference', 'order_number', 'order_date', 'eligible_count', 'promoted_officers', 'minutes', 'seniority_list', 'working_officers'],
            ['department_id', 'promotion_from_post_id', 'promotion_to_post_id', 'dpc_year', 'held_on', 'reference', 'order_number', 'order_date', 'eligible_count', 'promoted_officers', 'minutes', 'seniority_list', 'working_officers'],
        ));
    }
}
