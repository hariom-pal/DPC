<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Dpc\Exports\TabulationChartExport;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Import\Enums\ImportBatchStatus;
use App\Domain\Import\Enums\ImportTemplate;
use App\Domain\Import\Models\ImportBatch;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MoM of 16 November 2026, point 9: for the Administrative Head and every authority
 * after (Secretary, DPC Committee, Chairman), the Working Officers List and the
 * Seniority List as originally uploaded, and a Tabulation Chart that sets what the
 * lists said apart from what was added on the portal afterwards.
 */
class DpcTabulationController extends Controller
{
    /** The files, as uploaded: the latest committed batch of each list. */
    private const TEMPLATES = [ImportTemplate::CurrentlyWorkingUs, ImportTemplate::FinalSeniorityListUs];

    public function __construct(private readonly EmployeeDetailsService $details) {}

    public function show(DpcProposal $dpcProposal): View
    {
        $this->authorize('viewRecords', $dpcProposal);
        $years = $this->prepare($dpcProposal);

        return view('dpc-proposals.tabulation-chart', [
            'proposal' => $dpcProposal,
            'originals' => $this->originals($dpcProposal),
            'crYears' => $years,
            'propertyYears' => $years,
            'replacements' => $this->replacements($dpcProposal, $years),
        ]);
    }

    /** The chart as an Excel workbook (2026-11-18), for the same readers as the screen. */
    public function download(DpcProposal $dpcProposal): BinaryFileResponse
    {
        $this->authorize('viewRecords', $dpcProposal);
        $years = $this->prepare($dpcProposal);
        $name = 'tabulation-chart-'.Str::slug((string) ($dpcProposal->dpc_code ?: $dpcProposal->id)).'-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(new TabulationChartExport($dpcProposal, $years, $years, $this->replacements($dpcProposal, $years)), $name);
    }

    /**
     * Load what the chart shows; the five CR (and Property File) years before the DPC's, latest first.
     *
     * @return list<int>
     */
    private function prepare(DpcProposal $proposal): array
    {
        $proposal->load(['evaluationRows' => fn ($query) => $query->orderBy('sn'), 'evaluationRows.reservationCategory', 'evaluationRows.crGrades.integrityOption', 'evaluationRows.propertyFlags', 'evaluationRows.currentDocuments']);
        $dpcYear = (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);

        // As many as the promotion rule counts (five without a rule), 2026-11-18.
        return range($dpcYear - 1, $dpcYear - $this->details->crSlots($proposal));
    }

    /**
     * For each officer, each CR year on the chart that does not count (missing, or put
     * up without a grading) and the older year counted in its place — the officer's own
     * CR window, as the rule check and the calculation take it (2026-11-18). Null where
     * no older year is left (the year before the DPC year is never replaced).
     *
     * @param  list<int>  $years  the chart's CR years, latest first
     * @return array<int, array<int, int|null>> row id => [skipped year => year counted instead]
     */
    private function replacements(DpcProposal $proposal, array $years): array
    {
        $slots = count($years);
        $replacements = [];

        foreach ($proposal->evaluationRows as $row) {
            $row->setRelation('proposal', $proposal);
            $window = $this->details->crWindow($row, $row->crGrades, $slots);
            // The older years the window reached for, latest first, pair with the skipped chart years in order.
            $extra = array_values(array_diff($window->counted, $years));
            $skipped = array_values(array_intersect($window->skipped, $years));

            foreach ($skipped as $i => $year) {
                $replacements[$row->id][$year] = $extra[$i] ?? null;
            }
        }

        return $replacements;
    }

    public function original(DpcProposal $dpcProposal, ImportBatch $importBatch): StreamedResponse
    {
        $this->authorize('viewRecords', $dpcProposal);
        abort_unless(
            $importBatch->dpc_proposal_id === $dpcProposal->id
                && $importBatch->status === ImportBatchStatus::Committed
                && in_array($importBatch->template, self::TEMPLATES, true),
            404,
        );

        $disk = Storage::disk((string) config('dpc.imports.disk'));
        abort_unless($disk->exists($importBatch->stored_path), 404);

        return $disk->download($importBatch->stored_path, $importBatch->original_filename ?: basename($importBatch->stored_path));
    }

    /**
     * @return array<string, ImportBatch|null> template value => its latest committed batch
     */
    private function originals(DpcProposal $proposal): array
    {
        $originals = [];

        foreach (self::TEMPLATES as $template) {
            $originals[$template->value] = ImportBatch::query()
                ->where('dpc_proposal_id', $proposal->id)
                ->where('template', $template->value)
                ->where('status', ImportBatchStatus::Committed->value)
                ->latest('committed_at')
                ->latest('id')
                ->first();
        }

        return $originals;
    }
}
