<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Raising a circulation note (PPT S47) — M9 Phase 1.
 *
 * S47 collects a circulation proposal date and the origin DPC ID; the officers
 * and their resolution evidence come from S45's rule that a note follows the
 * *resolution* of a recorded gap. **A circulation with no stated resolution is
 * the one thing this screen must not accept** — the evidence is what the
 * Commission's consent rests on, and a note that records only "resolved" says
 * nothing a reader can check years later.
 */
class RaiseCirculationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->is_active
            && $user->hasRole(Role::AuthorizedOfficer->value)
            && $user->department_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'origin_dpc_proposal_uuid' => ['required', 'string'],
            'proposal_date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.hold_uuid' => ['required', 'string'],
            'items.*.resolution_detail' => ['required', 'string', 'max:2000'],
            'items.*.resolution_reference' => ['nullable', 'string', 'max:255'],
            'items.*.resolution_received_on' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $origin = DpcProposal::query()
                ->where('uuid', (string) $this->input('origin_dpc_proposal_uuid'))
                ->first();

            if ($origin === null) {
                $validator->errors()->add('origin_dpc_proposal_uuid', 'That DPC could not be found.');

                return;
            }

            // Checked here as well as in the service, so a non-HTTP caller is
            // bound by the same rule.
            if ($origin->department_id !== $this->user()?->department_id) {
                $validator->errors()->add(
                    'origin_dpc_proposal_uuid',
                    'That DPC belongs to another department.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'origin_dpc_proposal_uuid.required' => 'Select the DPC the promotion was held in.',
            'items.required' => 'Select at least one held officer.',
            'items.min' => 'Select at least one held officer.',
            'items.*.resolution_detail.required' => 'State what the department received to resolve the hold.',
            'items.*.resolution_received_on.required' => 'Give the date the resolution was received.',
        ];
    }
}
