<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreviousDpc\Models\PreviousDpcRecord;
use App\Domain\PreviousDpc\Requests\StorePreviousDpcRecordRequest;
use App\Domain\PreviousDpc\Services\PreviousDpcRecordService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Coder Instructions rule 1.1: DPCs held offline, entered by the Commission's staff.
 * The Administrator reads them; only the staff record them.
 */
class PreviousDpcRecordController extends Controller
{
    public function index(Request $request): View
    {
        return view('previous-dpcs.index', [
            'records' => PreviousDpcRecord::query()->with(['department', 'promotionToPost'])->orderByDesc('held_on')->paginate(25),
            'canRecord' => $request->user()?->hasRole(Role::CgpscStaff->value) ?? false,
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()?->hasRole(Role::CgpscStaff->value), 403);

        return view('previous-dpcs.create', [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'posts' => DepartmentPost::query()->orderBy('name')->get(['id', 'department_id', 'name']),
            'uploadsRequiredFrom' => PreviousDpcRecord::UPLOADS_REQUIRED_FROM,
        ]);
    }

    public function store(StorePreviousDpcRecordRequest $request, PreviousDpcRecordService $records): RedirectResponse
    {
        try {
            $record = $records->record($request->safe()->except(PreviousDpcRecord::FILES), [
                'minutes' => $request->file('minutes'),
                'seniority_list' => $request->file('seniority_list'),
                'working_officers' => $request->file('working_officers'),
            ], $request->user());
        } catch (WorkflowException $e) {
            return back()->withInput()->withErrors(['record' => $e->getMessage()]);
        }

        return redirect()->route('previous-dpcs.show', $record)->with('status', __('previous_dpc.recorded'));
    }

    public function show(PreviousDpcRecord $previousDpc): View
    {
        $previousDpc->loadMissing(['department', 'promotionFromPost', 'promotionToPost', 'recorder']);

        return view('previous-dpcs.show', ['record' => $previousDpc]);
    }

    public function file(PreviousDpcRecord $previousDpc, string $file): StreamedResponse
    {
        abort_unless($previousDpc->hasFile($file), 404);

        return Storage::disk((string) $previousDpc->getAttribute($file.'_disk'))
            ->download((string) $previousDpc->getAttribute($file.'_path'), (string) $previousDpc->getAttribute($file.'_original_name'), ['Content-Type' => 'application/pdf']);
    }
}
