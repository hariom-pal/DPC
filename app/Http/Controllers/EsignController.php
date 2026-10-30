<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Contracts\SignedAction;
use App\Enums\Feature;
use App\Integrations\Mock\MockSignatureProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The eSign step (2026-10-28): before the AO submits a Pre-DPC and before the AH
 * approves a Pre-DPC or a DPC, a screen shows what is being signed and by whom,
 * takes the officer's consent, and runs the eSign — every time. The name on the
 * eSign is checked against the portal name by the workflow service; a mismatch
 * leaves the proposal where it was and puts the notice on it.
 *
 * Outside production, with the mock provider, the eSign is a simulator page on
 * which the tester may type the name the eSign would carry.
 */
class EsignController extends Controller
{
    private const SESSION_KEY = 'esign.pending';

    /**
     * kind => [model, policy ability, success message key]
     */
    private const KINDS = [
        'pre-dpc-submit' => [PreDpcProposal::class, 'submit', 'esign.done.submit'],
        'pre-dpc-approve' => [PreDpcProposal::class, 'approve', 'esign.done.approve'],
        'dpc-submit' => [DpcProposal::class, 'submit', 'esign.done.submit'],
        'dpc-approve' => [DpcProposal::class, 'approve', 'esign.done.approve'],
    ];

    public function show(Request $request, string $kind, string $uuid): View
    {
        $proposal = $this->proposal($kind, $uuid);

        return view('esign.consent', [
            'kind' => $kind,
            'proposal' => $proposal,
            'label' => $proposal instanceof PreDpcProposal ? $proposal->pre_dpc_number : $proposal->citation(),
            'signer' => $this->user($request),
            'simulated' => $this->simulated(),
        ]);
    }

    public function start(Request $request, string $kind, string $uuid): RedirectResponse
    {
        $proposal = $this->proposal($kind, $uuid);
        $request->validate(['consent' => ['accepted']], ['consent.accepted' => __('esign.consent_required')]);

        // The AO's declaration on a Pre-DPC submission (MoM of 2026-10-29).
        if ($kind === 'pre-dpc-submit') {
            $request->validate(['declaration' => ['accepted']], ['declaration.accepted' => __('predpc.declaration_required')]);
        }

        if (! $this->simulated()) {
            // The provider signs as the workflow runs (its own consent screen, if
            // any, is the provider's).
            return $this->perform($request, $kind, $proposal);
        }

        $reference = Str::random(40);
        $request->session()->put(self::SESSION_KEY, [
            'reference' => $reference, 'kind' => $kind, 'uuid' => $uuid,
            'user_id' => $this->user($request)->id, 'expires' => now()->addMinutes(10)->timestamp,
            'declaration' => $request->boolean('declaration'),
        ]);

        return redirect()->route('esign.simulator', ['reference' => $reference]);
    }

    public function simulator(Request $request): View
    {
        abort_unless($this->simulated(), 404);
        $pending = $this->pending($request, (string) $request->query('reference'), pull: false);

        return view('esign.simulator', [
            'reference' => $pending['reference'],
            'signer' => $this->user($request),
            // A Department Admin's step (allot, release) has no proposal to sign.
            'proposal' => isset($pending['action']) ? null : $this->proposal($pending['kind'], $pending['uuid']),
        ]);
    }

    public function simulate(Request $request): RedirectResponse
    {
        abort_unless($this->simulated(), 404);
        $pending = $this->pending($request, (string) $request->input('reference'), pull: true);

        if (isset($pending['action'])) {
            return $this->simulateAction($request, $pending);
        }

        $proposal = $this->proposal($pending['kind'], $pending['uuid']);

        if ($request->input('decision') !== 'sign') {
            return $this->back($proposal)->withErrors(['workflow' => __('esign.errors.esign_failed')]);
        }

        $name = trim((string) $request->validate(['name' => ['required', 'string', 'max:255']])['name']);
        app()->instance(MockSignatureProvider::SIMULATED_NAME, $name);

        // The declaration was made on the consent screen, before the simulator.
        $request->merge(['declaration' => ($pending['declaration'] ?? false) ? '1' : '']);

        try {
            return $this->perform($request, $pending['kind'], $proposal);
        } finally {
            app()->forgetInstance(MockSignatureProvider::SIMULATED_NAME);
        }
    }

    /**
     * A Department Admin's signed step (MoM of 2026-10-29), stashed by EsignGate.
     *
     * @param  array<string, mixed>  $pending
     */
    private function simulateAction(Request $request, array $pending): RedirectResponse
    {
        $action = (string) $pending['action'];
        abort_unless(is_subclass_of($action, SignedAction::class), 404);

        /** @var SignedAction $handler */
        $handler = app($action);
        /** @var array<string, mixed> $payload */
        $payload = (array) ($pending['payload'] ?? []);

        if ($request->input('decision') !== 'sign') {
            return $handler->abandon($payload)->withErrors(['workflow' => __('esign.errors.esign_failed')]);
        }

        $name = trim((string) $request->validate(['name' => ['required', 'string', 'max:255']])['name']);
        app()->instance(MockSignatureProvider::SIMULATED_NAME, $name);

        try {
            return $handler->perform($request, $this->user($request), $payload);
        } finally {
            app()->forgetInstance(MockSignatureProvider::SIMULATED_NAME);
        }
    }

    private function perform(Request $request, string $kind, PreDpcProposal|DpcProposal $proposal): RedirectResponse
    {
        $user = $this->user($request);

        try {
            match ($kind) {
                'pre-dpc-submit' => app(PreDpcProposalService::class)->submit($proposal, $user, $request),
                'pre-dpc-approve' => app(PreDpcProposalService::class)->approve($proposal, $user, $request),
                'dpc-submit' => app(DpcProposalService::class)->submit($proposal, $user, $request),
                'dpc-approve' => app(DpcProposalService::class)->approve($proposal, $user, $request),
                default => abort(404),
            };
        } catch (WorkflowException $e) {
            return $this->back($proposal)->withErrors(['workflow' => $e->getMessage()]);
        }

        return $this->back($proposal)->with('status', __(self::KINDS[$kind][2]));
    }

    /**
     * @return array{reference: string, kind?: string, uuid?: string, action?: string, payload?: array<string, mixed>, user_id: int, expires: int, declaration?: bool}
     */
    private function pending(Request $request, string $reference, bool $pull): array
    {
        $pending = $pull ? $request->session()->pull(self::SESSION_KEY) : $request->session()->get(self::SESSION_KEY);

        // Another session's, a replayed or an expired eSign is refused.
        abort_unless(
            is_array($pending) && hash_equals((string) $pending['reference'], $reference)
            && $pending['user_id'] === $this->user($request)->id && $pending['expires'] >= now()->timestamp,
            419,
        );

        return $pending;
    }

    private function proposal(string $kind, string $uuid): PreDpcProposal|DpcProposal
    {
        abort_unless(isset(self::KINDS[$kind]), 404);
        [$model, $ability] = self::KINDS[$kind];

        /** @var PreDpcProposal|DpcProposal $proposal */
        $proposal = $model::query()->where('uuid', $uuid)->firstOrFail();
        $this->authorize($ability, $proposal);

        return $proposal;
    }

    private function back(PreDpcProposal|DpcProposal $proposal): RedirectResponse
    {
        return redirect()->route($proposal instanceof PreDpcProposal ? 'pre-dpc.show' : 'dpc-proposals.show', $proposal);
    }

    private function simulated(): bool
    {
        // The mock provider is bound exactly while CSC DigiSign is off
        // (AppServiceProvider); it is never used in production.
        return ! app()->environment('production') && Feature::CscDigiSign->disabled();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
