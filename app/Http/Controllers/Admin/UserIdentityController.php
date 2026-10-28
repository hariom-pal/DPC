<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Identity\Exceptions\IdentityRefused;
use App\Domain\Identity\Models\IdentityCorrection;
use App\Domain\Identity\Models\IdentityVerification;
use App\Domain\Identity\Requests\IdentityDecisionRequest;
use App\Domain\Identity\Services\IdentityCorrectionService;
use App\Domain\Identity\Services\IdentityVerificationService;
use App\Domain\Identity\Services\SupportingDocumentStore;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CGPSC Super Admin → Users → Identity (2026-10-27): the officer's identity status
 * and history, correction of identity details (locked ones included), exceptional
 * manual verification, and forced re-verification. Every decision carries a
 * reason; correction of verified details and manual verification carry a
 * supporting document.
 */
class UserIdentityController extends Controller
{
    public function __construct(
        private readonly IdentityVerificationService $identity,
        private readonly IdentityCorrectionService $corrections,
        private readonly SupportingDocumentStore $documents,
    ) {}

    public function show(User $user): View
    {
        return view('admin.users.identity', [
            'user' => $user,
            'status' => $this->identity->statusLabel($user),
            'lockedUntil' => $this->identity->lockedUntil($user),
            'verifications' => IdentityVerification::query()->where('user_id', $user->id)->with(['decidedBy', 'revokedBy'])->latest('id')->get(),
            'corrections' => IdentityCorrection::query()->where('user_id', $user->id)->with('correctedBy')->latest('id')->get(),
        ]);
    }

    public function correct(IdentityDecisionRequest $request, User $user): RedirectResponse
    {
        return $this->decide(fn () => $this->corrections->correct(
            $user,
            $request->only(['name', 'date_of_birth', 'gender']),
            (string) $request->input('reason'),
            $request->user(),
            $request->file('document'),
        ), $user);
    }

    public function manual(IdentityDecisionRequest $request, User $user): RedirectResponse
    {
        return $this->decide(fn () => $this->identity->verifyManually($user, $request->user(), (string) $request->input('reason'), $request->file('document')), $user);
    }

    public function force(IdentityDecisionRequest $request, User $user): RedirectResponse
    {
        return $this->decide(fn () => $this->identity->forceReverification($user, $request->user(), (string) $request->input('reason')), $user);
    }

    public function verificationDocument(User $user, IdentityVerification $verification): StreamedResponse
    {
        abort_unless($verification->user_id === $user->id, 404);

        return $this->documents->download($verification->document_disk, $verification->document_path, $verification->document_name);
    }

    public function correctionDocument(User $user, IdentityCorrection $correction): StreamedResponse
    {
        abort_unless($correction->user_id === $user->id, 404);

        return $this->documents->download($correction->document_disk, $correction->document_path, $correction->document_name);
    }

    private function decide(callable $action, User $user): RedirectResponse
    {
        try {
            $action();
        } catch (IdentityRefused $e) {
            return back()->withInput()->withErrors(['identity' => $e->getMessage()]);
        }

        return redirect()->route('admin.users.identity', $user)->with('status', __('identity.admin.saved'));
    }
}
