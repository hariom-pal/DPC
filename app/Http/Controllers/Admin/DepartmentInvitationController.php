<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Department\Exceptions\InvitationRefused;
use App\Domain\Department\IssuedInvitation;
use App\Domain\Department\Models\Department;
use App\Domain\Department\Models\DepartmentInvitation;
use App\Domain\Department\Requests\StoreDepartmentInvitationRequest;
use App\Domain\Department\Services\DepartmentInvitationService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Administration → Department Admin invitations (business decision, 2026-10-26).
 *
 * The Portal Super Admin invites, resends and revokes. An e-mail the mail server
 * refused is reported here, and the invitation can be resent.
 */
class DepartmentInvitationController extends Controller
{
    public function __construct(
        private readonly DepartmentInvitationService $invitations,
    ) {}

    public function index(): View
    {
        return view('admin.department-invitations.index', [
            'departments' => Department::query()->orderBy('name')->get(),
            'invitations' => DepartmentInvitation::query()->with(['department', 'inviter', 'acceptedUser'])->latest('id')->paginate(25),
        ]);
    }

    public function store(StoreDepartmentInvitationRequest $request): RedirectResponse|Response
    {
        try {
            $issued = $this->invitations->invite(
                Department::query()->findOrFail($request->integer('department_id')),
                (string) $request->input('email'),
                $request->user(),
            );
        } catch (InvitationRefused $e) {
            return back()->withInput()->withErrors(['email' => $e->getMessage()]);
        }

        return $this->showLink($issued, resent: false);
    }

    public function resend(Request $request, DepartmentInvitation $invitation): RedirectResponse|Response
    {
        try {
            $issued = $this->invitations->resend($invitation, $request->user());
        } catch (InvitationRefused $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return $this->showLink($issued, resent: true);
    }

    /**
     * The registration link, shown once to the Portal Super Admin (2026-10-26), so a
     * department can be invited while no mail server delivers. The direct response
     * to the POST — never flashed or put in a URL — and not cached; only the token's
     * hash exists afterwards, so a lost link is replaced with Resend.
     */
    private function showLink(IssuedInvitation $issued, bool $resent): Response
    {
        return response()
            ->view('admin.department-invitations.issued', [
                'invitation' => $issued->invitation->loadMissing('department'),
                'link' => $issued->link,
                'mailed' => $issued->mailed,
                'resent' => $resent,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    public function revoke(Request $request, DepartmentInvitation $invitation): RedirectResponse
    {
        try {
            $this->invitations->revoke($invitation, $request->user());
        } catch (InvitationRefused $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('status', __('invitations.revoked'));
    }
}
