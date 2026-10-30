<?php

declare(strict_types=1);

namespace App\Domain\Signature\Contracts;

use App\Domain\Authentication\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A step a Department Admin eSigns (MoM of 2026-10-29): allotting or reassigning
 * an AO / AH, releasing an officer. Its form is validated first; the step runs
 * once the eSign is made, signing inside its own transaction.
 */
interface SignedAction
{
    /**
     * @param  array<string, mixed>  $payload  what the validated form said
     */
    public function perform(Request $request, User $signer, array $payload): RedirectResponse;

    /**
     * The eSign was declined: nothing is done, and anything the form stored is
     * removed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function abandon(array $payload): RedirectResponse;
}
