<?php

declare(strict_types=1);

namespace App\Domain\Allotment\Requests;

use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreDpc\Models\PreDpcProposal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Department Admin opens a Pre-DPC: the exercise (DPC year and promotion-to
 * post) and the AO and AH it is allotted to (A1, 2026-10-26). Everything else is
 * the allotted AO's to fill.
 */
class OpenPreDpcRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller asks DepartmentUserAuthority.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $departmentId = $this->user()?->department_id;

        return [
            'dpc_year' => ['required', 'integer', 'min:'.(int) config('dpc.earliest_dpc_year', 2019), 'max:'.((int) now()->addYear()->format('Y'))],
            'promotion_to_post_id' => ['required', 'integer', Rule::exists('department_posts', 'id')->where('department_id', $departmentId)],
            // The AO and AH are assigned last, once the Pre-DPC is complete (2026-10-28).
        ];
    }

    /**
     * The year rules and their messages are the Pre-DPC form's, moved here with
     * the choice of year (A1).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dpc_year.min' => (string) __('predpc.validation.year_before_earliest', ['year' => (int) config('dpc.earliest_dpc_year', 2019)]),
            'dpc_year.required' => 'Every Pre-DPC belongs to a DPC Year. Enter it.',
            'dpc_year.max' => 'A Pre-DPC can be prepared for the current or the coming year, not beyond.',
            'ah_user_id.different' => (string) __('allotments.errors.same_officer'),
            'ao_user_id.required' => (string) __('allotments.picker.choose_ao'),
            'ah_user_id.required' => (string) __('allotments.picker.choose_ah'),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $post = DepartmentPost::query()->inForce()->whereKey((int) $this->input('promotion_to_post_id'))->first();

            if ($post === null) {
                $validator->errors()->add('promotion_to_post_id', (string) __('allotments.errors.post_not_in_force'));

                return;
            }

            $existing = PreDpcProposal::query()
                ->activeApproved()
                ->where('department_id', $this->user()?->department_id)
                ->where('promotion_to_post_id', $post->id)
                ->where('dpc_year', (int) $this->input('dpc_year'))
                ->first();

            if ($existing !== null) {
                $validator->errors()->add('promotion_to_post_id', (string) __('allotments.errors.exercise_in_force', ['number' => $existing->pre_dpc_number]));
            }
        }];
    }
}
