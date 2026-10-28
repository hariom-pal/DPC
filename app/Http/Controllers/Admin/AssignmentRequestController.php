<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Services\SupportingDocumentStore;
use App\Domain\UserManagement\Exceptions\AssignmentRefused;
use App\Domain\UserManagement\Models\AssignmentRequest;
use App\Domain\UserManagement\Services\AssignmentRequestService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CGPSC Super Admin → Transfer and additional-charge requests (Phases 7–8,
 * 2026-10-27): approve or reject, with the order to read.
 */
class AssignmentRequestController extends Controller
{
    public function __construct(
        private readonly AssignmentRequestService $requests,
        private readonly SupportingDocumentStore $documents,
    ) {}

    public function index(): View
    {
        return view('admin.assignment-requests.index', [
            'requests' => AssignmentRequest::query()
                ->with(['user', 'fromDepartment', 'toDepartment', 'requestedBy', 'decidedBy'])
                ->orderByRaw("status = 'submitted' DESC")
                ->latest('id')
                ->paginate(25),
        ]);
    }

    public function decide(Request $request, AssignmentRequest $assignmentRequest): RedirectResponse
    {
        $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->requests->decide($assignmentRequest, $request->user(), $request->input('decision') === 'approve', $request->input('reason'));
        } catch (AssignmentRefused $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('status', __('assignments.decided'));
    }

    public function document(AssignmentRequest $assignmentRequest): StreamedResponse
    {
        return $this->documents->download($assignmentRequest->document_disk, $assignmentRequest->document_path, $assignmentRequest->document_name);
    }
}
