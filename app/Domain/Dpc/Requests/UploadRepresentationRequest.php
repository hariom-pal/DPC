<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A representation, complaint or letter about the DPC, with its summary (rule 4.4).
 */
class UploadRepresentationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proposal = $this->route('dpcProposal');

        return $proposal instanceof DpcProposal && ($this->user()?->can('update', $proposal) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'summary' => ['required', 'string', 'max:2000'],
            'received_from' => ['nullable', 'string', 'max:255'],
            'received_on' => ['nullable', 'date', 'before_or_equal:today'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
        ];
    }
}
