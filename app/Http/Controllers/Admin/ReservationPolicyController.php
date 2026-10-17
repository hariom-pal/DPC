<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReservationPolicy;
use App\Domain\Master\Requests\ReservationPolicyRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Domain\Master\Services\PhReservationPolicy;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $policy = $this->crud->create(new ReservationPolicy, $request->safe()->except('document'));
        $this->storeDocument($policy, $request);

        return redirect()->route('admin.reservation-policies.index')->with('status', 'Reservation policy created.');
    }

    public function edit(ReservationPolicy $reservationPolicy): View
    {
        return view('admin.reservation-policies.form', $this->formData($reservationPolicy));
    }

    public function update(ReservationPolicyRequest $request, ReservationPolicy $reservationPolicy): RedirectResponse
    {
        $this->crud->update($reservationPolicy, $request->safe()->except('document'));
        $this->storeDocument($reservationPolicy, $request);

        return redirect()->route('admin.reservation-policies.index')->with('status', 'Reservation policy updated.');
    }

    public function destroy(ReservationPolicy $reservationPolicy): RedirectResponse
    {
        $this->crud->delete($reservationPolicy);

        return redirect()->route('admin.reservation-policies.index')->with('status', 'Reservation policy deleted.');
    }

    public function document(ReservationPolicy $reservationPolicy): StreamedResponse
    {
        $storage = Storage::disk((string) $reservationPolicy->disk);
        abort_unless($reservationPolicy->path !== null && $storage->exists($reservationPolicy->path), 404);

        return $storage->download($reservationPolicy->path, (string) $reservationPolicy->original_name, ['Content-Type' => 'application/pdf']);
    }

    /**
     * The supporting PDF, when one is uploaded. A new upload replaces the reference;
     * the earlier file stays on disk and in the audit trail.
     */
    private function storeDocument(ReservationPolicy $policy, ReservationPolicyRequest $request): void
    {
        $file = $request->file('document');

        if ($file === null) {
            return;
        }

        $disk = (string) config('dpc.documents.disk', 'local');
        $path = Storage::disk($disk)->putFile('private/reservation-policies', $file);
        abort_if($path === false, 500);

        $policy->forceFill([
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'size_bytes' => $file->getSize() ?: 0,
            'sha256' => hash_file('sha256', (string) $file->getRealPath()) ?: null,
        ])->save();
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
            'roundings' => PhReservationPolicy::ROUNDINGS,
        ];
    }
}
