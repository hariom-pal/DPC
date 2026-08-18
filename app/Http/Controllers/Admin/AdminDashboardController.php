<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\CommitteeConfiguration;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\District;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\ReservationPolicy;
use App\Domain\Master\Models\ReservationRoster;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * The administration landing screen.
 *
 * Read-only throughout: it counts what exists so an administrator can see at a
 * glance whether the masters are populated and where the portal's workload
 * sits. Nothing here decides or changes anything.
 */
class AdminDashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard', [
            'portal' => [
                'departments_registered' => Department::query()->where('status', '!=', DepartmentStatus::Unregistered->value)->count(),
                'departments_total' => Department::query()->count(),
                'proposals' => DpcProposal::query()->count(),
                'proposals_with_commission' => DpcProposal::query()
                    ->where('status', DpcProposalStatus::Approved->value)
                    ->whereNotNull('forwarded_at')
                    ->count(),
                'sittings_open' => DpcProceeding::query()->where('status', DpcProceedingStatus::InProceeding->value)->count(),
                'sittings_concluded' => DpcProceeding::query()->where('status', DpcProceedingStatus::ResultApproved->value)->count(),
                'circulations' => DpcCirculation::query()->count(),
            ],
            'accounts' => [
                'total' => User::query()->count(),
                'inactive' => User::query()->where('is_active', false)->count(),
                'by_role' => collect(Role::cases())
                    ->mapWithKeys(fn (Role $role): array => [
                        $role->label() => User::query()->whereHas('roles', fn ($q) => $q->where('name', $role->value))->count(),
                    ])
                    ->filter()
                    ->all(),
            ],
            // The masters an administrator has to populate before a department
            // can run a DPC. A zero here is the reason a later screen is empty.
            'masters' => [
                ['label' => 'Departments', 'count' => Department::query()->count(), 'route' => route('admin.departments.index')],
                ['label' => 'Districts', 'count' => District::query()->count(), 'route' => route('admin.districts.index')],
                ['label' => 'Designations', 'count' => Designation::query()->count(), 'route' => route('admin.designations.index')],
                ['label' => 'Cadres', 'count' => Cadre::query()->count(), 'route' => route('admin.cadres.index')],
                ['label' => 'Recruitment Rules', 'count' => RecruitmentRule::query()->count(), 'route' => route('admin.recruitment-rules.index')],
                ['label' => 'Reservation Policies', 'count' => ReservationPolicy::query()->count(), 'route' => route('admin.reservation-policies.index')],
                ['label' => 'Reservation Rosters', 'count' => ReservationRoster::query()->count(), 'route' => route('admin.reservation-rosters.index')],
                ['label' => 'Committee Configurations', 'count' => CommitteeConfiguration::query()->count(), 'route' => route('admin.committee-configurations.index')],
            ],
        ]);
    }
}
