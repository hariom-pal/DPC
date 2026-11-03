<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * The Class Master (change item 1).
 *
 * **`code` is validated against the enum, not merely for uniqueness.** The codes
 * are statutory and the M5 engine keys its zone rules on them; a code accepted
 * here that the engine has no rule for would not fail — it would fall through to
 * the uncapped default and quietly change who is considered for promotion. See
 * `ClassMaster` and its migration for the full division of authority.
 */
class ClassMasterRequest extends FormRequest
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
        $current = $this->route('classMaster');
        $id = $current?->id;

        return [
            /*
             | A new class may be added (2026-11-03). Its code is what every post and
             | proposal stores, so it is short, upper-case, and never changed once
             | created: a renamed code would orphan every post of the class.
             */
            'code' => [
                'required', 'string', 'max:5', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('service_classes', 'code')->withoutTrashed()->ignore($id),
                ...($current !== null ? [Rule::in([$current->code])] : []),
            ],
            'name_en' => ['required', 'string', 'max:255'],
            'name_hi' => ['nullable', 'string', 'max:255'],
            // Place in the hierarchy, 1 highest; two classes never share one.
            'level' => ['required', 'integer', 'min:1', 'max:99', Rule::unique('service_classes', 'level')->withoutTrashed()->ignore($id)],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => (string) __('admin.service_classes.form.code_format'),
            'code.in' => (string) __('admin.service_classes.form.code_fixed'),
            'code.unique' => 'That class is already in the master. Edit the existing row instead.',
            'level.unique' => (string) __('admin.service_classes.form.level_taken'),
        ];
    }
}
