<?php

declare(strict_types=1);

namespace App\Http\SignedActions;

use App\Domain\Allotment\Exceptions\AllotmentRefused;
use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Contracts\SignedAction;
use App\Domain\UserManagement\Services\DepartmentUserAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Allot or reassign a proposal's AO and AH, eSigned by the Department Admin, with
 * the assignment order and the declaration (MoM of 2026-10-29).
 *
 * Payload: kind (pre_dpc | dpc), proposal (id), ao, ah (user ids), reason,
 * order (disk, path, name, sha256), label.
 */
class AllotOfficers implements SignedAction
{
    public function __construct(
        private readonly DepartmentUserAuthority $authority,
        private readonly ProposalAllotmentService $allotments,
        private readonly PreDpcProposalService $preDpcs,
    ) {}

    public function perform(Request $request, User $signer, array $payload): RedirectResponse
    {
        $proposal = $this->proposal($payload);

        // Asked again: the eSign may come minutes after the form.
        abort_unless($this->authority->isDepartmentAdmin($signer) && $proposal->department_id === $signer->department_id, 403);

        $ao = User::query()->findOrFail((int) $payload['ao']);
        $ah = User::query()->findOrFail((int) $payload['ah']);

        try {
            if ($proposal instanceof PreDpcProposal) {
                $this->preDpcs->assertComplete($proposal);
            }

            /** @var array{disk: string, path: string, name: string, sha256: string} $order */
            $order = $payload['order'];
            $this->allotments->allot($proposal, $ao, $ah, $signer, isset($payload['reason']) ? (string) $payload['reason'] : null, $order);
        } catch (AllotmentRefused|WorkflowException $e) {
            $this->discard($payload);

            return redirect()->to($this->editUrl($proposal))->withErrors(['ao_user_id' => $e->getMessage()]);
        }

        return redirect()->route('department.allotments.index', $proposal instanceof PreDpcProposal ? ['view' => 'pre-dpc'] : [])
            ->with('status', __('allotments.saved', ['proposal' => (string) ($payload['label'] ?? ''), 'ao' => $ao->name, 'ah' => $ah->name]));
    }

    public function abandon(array $payload): RedirectResponse
    {
        $this->discard($payload);

        return redirect()->to($this->editUrl($this->proposal($payload)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function proposal(array $payload): PreDpcProposal|DpcProposal
    {
        return ($payload['kind'] ?? null) === 'pre_dpc'
            ? PreDpcProposal::query()->findOrFail((int) $payload['proposal'])
            : DpcProposal::query()->findOrFail((int) $payload['proposal']);
    }

    private function editUrl(PreDpcProposal|DpcProposal $proposal): string
    {
        return $proposal instanceof PreDpcProposal
            ? route('department.allotments.pre-dpc.edit', $proposal)
            : route('department.allotments.dpc.edit', $proposal);
    }

    /**
     * The uploaded order of an allotment that was not made.
     *
     * @param  array<string, mixed>  $payload
     */
    private function discard(array $payload): void
    {
        $order = $payload['order'] ?? null;

        if (is_array($order) && isset($order['disk'], $order['path'])) {
            Storage::disk((string) $order['disk'])->delete((string) $order['path']);
        }
    }
}
