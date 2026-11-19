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
        $proposal->load(['evaluationRows' => fn ($query) => $query->orderBy('sn'), 'evaluationRows.reservationCategory', 'evaluationRows.crGrades.integrityOption', 'evaluationRows.propertyFlags', 'evaluationRows.currentDocuments']);
        $dpcYear = (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);

        // As many as the promotion rule counts (five without a rule), 2026-11-18.
        return range($dpcYear - 1, $dpcYear - $this->details->crSlots($proposal));
    }

    /**
     * Each officer's CR years in the order their CR window takes them (2026-11-19): the
     * years counted, a middle year not counted (missing, or no grading) in its own
     * column, and the older year counted in its place after — so a row with a skipped
     * year runs one column longer. As the rule check and the calculation take it.
     *
     * @param  list<int>  $years  the rule's CR years, latest first
     * @return array{crColumns: int, crCells: array<int, list<array{year: int, state: 'counted'|'skipped'|'replacement'}>>}
     */
    private function crCells(DpcProposal $proposal, array $years): array
    {
        $slots = count($years);
        $oldest = $years === [] ? 0 : min($years);
        $cells = [];

        foreach ($proposal->evaluationRows as $row) {
            $row->setRelation('proposal', $proposal);
            $window = $this->details->crWindow($row, $row->crGrades, $slots);

            $cells[$row->id] = array_map(fn (int $year): array => [
                'year' => $year,
                'state' => match (true) {
                    in_array($year, $window->skipped, true) => 'skipped',
                    $year < $oldest => 'replacement',
                    default => 'counted',
                },
            ], $window->years);
        }

        return [
            'crColumns' => max($slots, ...array_map('count', $cells ?: [[]])),
            'crCells' => $cells,
        ];
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
