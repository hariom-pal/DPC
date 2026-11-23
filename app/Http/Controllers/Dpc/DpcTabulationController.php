<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Dpc\Exports\TabulationChartExport;
use App\Domain\Dpc\Models\DpcEvaluationRow;
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
            'propertyYears' => $years,
            ...$this->crCells($dpcProposal, $years),
        ]);
    }

    /** The chart as an Excel workbook (2026-11-18), for the same readers as the screen. */
    public function download(DpcProposal $dpcProposal): BinaryFileResponse
    {
        $this->authorize('viewRecords', $dpcProposal);
        $years = $this->prepare($dpcProposal);
        $name = 'tabulation-chart-'.Str::slug((string) ($dpcProposal->dpc_code ?: $dpcProposal->id)).'-'.now()->format('Ymd-His').'.xlsx';

        $cr = $this->crCells($dpcProposal, $years);

        return Excel::download(new TabulationChartExport($dpcProposal, $years, $cr['crColumns'], $cr['crCells']), $name);
    }

    /**
     * Load what the chart shows; the five CR (and Property File) years before the DPC's, latest first.
     *
     * @return list<int>
     */
    private function prepare(DpcProposal $proposal): array
    {
        $proposal->load(['evaluationRows' => fn ($query) => $query->orderBy('sn'), 'evaluationRows.reservationCategory', 'evaluationRows.crGrades.integrityOption', 'evaluationRows.propertyFlags', 'evaluationRows.currentDocuments', 'evaluationRows.recordGaps.reason']);
        $dpcYear = (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);

        // As many as the promotion rule counts (five without a rule), 2026-11-18.
        return range($dpcYear - 1, $dpcYear - $this->details->crSlots($proposal));
    }

    /**
     * Each officer's CR cells (2026-11-22): only the years counted that have a CR put up,
     * latest first, in as many columns as the rule's CR count — a year missing (or not
     * entered) is not shown, so an officer with three CRs found shows three. A year put
     * up without a grading shows only where it is counted (the last year); passed over,
     * it goes with the missing years behind "View missing CR". The older year counted in
     * place of a skipped one stays yellow.
     *
     * @param  list<int>  $years  the rule's CR years, latest first
     * @return array{crColumns: int, crCells: array<int, list<array{year: int, state: 'counted'|'replacement'}>>, missingCr: array<int, int>}
     */
    private function crCells(DpcProposal $proposal, array $years): array
    {
        $slots = count($years);
        $oldest = $years === [] ? 0 : min($years);
        $cells = [];
        $missing = [];

        foreach ($proposal->evaluationRows as $row) {
            $row->setRelation('proposal', $proposal);
            $window = $this->details->crWindow($row, $row->crGrades, $slots);

            $shown = array_values(array_filter($window->counted, function (int $year) use ($row): bool {
                $cr = $row->crGrades->firstWhere('year', $year);

                return $cr !== null && $cr->put_up !== false;
            }));

            $cells[$row->id] = array_map(fn (int $year): array => [
                'year' => $year,
                'state' => $year < $oldest ? 'replacement' : 'counted',
            ], $shown);
            $missing[$row->id] = count($this->details->missingCrYears($row, $slots));
        }

        return [
            'crColumns' => max(1, $slots),
            'crCells' => $cells,
            'missingCr' => $missing,
        ];
    }

    /**
     * One officer's missing CR years and the reason recorded for each (2026-11-22), from
     * the Tabulation Chart's "View missing CR" link, in a tab of its own.
     */
    public function missingCr(DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): View
    {
        $this->authorize('viewRecords', $dpcProposal);
        $this->prepare($dpcProposal);
        $evaluationRow->setRelation('proposal', $dpcProposal);
        $evaluationRow->loadMissing(['crGrades', 'recordGaps.reason']);

        return view('dpc-proposals.tabulation-missing-cr', [
            'proposal' => $dpcProposal,
            'row' => $evaluationRow,
            'missing' => $this->details->missingCrYears($evaluationRow),
        ]);
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
