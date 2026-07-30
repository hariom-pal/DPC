<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\ReviewReason;
use App\Domain\Master\Requests\ReviewReasonRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ReviewReasonController extends Controller
{
    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(): View
    {
        return view('admin.review-reasons.index', [
            'reasons' => ReviewReason::query()->orderBy('code')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.review-reasons.form', ['reason' => new ReviewReason, 'statuses' => MasterStatus::cases()]);
    }

    public function store(ReviewReasonRequest $request): RedirectResponse
    {
        $this->crud->create(new ReviewReason, $request->validated());

        return redirect()->route('admin.review-reasons.index')->with('status', 'Review reason created.');
    }

    public function edit(ReviewReason $reviewReason): View
    {
        return view('admin.review-reasons.form', ['reason' => $reviewReason, 'statuses' => MasterStatus::cases()]);
    }

    public function update(ReviewReasonRequest $request, ReviewReason $reviewReason): RedirectResponse
    {
        $this->crud->update($reviewReason, $request->validated());

        return redirect()->route('admin.review-reasons.index')->with('status', 'Review reason updated.');
    }

    public function destroy(ReviewReason $reviewReason): RedirectResponse
    {
        $this->crud->delete($reviewReason);

        return redirect()->route('admin.review-reasons.index')->with('status', 'Review reason deleted.');
    }
}
