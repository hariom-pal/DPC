<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnPreDpcProposalRequest extends FormRequest
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
            'return_remarks' => ['required', 'string', 'max:1000'],
        ];
    }
}
