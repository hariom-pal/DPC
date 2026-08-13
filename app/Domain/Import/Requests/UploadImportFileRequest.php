<?php

declare(strict_types=1);

namespace App\Domain\Import\Requests;

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
    public function authorize(): bool
    {
        return true;
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a spreadsheet to upload.',
        ];
    }
}
