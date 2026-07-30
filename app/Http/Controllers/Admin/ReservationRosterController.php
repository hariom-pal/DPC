<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReservationRoster;
use App\Domain\Master\Requests\ReservationRosterRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ReservationRosterController extends Controller
{
    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(): View
    {
        return view('admin.reservation-rosters.index', [
            'rosters' => ReservationRoster::query()->with(['department', 'cadre'])->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.reservation-rosters.form', $this->formData(new ReservationRoster));
    }

    public function store(ReservationRosterRequest $request): RedirectResponse
    {
        $roster = $this->crud->create(new ReservationRoster, $request->validated());

        return redirect()->route('admin.reservation-rosters.edit', $roster)
            ->with('status', 'Roster created. Define its points below.');
    }

    public function edit(ReservationRoster $reservationRoster): View
    {
        $reservationRoster->load('points.reservationCategory');

        return view('admin.reservation-rosters.form', $this->formData($reservationRoster));
    }

    public function update(ReservationRosterRequest $request, ReservationRoster $reservationRoster): RedirectResponse
    {
        $this->crud->update($reservationRoster, $request->validated());

        return redirect()->route('admin.reservation-rosters.edit', $reservationRoster)->with('status', 'Roster updated.');
    }

    public function destroy(ReservationRoster $reservationRoster): RedirectResponse
    {
        $this->crud->delete($reservationRoster);

        return redirect()->route('admin.reservation-rosters.index')->with('status', 'Roster deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(ReservationRoster $roster): array
    {
        return [
            'roster' => $roster,
            'departments' => Department::query()->orderBy('name')->get(),
            'cadres' => Cadre::query()->where('status', MasterStatus::Active->value)->orderBy('name')->get(),
            'categories' => ReservationCategory::query()->where('status', MasterStatus::Active->value)->orderBy('code')->get(),
            'statuses' => MasterStatus::cases(),
        ];
    }
}
