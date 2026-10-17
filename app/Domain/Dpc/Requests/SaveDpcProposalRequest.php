<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DpcType;
use App\Domain\PreDpc\Models\PreDpcProposal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raising a DPC (PPT S14), rewritten for change items 11 and 12 on 2026-08-24.
 *
 * **Almost everything that used to be validated here is gone**, because it is no
 * longer sent. The register: *"There will be NO standalone New DPC button. A DPC
 * can ONLY be created from an Active Approved Pre-DPC… The AO should not enter
 * these details again."* So the Promotion From Post, the Promotion To Post, the
 * Class, the Pay Scale, the Promotion Duration, the DPC Year and the Recruitment
 * Rule are all read from the Pre-DPC, and the Proposal Date is generated (item
 * 12).
 *
 * What remains is the pair of facts the sanctioning order does not carry: which
 * *type* of DPC this is, and whether a legal stay is in force.
 */
class SaveDpcProposalRequest extends FormRequest
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
            /*
             | Required on create, absent on update.
             |
             | A DPC's Pre-DPC is not editable: the proposal inherited its terms
             | from that order, and re-pointing it at a different one would leave a
             | proposal whose stored Class and Pay Scale came from somewhere it no
             | longer claims to come from. Correcting the wrong choice means
             | cancelling the draft and raising another.
             */
            'pre_dpc_proposal_id' => $this->route('dpcProposal') instanceof DpcProposal
                ? ['prohibited']
                : ['required', 'integer'],

            /*
             | Which of the order's Promotion From Posts this DPC is for.
             |
             | **Optional, and only meaningful on create.** An order may sanction
             | promotion from several posts; a DPC runs one of them, because the
             | seniority list, the qualifying service and the scoring engine are all
             | per feeder. When the order names exactly one the choice is implied and
             | may be left out — which is why this is `nullable` rather than
             | `required` — and `DpcProposalService::sourceFor()` refuses to guess
             | when it names more.
             */
            'promotion_from_post_id' => $this->route('dpcProposal') instanceof DpcProposal
                ? ['prohibited']
                : ['nullable', 'integer'],

            'dpc_type_id' => ['required', Rule::exists('dpc_types', 'id')],
            'legal_stay' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertTypeIsSupported($validator);
            $preDpc = $this->assertPreDpcIsRaisable($validator);
            $this->assertFeederIsSanctioned($validator, $preDpc);
        });
    }

    /**
     * `dpc_type_id` accepts any active DPC type shown on the S14 mockup
     * (Fresh/Review/Circulation — never hide PPT content), but Review DPC and
     * Circulation DPC have their own screens, so choosing them here is rejected
     * with a message that says where to go rather than a generic error.
     */
    private function assertTypeIsSupported(Validator $validator): void
    {
        $typeId = $this->input('dpc_type_id');

        if ($typeId === null) {
            return;
        }

        $type = DpcType::query()->find($typeId);

        // Case-insensitive: a master code typed as `FRESH` names the same type.
        if ($type !== null && strtolower((string) $type->code) !== 'fresh') {
            $validator->errors()->add(
                'dpc_type_id',
                'A Review DPC is raised from the Review DPC screen and a Circulation DPC from the Circulation '
                .'screen, because each starts from a concluded DPC rather than from a Pre-DPC. Only a Fresh DPC '
                .'is raised here.',
            );
        }
    }

    /**
     * The chosen Pre-DPC must be this department's, approved, and active for its
     * exercise (items 9 and 11).
     *
     * **All three conditions, and each closes a different hole.** Another
     * department's order would let an officer raise a promotion in an
     * establishment they have no part in. An unapproved one sanctions nothing yet.
     * A superseded one sanctioned terms that a later Government Order has replaced
     * — raising a DPC under it would conduct a promotion on withdrawn terms, which
     * is the precise failure item 9 exists to prevent.
     */
    private function assertPreDpcIsRaisable(Validator $validator): ?PreDpcProposal
    {
        $id = $this->input('pre_dpc_proposal_id');

        if ($id === null || $validator->errors()->has('pre_dpc_proposal_id')) {
            return null;
        }

        $preDpc = PreDpcProposal::query()
            ->activeApproved()
            ->where('department_id', $this->user()?->department_id)
            ->find($id);

        if ($preDpc === null) {
            $validator->errors()->add(
                'pre_dpc_proposal_id',
                'Choose an approved Pre-DPC that is still in force for your department. A superseded order cannot '
                .'be used: the terms it sanctioned have been replaced, and a DPC raised under it would run on '
                .'withdrawn terms.',
            );
        }

        return $preDpc;
    }

    /**
     * The feeder must be one the chosen order actually sanctions.
     *
     * `DpcProposalService::sourceFor()` refuses the same thing, and deliberately:
     * it is the method an importer would call, and a service that trusts its caller
     * is a service that can be driven past its own rule. This adds nothing to the
     * guarantee — it only turns a 500 into a message under the right field, and only
     * when the order was itself valid.
     *
     * Silent when no feeder was posted: an order naming exactly one implies it.
     */
    private function assertFeederIsSanctioned(Validator $validator, ?PreDpcProposal $preDpc): void
    {
        $chosen = $this->input('promotion_from_post_id');

        if ($preDpc === null || $chosen === null || $validator->errors()->has('promotion_from_post_id')) {
            return;
        }

        if (! $preDpc->promotionSources->contains('promotion_from_post_id', (int) $chosen)) {
            $validator->errors()->add(
                'promotion_from_post_id',
                'That Pre-DPC does not sanction promotion from the post chosen. A DPC may only run a Promotion '
                .'From Post the order names among its sources.',
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pre_dpc_proposal_id.required' => 'A DPC is raised from an approved Pre-DPC. Choose which one.',
            'pre_dpc_proposal_id.prohibited' => 'A DPC cannot be moved to a different Pre-DPC. It inherited its '
                .'terms from the order it was raised under; cancel this draft and raise another if the wrong order '
                .'was chosen.',
        ];
    }
}
