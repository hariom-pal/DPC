<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Committee\Requests\CgpscChairmanRequest;
use App\Domain\Committee\Services\CgpscChairmanService;
use App\Domain\Master\Models\Designation;
use App\Domain\UserManagement\Services\UserProvisioningService;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Http\Controllers\Concerns\ShowsIssuedPassword;
use App\Http\Controllers\Controller;
use App\Support\OfficialEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
    use ShowsIssuedPassword;

    /**
     * Authorisation is the route group's: every route below sits inside
     * `role:admin`, as every other master screen does. A policy here would be a
     * second gate saying the same thing, and two gates are how one of them comes
     * to say something different.
     */
    public function __construct(
        private readonly CgpscChairmanService $chairmen,
        private readonly UserProvisioningService $provisioning,
    ) {}

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
     * Create the serving Chairman's login from this record (2026-11-17).
     *
     * The record names the office-holder; the login is a separate account, and a
     * record appointed without one left the Administrator nothing to reset. This
     * provisions a `chairman` account from the record's own name, e-mail and
     * mobile, links it, and shows the temporary password once — exactly as User
     * Management does. Only for the serving record, and only while it names none:
     * linking never replaces a person already on record.
     */
    public function createAccount(CgpscChairman $cgpscChairman): Response|RedirectResponse
    {
        $serving = $this->chairmen->serving();

        if ($serving === null || $serving->id !== $cgpscChairman->id || $cgpscChairman->user_id !== null) {
            return redirect()->route('admin.cgpsc-chairman.index')
                ->withErrors(['account' => __('admin.cgpsc_chairman.account_not_allowed')]);
        }

        // A Chairman login made earlier in User Management with this record's
        // e-mail or mobile is that same Chairman: link it rather than refuse.
        $existing = User::role(Role::Chairman->value)->where('is_active', true)
            ->where(fn ($query) => $query->where('email', (string) $cgpscChairman->email)->orWhere('mobile', (string) $cgpscChairman->mobile))
            ->first();

        if ($existing !== null && ($cgpscChairman->email || $cgpscChairman->mobile)) {
            $this->chairmen->linkIfVacant($existing, request()->user());

            return redirect()->route('admin.cgpsc-chairman.index')->with('status', __('admin.cgpsc_chairman.account_linked', ['name' => $existing->name]));
        }

        $validator = Validator::make(
            ['email' => $cgpscChairman->email, 'mobile' => $cgpscChairman->mobile],
            [
                'email' => ['required', 'email', 'max:255', OfficialEmail::RULE, Rule::unique('users', 'email')->withoutTrashed()],
                'mobile' => ['required', 'digits:10', Rule::unique('users', 'mobile')->withoutTrashed()],
            ],
            [
                'email.required' => __('admin.cgpsc_chairman.account_needs_contact'),
                'mobile.required' => __('admin.cgpsc_chairman.account_needs_contact'),
                'email.unique' => __('admin.cgpsc_chairman.account_exists'),
                'mobile.unique' => __('admin.cgpsc_chairman.account_exists'),
            ],
        );

        if ($validator->fails()) {
            return redirect()->route('admin.cgpsc-chairman.index')->withErrors(['account' => $validator->errors()->first()]);
        }

        $issued = DB::transaction(function () use ($cgpscChairman) {
            $issued = $this->provisioning->provision([
                'name' => $cgpscChairman->name,
                'email' => (string) $cgpscChairman->email,
                'mobile' => (string) $cgpscChairman->mobile,
                'designation_id' => null,
                'role' => Role::Chairman->value,
            ], request()->user());

            $this->chairmen->linkIfVacant($issued->user, request()->user());

            return $issued;
        });

        return $this->showIssuedPassword($issued->user, $issued->temporaryPassword, 'created', 'layouts.admin', route('admin.cgpsc-chairman.index'));
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
