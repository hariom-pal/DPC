<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReservationPolicy;
use App\Domain\Master\Requests\ReservationPolicyRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ReservationPolicyController extends Controller
{
    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(): View
    {
        return view('admin.reservation-policies.index', [
            'policies' => ReservationPolicy::query()->with('reservationCategory')->latest('effective_from')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.reservation-policies.form', $this->formData(new ReservationPolicy));
    }

    public function store(ReservationPolicyRequest $request): RedirectResponse
    {
        $this->crud->create(new ReservationPolicy, $request->validated());

        return redirect()->route('admin.reservation-policies.index')->with('status', 'Reservation policy created.');
    }

    public function edit(ReservationPolicy $reservationPolicy): View
    {
        return view('admin.reservation-policies.form', $this->formData($reservationPolicy));
    }

    public function update(ReservationPolicyRequest $request, ReservationPolicy $reservationPolicy): RedirectResponse
    {
        $this->crud->update($reservationPolicy, $request->validated());

        return redirect()->route('admin.reservation-policies.index')->with('status', 'Reservation policy updated.');
    }

    public function destroy(ReservationPolicy $reservationPolicy): RedirectResponse
    {
        $this->crud->delete($reservationPolicy);

        return redirect()->route('admin.reservation-policies.index')->with('status', 'Reservation policy deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(ReservationPolicy $policy): array
    {
        return [
            'policy' => $policy,
            'categories' => ReservationCategory::query()->where('status', MasterStatus::Active->value)->orderBy('code')->get(),
            'statuses' => MasterStatus::cases(),
        ];
    }
}
