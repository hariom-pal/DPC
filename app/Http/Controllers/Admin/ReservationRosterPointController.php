<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\ReservationRoster;
use App\Domain\Master\Models\ReservationRosterPoint;
use App\Domain\Master\Requests\ReservationRosterPointRequest;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class ReservationRosterPointController extends Controller
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    public function store(ReservationRosterPointRequest $request, ReservationRoster $reservationRoster): RedirectResponse
    {
        $point = $reservationRoster->points()->create($request->validated());
        $this->timeline->record('roster.point_added', $reservationRoster, "Roster point {$point->point_no} added");

        return redirect()->route('admin.reservation-rosters.edit', $reservationRoster)->with('status', 'Roster point added.');
    }

    public function destroy(ReservationRoster $reservationRoster, ReservationRosterPoint $point): RedirectResponse
    {
        abort_unless($point->reservation_roster_id === $reservationRoster->id, 404);

        $point->delete();
        $this->timeline->record('roster.point_removed', $reservationRoster, "Roster point {$point->point_no} removed");

        return redirect()->route('admin.reservation-rosters.edit', $reservationRoster)->with('status', 'Roster point removed.');
    }
}
