<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Import\Enums\ImportTemplate;
use App\Domain\Import\Models\ImportBatch;
use App\Domain\Import\Requests\UploadImportFileRequest;
use App\Domain\Import\Services\ExcelImportService;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Excel import for the three PPT upload templates (S16–S18) — M4 Phase 6.
 *
 * Every action is gated by the existing `update` ability on the parent
 * proposal, so an import inherits exactly the same ownership and
 * DRAFT/RETURNED-only rules as manual entry — there is no second, weaker path
 * into a submitted proposal. Batches are additionally scoped to the proposal
 * in the route binding, so a batch id from another proposal cannot be driven
 * through this controller.
 */
class DpcImportController extends Controller
{
    public function __construct(private readonly ExcelImportService $imports) {}

    public function store(UploadImportFileRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);

        $template = ImportTemplate::from((string) $request->validated('template'));

        try {
            $batch = $this->imports->preview(
                $dpcProposal,
                $template,
                $request->file('file'),
                $request->user(),
                $request->filled('seniority_list_as_on')
                    ? Carbon::parse((string) $request->validated('seniority_list_as_on'))
                    : null,
            );
        } catch (WorkflowException $e) {
            return redirect()
                ->route($template->sectionRoute(), $dpcProposal)
                ->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('dpc-proposals.imports.show', [$dpcProposal, $batch]);
    }

    public function show(Request $request, DpcProposal $dpcProposal, ImportBatch $importBatch): View
    {
        $this->authorize('update', $dpcProposal);
        $this->assertBelongsTo($dpcProposal, $importBatch);

        $importBatch->load('uploader', 'committer');

        return view('dpc-proposals.imports.preview', [
            'proposal' => $dpcProposal,
            'batch' => $importBatch,
            'validRows' => $importBatch->validRows()->paginate(25, ['*'], 'valid_page'),
            'rejectedRows' => $importBatch->rejectedRows()->paginate(25, ['*'], 'rejected_page'),
            // Rows stage a resolved category id; the preview shows the code the
            // officer actually typed in the spreadsheet.
            'categoryCodes' => ReservationCategory::query()->pluck('code', 'id'),
            'duplicates' => $this->imports->duplicatesOf($importBatch),
            'fileIntact' => $this->imports->verifyStoredFileIntegrity($importBatch),
            // Whose recorded details a Seniority List commit keeps, and whose it
            // detaches — shown before the officer confirms.
            'seniorityChanges' => $importBatch->template === ImportTemplate::FinalSeniorityListUs
                && $importBatch->status->isCommittable()
                    ? $this->imports->seniorityChanges($importBatch)
                    : null,
        ]);
    }

    public function commit(Request $request, DpcProposal $dpcProposal, ImportBatch $importBatch): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);
        $this->assertBelongsTo($dpcProposal, $importBatch);

        try {
            $this->imports->commit($importBatch, $request->user());
        } catch (WorkflowException $e) {
            return redirect()
                ->route('dpc-proposals.imports.show', [$dpcProposal, $importBatch])
                ->withErrors(['commit' => $e->getMessage()]);
        }

        return redirect()
            ->route('dpc-proposals.show', $dpcProposal)
            ->with('status', "{$importBatch->template->label()} imported: {$importBatch->imported_count} rows committed, {$importBatch->rejected_count} rejected.");
    }

    public function cancel(Request $request, DpcProposal $dpcProposal, ImportBatch $importBatch): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);
        $this->assertBelongsTo($dpcProposal, $importBatch);

        try {
            $this->imports->cancel($importBatch, $request->user());
        } catch (WorkflowException $e) {
            return redirect()
                ->route('dpc-proposals.imports.show', [$dpcProposal, $importBatch])
                ->withErrors(['commit' => $e->getMessage()]);
        }

        return redirect()
            ->route($importBatch->template->sectionRoute(), $dpcProposal)
            ->with('status', 'Import cancelled. Nothing was changed.');
    }

    /**
     * Downloadable error report (architect directive) — every rejected row and
     * the reason it was rejected, as CSV.
     */
    public function errors(Request $request, DpcProposal $dpcProposal, ImportBatch $importBatch): Response
    {
        $this->authorize('update', $dpcProposal);
        $this->assertBelongsTo($dpcProposal, $importBatch);

        $csv = $this->imports->errorReportCsv($importBatch);
        $filename = "import-errors-batch-{$importBatch->id}.csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function assertBelongsTo(DpcProposal $proposal, ImportBatch $batch): void
    {
        abort_unless($batch->dpc_proposal_id === $proposal->id, 404);
    }
}
