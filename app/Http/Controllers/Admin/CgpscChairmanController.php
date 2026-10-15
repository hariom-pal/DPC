<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Committee\Requests\CgpscChairmanRequest;
use App\Domain\Committee\Services\CgpscChairmanService;
use App\Domain\Master\Models\Designation;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The CGPSC Chairman, maintained by the Administrator (change register of
 * 2026-08-26, item 9 as clarified).
 *
 * > *Admin has the option to add or modify the CGPSC Chairman detail, with an
 * > audit trail.*
 *
 * **Two operations, not four**, and the missing two are the point:
 *
 *  - There is **no destroy**. A Chairman who has left office is succeeded, not
 *    deleted — every DPC composed during their tenure names them, and removing the
 *    record would orphan those committees. `appoint()` closes the tenure instead.
 *  - There is **no arbitrary update of who holds the office**. `amend()` corrects
 *    particulars — a misspelt name, a new mobile number — and deliberately refuses
 *    to change `user_id` or `effective_from`, because replacing the person in place
 *    would rewrite who chaired a committee that has already sat.
 *
 * The audit trail the register asks for comes from three places at once: the model
 * is `Auditable`, so every column change is recorded field by field; the blameable
 * columns record who; and the service writes a timeline entry naming the
 * succession. The screen shows the full tenure history, so the trail is visible
 * rather than merely stored.
 */
class CgpscChairmanController extends Controller
{
    /**
     * Authorisation is the route group's: every route below sits inside
     * `role:admin`, as every other master screen does. A policy here would be a
     * second gate saying the same thing, and two gates are how one of them comes
     * to say something different.
     */
    public function __construct(private readonly CgpscChairmanService $chairmen) {}

    public function index(): View
    {
        return view('admin.cgpsc-chairman.index', [
            'serving' => $this->chairmen->serving(),
            'history' => $this->chairmen->history(),
        ]);
    }

    public function create(): View
    {
        return view('admin.cgpsc-chairman.form', [
            'chairman' => new CgpscChairman,
            'serving' => $this->chairmen->serving(),
            'candidates' => $this->candidates(),

            // The Chairman's rank comes from the master, exactly as a committee
            // member's does (DPC-MODULE-FIX-001, second pass).
            'designations' => $this->designations(),
        ]);
    }

    public function store(CgpscChairmanRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->chairmen->appoint(
                $data,
                $request->user(),
                isset($data['effective_from']) ? Carbon::parse((string) $data['effective_from']) : null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['effective_from' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.cgpsc-chairman.index')
            ->with('status', __('admin.cgpsc_chairman.appointed'));
    }

    public function edit(CgpscChairman $cgpscChairman): View
    {
        return view('admin.cgpsc-chairman.form', [
            'chairman' => $cgpscChairman,
            'serving' => $this->chairmen->serving(),
            'candidates' => $this->candidates(),

            // The Chairman's rank comes from the master, exactly as a committee
            // member's does (DPC-MODULE-FIX-001, second pass).
            'designations' => $this->designations(),
        ]);
    }

    public function update(CgpscChairmanRequest $request, CgpscChairman $cgpscChairman): RedirectResponse
    {
        $this->chairmen->amend($cgpscChairman, $request->validated(), $request->user());

        return redirect()
            ->route('admin.cgpsc-chairman.index')
            ->with('status', __('admin.cgpsc_chairman.amended'));
    }

    /**
     * The ranks this office may be recorded as holding — active rows only.
     *
     * @return Collection<int, Designation>
     */
    private function designations(): Collection
    {
        return Designation::query()
            ->where('status', MasterStatus::Active)
            ->orderBy('name')
            ->get();
    }

    /**
     * Accounts the Administrator may name as Chairman.
     *
     * Offered rather than required: the record may name no account at all, because
     * an incoming Chairman's login may not exist yet and the Commission still needs
     * to say who holds the office. The list is not restricted to `Role::Chairman`
     * holders — appointing somebody here is what makes them Chairman, so requiring
     * them to already hold the role would be circular.
     *
     * @return Collection<int, User>
     */
    private function candidates(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', Role::PromotionCandidate->value))
            ->orderBy('name')
            ->get();
    }
}
