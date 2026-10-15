<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
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
        $id = $this->route('classMaster')?->id;

        return [
            'code' => [
                'required',
                new Enum(ServiceClass::class),
                Rule::unique('service_classes', 'code')->ignore($id),
            ],
            'name_en' => ['required', 'string', 'max:255'],
            'name_hi' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.Illuminate\Validation\Rules\Enum' => 'The service classes are fixed by the service rules. '
                .'Only '.implode(' / ', array_column(ServiceClass::cases(), 'value')).' exist — '
                .'rename a class here, but do not invent one.',
            'code.unique' => 'That class is already in the master. Edit the existing row instead.',
        ];
    }
}
