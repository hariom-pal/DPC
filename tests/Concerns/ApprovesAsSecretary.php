<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Enums\Role;

/**
 * The Secretary, CGPSC's approval after the AH's (2026-11-14): over HTTP, through
 * the eSign step, as a browser does it.
 */
trait ApprovesAsSecretary
{
    private ?User $secretaryUser = null;

    protected function secretary(): User
    {
        if ($this->secretaryUser === null) {
            $this->secretaryUser = User::factory()->create(['name' => 'Secretary CGPSC']);
            $this->secretaryUser->assignRole(Role::Secretary->value);
        }

        return $this->secretaryUser;
    }

    protected function approveAsSecretary(DpcProposal $proposal): void
    {
        $this->travel(2)->minutes(); // past the eSign rate limit between attempts
        $this->actingAs($this->secretary())
            ->post(route('esign.start', ['kind' => 'dpc-secretary-approve', 'uuid' => $proposal->uuid]), ['consent' => '1'])
            ->assertRedirect();
        $reference = (string) session('esign.pending')['reference'];
        $this->post(route('esign.simulator.submit'), ['reference' => $reference, 'decision' => 'sign', 'name' => $this->secretary()->name])
            ->assertRedirect(route('dpc-proposals.show', $proposal))
            ->assertSessionHasNoErrors();
    }
}
