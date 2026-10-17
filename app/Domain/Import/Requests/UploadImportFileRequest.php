<?php

declare(strict_types=1);

namespace App\Domain\Import\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Import\Enums\ImportTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Upload step of the Excel import pipeline (PPT S16–S18) — M4 Phase 6.
 *
 * Accepted formats and the size cap are config-driven so they can be tuned
 * per environment. The extension allow-list is enforced by `mimes`, which
 * checks the file's detected type rather than trusting the client-supplied
 * name.
 */
class UploadImportFileRequest extends FormRequest
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
        /** @var list<string> $mimes */
        $mimes = config('dpc.imports.mimes');

        return [
            'template' => ['required', new Enum(ImportTemplate::class)],
            'file' => [
                'required',
                'file',
                Rule::file()
                    ->extensions($mimes)
                    ->max((int) config('dpc.imports.max_file_kb')),
            ],

            // Rule 1.6: the date the uploaded Seniority List was drawn up as on. Its
            // value is checked against the proposal's DPC year by the import service.
            'seniority_list_as_on' => [
                Rule::requiredIf(fn (): bool => $this->input('template') === ImportTemplate::FinalSeniorityListUs->value),
                'nullable',
                'date',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a spreadsheet to upload.',
            'seniority_list_as_on.required' => 'State the date the Seniority List was drawn up as on.',
        ];
    }
}
