<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * The Reason Master form (change register of 2026-08-27, item 16).
 *
 * Shaped like {@see ReviewReasonRequest}, with two fields that master does not have
 * and this one needs:
 *
 * - **`requires_remarks`** — the flag that makes the particulars mandatory on a gap
 *   citing this reason. A checkbox, so an unticked box posts nothing at all; hence
 *   {@see prepareForValidation()} rather than a `boolean` rule alone.
 * - **`sort_order`** — the order the reasons appear in on the officer's dropdown. An
 *   Administrator orders them by how often they are used, which no alphabetical sort
 *   can guess.
 */
class RecordReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An unticked checkbox is absent from the request, not false.
     *
     * Without this, saving a reason with the box cleared would leave
     * `requires_remarks` untouched — so a flag could be turned on and never off,
     * which is the more dangerous direction: the Commission would be unable to
     * withdraw a mandatory-remarks rule it had set by mistake.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['requires_remarks' => $this->boolean('requires_remarks')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $id = $this->route('recordReason')?->id;

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('record_reasons', 'code')->ignore($id)],
            'name_en' => ['required', 'string', 'max:255'],
            'name_hi' => ['required', 'string', 'max:255'],
            'requires_remarks' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }
}
