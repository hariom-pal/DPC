<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Maintenance\DpcDataPurgeService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Admin → Clear DPC data: `dpc:purge-dpc-data` from the browser (2026-11-02). The
 * page shows what would go; deleting needs the database name typed.
 */
class DpcDataPurgeController extends Controller
{
    public function __construct(private readonly DpcDataPurgeService $purge) {}

    public function index(): View
    {
        return view('admin.dpc-data-purge.index', ['plan' => $this->purge->plan()]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($this->purge->allowed(), 403);

        $request->validate(['confirmation' => ['required', 'string', 'max:128']]);

        try {
            $result = $this->purge->apply((string) $request->input('confirmation'), $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['confirmation' => $exception->getMessage()]);
        }

        return redirect()->route('admin.dpc-data-purge.index')->with('status', __('admin.dpc_data_purge.done', [
            'rows' => $result['total'],
            'files' => $result['files']['deleted'],
        ]));
    }
}
