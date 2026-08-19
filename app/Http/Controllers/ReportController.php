<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Report\Services\DpcReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports — M11.
 *
 * **One screen for every role, scoped rather than duplicated.** A department sees
 * its own DPCs; the Commission and the Admin see all of them. Two controllers
 * would mean two places for the scope to be got wrong, and the wrong answer here
 * discloses another department's promotion figures.
 *
 * Read-only. Nothing on this screen recalculates: the promotion figures come from
 * the calculation run each proceeding **pinned at approval**, so the report can
 * never disagree with the signed document it summarises.
 */
class ReportController extends Controller
{
    public function __construct(private readonly DpcReportService $reports) {}

    public function index(Request $request): View
    {
        $scope = $this->reports->scopeFor($request->user());

        return view('reports.index', [
            'scopedToDepartment' => $scope !== null,
            'departmentName' => $scope === null ? null : $request->user()->department?->name,
            'pipeline' => $this->reports->pipeline($scope),
            'byYear' => $this->reports->byYear($scope),
            'outcomes' => $this->reports->outcomes($scope),
            'variants' => $this->reports->variants($scope),
            'byDepartment' => $this->reports->byDepartment($scope),
        ]);
    }

    /**
     * The pipeline as CSV — the one table anybody asks for outside the screen.
     */
    public function export(Request $request): StreamedResponse
    {
        $scope = $this->reports->scopeFor($request->user());
        $pipeline = $this->reports->pipeline($scope);

        return response()->streamDownload(function () use ($pipeline): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['Stage', 'PPT reference', 'Count']);

            foreach ($pipeline as $row) {
                fputcsv($handle, [$row['stage'], $row['reference'], $row['count']]);
            }

            fclose($handle);
        }, 'dpc-pipeline-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
