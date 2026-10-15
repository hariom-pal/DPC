<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Requests\PayScaleRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Admin\Concerns\ListsAMaster;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PayScaleController extends Controller
{
    use ListsAMaster;

    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(Request $request): View
    {
        return view('admin.pay-scales.index', [
            'payScales' => $this->searched($request, PayScale::query()->orderBy('label'), 'pay-scales')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.pay-scales.form', ['payScale' => new PayScale, 'statuses' => MasterStatus::cases()]);
    }

    public function store(PayScaleRequest $request): RedirectResponse
    {
        $this->crud->create(new PayScale, $request->validated());

        return redirect()->route('admin.pay-scales.index')->with('status', 'Pay scale created.');
    }

    public function edit(PayScale $payScale): View
    {
        return view('admin.pay-scales.form', ['payScale' => $payScale, 'statuses' => MasterStatus::cases()]);
    }

    public function update(PayScaleRequest $request, PayScale $payScale): RedirectResponse
    {
        $this->crud->update($payScale, $request->validated());

        return redirect()->route('admin.pay-scales.index')->with('status', 'Pay scale updated.');
    }

    public function destroy(PayScale $payScale): RedirectResponse
    {
        $this->crud->delete($payScale);

        return redirect()->route('admin.pay-scales.index')->with('status', 'Pay scale deleted.');
    }
}
