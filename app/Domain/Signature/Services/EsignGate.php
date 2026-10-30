<?php

declare(strict_types=1);

namespace App\Domain\Signature\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Signature\Contracts\SignedAction;
use App\Enums\Feature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Runs a {@see SignedAction} behind the eSign (MoM of 2026-10-29).
 *
 * With CSC DigiSign on, the provider signs as the step runs. Off it (never in
 * production), the step waits in the session for the eSign simulator, exactly as
 * a Pre-DPC submission does.
 */
class EsignGate
{
    public const SESSION_KEY = 'esign.pending';

    /**
     * @param  class-string<SignedAction>  $action
     * @param  array<string, mixed>  $payload
     */
    public function begin(Request $request, User $signer, string $action, array $payload): RedirectResponse
    {
        if (! $this->simulated()) {
            return app($action)->perform($request, $signer, $payload);
        }

        $reference = Str::random(40);
        $request->session()->put(self::SESSION_KEY, [
            'reference' => $reference, 'action' => $action, 'payload' => $payload,
            'user_id' => $signer->id, 'expires' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()->route('esign.simulator', ['reference' => $reference]);
    }

    public function simulated(): bool
    {
        // The mock provider is bound exactly while CSC DigiSign is off
        // (AppServiceProvider); it is never used in production.
        return ! app()->environment('production') && Feature::CscDigiSign->disabled();
    }

    /**
     * Production never signs with the mock provider.
     */
    public function available(): bool
    {
        return ! (app()->environment('production') && Feature::CscDigiSign->disabled());
    }
}
