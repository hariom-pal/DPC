<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Models\DpcEvaluationDocument;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Requests\SaveEmployeeBasicRequest;
use App\Domain\Dpc\Requests\SaveEmployeeCrYearRequest;
use App\Domain\Dpc\Requests\SaveEmployeePhRequest;
use App\Domain\Dpc\Requests\SaveEmployeePropertyYearRequest;
use App\Domain\Dpc\Requests\UploadEmployeeDocumentRequest;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Dpc\Services\PromotionRuleCheck;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Services\PromotionCrRules;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee-wise details inside the DPC proposal — "Complete Details" for each officer
 * on the Seniority List (Coder Instructions, points 2 and 3, rule 4.1).
 *
 * The Authorized Officer who owns the proposal does all of it: every write is gated
 * on `update` for the proposal, which is the Authorized Officer of the proposal's own
 * department while the proposal is a Draft or Returned. Reading is gated on `view`,
 * so the Administrative Head of the same department can review it. No other role and
 * no employee login reaches these screens.
 *
 * Each section saves on its own form, so one section's validation error never loses
 * another section's work, and whatever has been saved is there when the officer comes
 * back — the draft is the saved state.
 */
class DpcEmployeeDetailsController extends Controller
{
    public function __construct(private readonly EmployeeDetailsService $details) {}

    /**
     * Every officer on the list, with what is still pending for each.
     */
    public function index(DpcProposal $dpcProposal): View
    {
        $this->authorize('view', $dpcProposal);

        $statuses = $this->details->statuses($dpcProposal);
        $ruleCheck = app(PromotionRuleCheck::class);

        return view('dpc-proposals.employees.index', [
            'proposal' => $dpcProposal,
            'statuses' => $statuses,
            'completeCount' => $statuses->filter(fn (array $entry): bool => $entry['status']['complete'])->count(),
            'phQuota' => $this->details->phQuota($dpcProposal),
            // Each candidate against the Class Master promotion rule.
            'ruleChecks' => $statuses->mapWithKeys(fn (array $entry): array => [
                $entry['row']->id => $ruleCheck->check($entry['row'], $dpcProposal),
            ])->all(),
            'canEdit' => request()->user()?->can('update', $dpcProposal) ?? false,
        ]);
    }

    public function show(DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): View
    {
        $this->authorize('view', $dpcProposal);
        $this->assertRowBelongsToProposal($dpcProposal, $evaluationRow);

        $evaluationRow->load([
            'crGrades', 'propertyFlags', 'recordGaps.reason', 'reservationCategory',
            'documents.uploader', 'currentDocuments',
        ]);

        $statuses = $this->details->statuses($dpcProposal);
        $position = $statuses->search(fn (array $entry): bool => $entry['row']->id === $evaluationRow->id);

        // "Complete one employee and move to the next": the next officer still
        // pending, after this one and then from the top.
        $next = $statuses->slice((int) $position + 1)->first(fn (array $entry): bool => ! $entry['status']['complete'])
            ?? $statuses->first(fn (array $entry): bool => ! $entry['status']['complete'] && $entry['row']->id !== $evaluationRow->id);

        return view('dpc-proposals.employees.show', [
            'proposal' => $dpcProposal,
            'row' => $evaluationRow,
            'status' => $this->details->status($evaluationRow),
            'previousRow' => $position > 0 ? $statuses->get($position - 1)['row'] : null,
            'nextRow' => $statuses->get((int) $position + 1)['row'] ?? null,
            'nextPending' => $next['row'] ?? null,
            'canEdit' => request()->user()?->can('update', $dpcProposal) ?? false,
            'categories' => ReservationCategory::query()->where('status', MasterStatus::Active->value)->orderBy('code')->get(),
            'crGrades' => (array) config('dpc.evaluation.cr_grades'),
            // Points of each grade (Eligible CR Grades, rule 3.4) for the DPC year.
            'gradePoints' => app(PromotionCrRules::class)->pointsForYear((int) ($dpcProposal->dpc_year ?: $dpcProposal->proposal_date->year)),
            'ruleCheck' => app(PromotionRuleCheck::class)->check($evaluationRow, $dpcProposal),
            'maxReports' => (int) config('dpc.employee_details.max_crs_per_year', 4),
            'reasons' => RecordReason::query()->selectable()->get(),
            'requiredCrCount' => (int) config('dpc.merit.required_cr_count', 5),
            'propertyYearsRequired' => (int) config('dpc.merit.property_return_years', 5),
            'latestYear' => (int) ($dpcProposal->dpc_year ?: $dpcProposal->proposal_date->year),
            'earlierPh' => $this->details->earlierPhRecords($evaluationRow),
        ]);
    }

    public function updateBasic(SaveEmployeeBasicRequest $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): RedirectResponse
    {
        return $this->write($request, $dpcProposal, $evaluationRow, 'basic', function () use ($request, $evaluationRow): void {
            $this->details->saveBasic($evaluationRow, $request->validated(), $request->user());
        });
    }

    public function saveCrYear(SaveEmployeeCrYearRequest $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): RedirectResponse
    {
        return $this->write($request, $dpcProposal, $evaluationRow, 'cr', function () use ($request, $evaluationRow): void {
            $this->details->saveCrYear($evaluationRow, $request->integer('year'), $request->validated(), $request->user());
        });
    }

    public function removeCrYear(Request $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow, int $year): RedirectResponse
    {
        return $this->write($request, $dpcProposal, $evaluationRow, 'cr', function () use ($request, $evaluationRow, $year): void {
            $this->details->removeCrYear($evaluationRow, $year, $request->user());
        });
    }

    public function savePropertyYear(SaveEmployeePropertyYearRequest $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): RedirectResponse
    {
        return $this->write($request, $dpcProposal, $evaluationRow, 'property', function () use ($request, $evaluationRow): void {
            $this->details->savePropertyYear($evaluationRow, $request->integer('year'), $request->validated(), $request->user());
        });
    }

    public function removePropertyYear(Request $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow, int $year): RedirectResponse
    {
        return $this->write($request, $dpcProposal, $evaluationRow, 'property', function () use ($request, $evaluationRow, $year): void {
            $this->details->removePropertyYear($evaluationRow, $year, $request->user());
        });
    }

    public function savePh(SaveEmployeePhRequest $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): RedirectResponse
    {
        return $this->write($request, $dpcProposal, $evaluationRow, 'ph', function () use ($request, $evaluationRow): void {
            $this->details->savePh($evaluationRow, $request->validated(), $request->user());
        });
    }

    public function uploadDocument(UploadEmployeeDocumentRequest $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): RedirectResponse
    {
        $kind = EvaluationDocumentKind::from((string) $request->validated('kind'));
        $section = match ($kind) {
            EvaluationDocumentKind::CrReport, EvaluationDocumentKind::CrWithoutGrading,
            EvaluationDocumentKind::CrNrc, EvaluationDocumentKind::CrChannel => 'cr',
            EvaluationDocumentKind::PropertyReturn, EvaluationDocumentKind::PropertyLateDecision => 'property',
            EvaluationDocumentKind::PhUndertaking => 'ph',
            EvaluationDocumentKind::Other => 'documents',
        };

        return $this->write($request, $dpcProposal, $evaluationRow, $section, function () use ($request, $evaluationRow, $kind): void {
            $this->details->upload(
                $evaluationRow,
                $kind,
                $request->filled('year') ? $request->integer('year') : null,
                $request->file('document'),
                $request->boolean('undertaking'),
                $request->input('remarks'),
                $request->user(),
            );
        });
    }

    /**
     * Streamed from the private disk, to whoever may view the proposal.
     */
    public function download(DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow, DpcEvaluationDocument $document): StreamedResponse
    {
        $this->authorize('view', $dpcProposal);
        $this->assertRowBelongsToProposal($dpcProposal, $evaluationRow);
        abort_unless($document->dpc_evaluation_row_id === $evaluationRow->id, 404);

        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404);

        return $disk->download($document->path, $document->original_name, ['Content-Type' => 'application/pdf']);
    }

    /**
     * One shape for every write: authorise, scope, run, and come back to the section
     * that was saved — with the service's refusal shown there if it refused.
     */
    private function write(Request $request, DpcProposal $proposal, DpcEvaluationRow $row, string $section, callable $action): RedirectResponse
    {
        $this->authorize('update', $proposal);
        $this->assertRowBelongsToProposal($proposal, $row);

        $back = redirect()->to(route('dpc-proposals.employees.show', [$proposal, $row]).'#section-'.$section);

        try {
            $action();
        } catch (WorkflowException $e) {
            return $back->withInput()->withErrors([$section => $e->getMessage()]);
        }

        return $back->with('status', __('dpc.employee_details.saved'));
    }

    private function assertRowBelongsToProposal(DpcProposal $proposal, DpcEvaluationRow $row): void
    {
        abort_unless($row->dpc_proposal_id === $proposal->id, 404);
    }
}
