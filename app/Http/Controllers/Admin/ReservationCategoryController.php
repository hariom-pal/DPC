<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Requests\ReservationCategoryRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Admin\Concerns\ListsAMaster;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReservationCategoryController extends Controller
{
    use ListsAMaster;

    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(Request $request): View
    {
        return view('admin.reservation-categories.index', [
            'categories' => $this->searched($request, ReservationCategory::query()->orderBy('code'), 'reservation-categories')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.reservation-categories.form', ['category' => new ReservationCategory, 'statuses' => MasterStatus::cases()]);
    }

    public function store(ReservationCategoryRequest $request): RedirectResponse
    {
        $this->crud->create(new ReservationCategory, $request->validated());

        return redirect()->route('admin.reservation-categories.index')->with('status', 'Reservation category created.');
    }

    public function edit(ReservationCategory $reservationCategory): View
    {
        return view('admin.reservation-categories.form', ['category' => $reservationCategory, 'statuses' => MasterStatus::cases()]);
    }

    public function update(ReservationCategoryRequest $request, ReservationCategory $reservationCategory): RedirectResponse
    {
        $this->crud->update($reservationCategory, $request->validated());

        return redirect()->route('admin.reservation-categories.index')->with('status', 'Reservation category updated.');
    }

    public function destroy(ReservationCategory $reservationCategory): RedirectResponse
    {
        $this->crud->delete($reservationCategory);

        return redirect()->route('admin.reservation-categories.index')->with('status', 'Reservation category deleted.');
    }
}
