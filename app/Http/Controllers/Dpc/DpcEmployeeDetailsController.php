<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Models\DpcEvaluationDocument;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Requests\SaveEmployeeBasicRequest;
use App\Domain\Dpc\Requests\SaveEmployeeCrYearRequest;
use App\Domain\Dpc\Requests\SaveEmployeeDeclarationsRequest;
use App\Domain\Dpc\Requests\SaveEmployeePhRequest;
use App\Domain\Dpc\Requests\SaveEmployeePropertyYearRequest;
use App\Domain\Dpc\Requests\SaveEmployeeVigilanceRequest;
use App\Domain\Dpc\Requests\UploadEmployeeDocumentRequest;
use App\Domain\Dpc\Requests\VerifyPhClaimRequest;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Dpc\Services\PromotionRuleCheck;
use App\Domain\Dpc\Services\RecordGapService;
use App\Domain\Master\Models\IntegrityOption;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\SealedCoverReason;
use App\Domain\Master\Services\PromotionCrRules;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
            'phApplies' => $this->details->phApplies($dpcProposal),
            // Each candidate against the Class Master promotion rule.
            'ruleChecks' => $statuses->mapWithKeys(fn (array $entry): array => [
                $entry['row']->id => $ruleCheck->check($entry['row'], $dpcProposal),
            ])->all(),
            // The AO, and while a committee return is open the AO for its sections (MoM point 3).
            'canEdit' => (request()->user()?->can('update', $dpcProposal) ?? false) || (request()->user()?->can('committeeResubmit', $dpcProposal) ?? false),
        ]);
    }

    public function show(DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): View
    {
        $this->authorize('view', $dpcProposal);
        $this->assertRowBelongsToProposal($dpcProposal, $evaluationRow);

        $evaluationRow->load([
            'crGrades.integrityOption', 'propertyFlags', 'recordGaps.reason', 'reservationCategory',
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
            // Submit & Next, then Complete & Submit on the last officer (2026-11-16).
            'pendingCount' => $statuses->reject(fn (array $entry): bool => $entry['status']['complete'])->count(),
            // Each section on its own: a committee return opens only the sections it names (MoM point 3).
            'canEditSection' => $canEditSection = collect(['basic', 'cr', 'property', 'ph', 'vigilance', 'declarations', 'documents'])
                ->mapWithKeys(fn (string $section): array => [$section => request()->user()?->can('updateEmployeeSection', [$dpcProposal, $section]) ?? false])
                ->all(),
            'canEdit' => in_array(true, $canEditSection, true),
            'committeeReturn' => $dpcProposal->committee_returned_at === null ? null : $dpcProposal->committee_return,
            // Rule 4.4: the Administrative Head verifies the documents of a submitted proposal.
            'canVerify' => request()->user()?->can('approve', $dpcProposal) ?? false,
            'categories' => ReservationCategory::query()->where('status', MasterStatus::Active->value)->orderBy('code')->get(),
            'crGrades' => (array) config('dpc.evaluation.cr_grades'),
            // Points of each grade (Eligible CR Grades, rule 3.4) for the DPC year.
            'gradePoints' => app(PromotionCrRules::class)->pointsForYear((int) ($dpcProposal->dpc_year ?: $dpcProposal->proposal_date->year)),
            'ruleCheck' => app(PromotionRuleCheck::class)->check($evaluationRow, $dpcProposal),
            'maxReports' => (int) config('dpc.employee_details.max_crs_per_year', 4),
            'reasons' => RecordReason::query()->selectable()->get(),
            // The years a missing Property Return needs its reason and document (2026-11-05).
            'propertyRuleYears' => $this->details->rulePropertyYears($evaluationRow),
            'sealedCoverReasons' => SealedCoverReason::query()->selectable()->get(),
            // 2026-11-14: Integrity beside the grade; the CR and Property Return year windows; the enquiry period.
            'integrityOptions' => IntegrityOption::query()->selectable()->get(),
            'crYearWindow' => $this->details->crYearWindow($evaluationRow),
            'propertyYearWindow' => $this->details->propertyYearWindow($evaluationRow),
            'enquiryPeriod' => $this->details->enquiryPeriod($dpcProposal),
            'requiredCrCount' => (int) config('dpc.merit.required_cr_count', 5),
            'propertyYearsRequired' => (int) config('dpc.merit.property_return_years', 5),
            'phApplies' => $this->details->phApplies($dpcProposal),
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
            DB::transaction(function () use ($request, $evaluationRow): void {
                $this->details->saveCrYear($evaluationRow, $request->integer('year'), $request->validated(), $request->user(), $request->file('nrc'));
                if ($request->hasFile('cr_document')) {
                    $this->details->uploadCrWithYear($evaluationRow, $request->integer('year'), $request->file('cr_document'), $request->boolean('cr_undertaking'), $request->boolean('cr_verified'), $request->user());
                }
                // Each CR of a graded year, uploaded on its own (2026-11-16).
                foreach ((array) $request->file('cr_documents', []) as $index => $file) {
                    if ($file instanceof UploadedFile && $request->boolean('has_grading') && (int) $index < max(1, $request->integer('cr_count'))) {
                        $this->details->uploadCrWithYear($evaluationRow, $request->integer('year'), $file, $request->boolean('cr_undertaking'), $request->boolean('cr_verified'), $request->user(), (int) $index + 1);
                    }
                }
                if ($request->hasFile('nrc')) {
                    $this->details->assertNrcHasItsCr($evaluationRow, $request->integer('year'));
                }
                // The document behind the Final Departmental View Regarding Integrity (2026-11-16).
                $saved = $evaluationRow->crGrades()->where('year', $request->integer('year'))->first();
                if ($request->hasFile('integrity_document') && $saved?->integrity_departmental_view !== null) {
                    $this->details->upload($evaluationRow, EvaluationDocumentKind::IntegrityDecision, $request->integer('year'), $request->file('integrity_document'), false, null, $request->user(), $request->boolean('cr_verified'));
                }
                $this->recordMissing($request, $evaluationRow, DpcEvaluationRecordGap::KIND_CR);
            });
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
            DB::transaction(function () use ($request, $evaluationRow): void {
                $this->details->savePropertyYear($evaluationRow, $request->integer('year'), $request->validated(), $request->user());
                if ($request->hasFile('property_document') && $request->boolean('submitted')) {
                    $this->details->upload($evaluationRow, EvaluationDocumentKind::PropertyReturn, $request->integer('year'), $request->file('property_document'), false, null, $request->user(), $request->boolean('property_verified'));
                }
                // The department's decision on a late return, with the year (2026-11-14).
                $saved = $evaluationRow->propertyFlags()->where('year', $request->integer('year'))->first();
                if ($request->hasFile('late_document') && $saved?->isLate() === true) {
                    $this->details->upload($evaluationRow, EvaluationDocumentKind::PropertyLateDecision, $request->integer('year'), $request->file('late_document'), false, null, $request->user(), $request->boolean('property_verified'));
                }
                $this->recordMissing($request, $evaluationRow, DpcEvaluationRecordGap::KIND_PROPERTY);
            });
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

    public function saveVigilance(SaveEmployeeVigilanceRequest $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): RedirectResponse
    {
        return $this->write($request, $dpcProposal, $evaluationRow, 'vigilance', function () use ($request, $evaluationRow): void {
            DB::transaction(function () use ($request, $evaluationRow): void {
                $this->details->saveVigilance($evaluationRow, $request->validated(), $request->user());
                // A Yes answer's document, uploaded with it; a No asks none (2026-11-16).
                foreach (['enquiry_ongoing' => ['enquiry_document', EvaluationDocumentKind::EnquiryDocument], 'punishment_effective' => ['punishment_document', EvaluationDocumentKind::PunishmentDocument]] as $answer => [$field, $kind]) {
                    if ($request->boolean($answer) && $request->hasFile($field)) {
                        $this->details->upload($evaluationRow, $kind, null, $request->file($field), false, null, $request->user(), $request->boolean('vigilance_verified'));
                    }
                }
            });
        });
    }

    public function saveDeclarations(SaveEmployeeDeclarationsRequest $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): RedirectResponse
    {
        return $this->write($request, $dpcProposal, $evaluationRow, 'declarations', function () use ($request, $evaluationRow): void {
            $this->details->saveDeclarations($evaluationRow, $request->validated(), $request->user());
        });
    }

    public function uploadDocument(UploadEmployeeDocumentRequest $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): RedirectResponse
    {
        $kind = EvaluationDocumentKind::from((string) $request->validated('kind'));
        $section = $kind->section();

        return $this->write($request, $dpcProposal, $evaluationRow, $section, function () use ($request, $evaluationRow, $kind): void {
            $this->details->upload(
                $evaluationRow,
                $kind,
                $request->filled('year') ? $request->integer('year') : null,
                $request->file('document'),
                $request->boolean('undertaking'),
                $request->input('remarks'),
                $request->user(),
                $request->boolean('verified'),
            );
        });
    }

    /**
     * Rule 2.1: the Administrative Head verifies, or does not, a PH claim.
     */
    public function verifyPh(VerifyPhClaimRequest $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): RedirectResponse
    {
        $this->assertRowBelongsToProposal($dpcProposal, $evaluationRow);

        try {
            $this->details->verifyPhClaim($evaluationRow, $request->validated('decision') === 'verified', $request->validated('remarks'), $request->user());
        } catch (WorkflowException $e) {
            return back()->withErrors(['ph' => $e->getMessage()]);
        }

        return redirect()->to(route('dpc-proposals.employees.show', [$dpcProposal, $evaluationRow]).'#section-ph')
            ->with('status', __('dpc.employee_details.ph.claim_decided'));
    }

    /**
     * Rule 4.4's second verification, by the Administrative Head of a submitted
     * proposal: one document, or every current document of the officer.
     */
    public function verifyDocument(Request $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow, ?DpcEvaluationDocument $document = null): RedirectResponse
    {
        $this->authorize('approve', $dpcProposal);
        $this->assertRowBelongsToProposal($dpcProposal, $evaluationRow);

        $documents = $document !== null
            ? collect([$document])
            : $evaluationRow->currentDocuments()->whereNull('ah_verified_at')->get();

        foreach ($documents as $item) {
            abort_unless($item->dpc_evaluation_row_id === $evaluationRow->id, 404);
            $this->details->verifyByHead($item, $request->user());
        }

        return redirect()->to(route('dpc-proposals.employees.show', [$dpcProposal, $evaluationRow]).'#section-documents')
            ->with('status', __('dpc.employee_details.verification.verified'));
    }

    /**
     * Every officer's current documents, category by category (2026-11-16), for the
     * Administrative Head's verification (rule 4.4) in one place.
     */
    public function documentVerification(DpcProposal $dpcProposal): View
    {
        $this->authorize('view', $dpcProposal);

        $documents = DpcEvaluationDocument::query()
            ->where('dpc_proposal_id', $dpcProposal->id)
            ->current()
            ->with(['row', 'uploader'])
            ->get()
            ->filter(fn (DpcEvaluationDocument $document): bool => $document->row !== null);

        $categories = collect(EvaluationDocumentKind::cases())
            ->mapWithKeys(fn (EvaluationDocumentKind $kind): array => [$kind->value => $documents
                ->filter(fn (DpcEvaluationDocument $document): bool => $document->kind === $kind)
                ->sortBy(fn (DpcEvaluationDocument $document): array => [(int) $document->row?->sn, (int) $document->year, (int) $document->part])
                ->values()])
            ->filter(fn ($items): bool => $items->isNotEmpty());

        return view('dpc-proposals.document-verification', [
            'proposal' => $dpcProposal,
            'categories' => $categories,
            'pending' => $documents->whereNull('ah_verified_at')->count(),
            'total' => $documents->count(),
            'canVerify' => request()->user()?->can('approve', $dpcProposal) ?? false,
        ]);
    }

    /**
     * The Administrative Head's verification (rule 4.4) from the category view
     * (2026-11-16): every document, one category, or one document.
     */
    public function verifyDocuments(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('approve', $dpcProposal);

        $data = $request->validate([
            'kind' => ['nullable', 'string', Rule::in(array_map(fn (EvaluationDocumentKind $kind): string => $kind->value, EvaluationDocumentKind::cases()))],
            'document' => ['nullable', 'string', 'uuid'],
        ]);

        $documents = DpcEvaluationDocument::query()
            ->where('dpc_proposal_id', $dpcProposal->id)
            ->current()
            ->whereNull('ah_verified_at')
            ->when($data['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->when($data['document'] ?? null, fn ($query, $uuid) => $query->where('uuid', $uuid))
            ->get();

        foreach ($documents as $document) {
            $this->details->verifyByHead($document, $request->user());
        }

        $anchor = isset($data['kind']) ? '#category-'.$data['kind'] : '';

        return redirect()->to(route('dpc-proposals.document-verification', $dpcProposal).$anchor)
            ->with('status', __('dpc.document_verification.verified', ['count' => $documents->count()]));
    }

    /**
     * Streamed from the private disk, to whoever may view the proposal — inline, so
     * the document's name opens it (MoM of 12 November 2026).
     */
    public function download(DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow, DpcEvaluationDocument $document): StreamedResponse
    {
        $this->authorize('view', $dpcProposal);
        $this->assertRowBelongsToProposal($dpcProposal, $evaluationRow);
        abort_unless($document->dpc_evaluation_row_id === $evaluationRow->id, 404);

        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404);

        return $disk->response($document->path, $document->original_name, ['Content-Type' => 'application/pdf'], 'inline');
    }

    /**
     * One shape for every write: authorise, scope, run, and come back to the section
     * that was saved — with the service's refusal shown there if it refused.
     */
    private function write(Request $request, DpcProposal $proposal, DpcEvaluationRow $row, string $section, callable $action): RedirectResponse
    {
        // Only the sections a committee return names, while it is open (MoM point 3).
        $this->authorize('updateEmployeeSection', [$proposal, $section]);
        $this->assertRowBelongsToProposal($proposal, $row);

        $back = redirect()->to(route('dpc-proposals.employees.show', [$proposal, $row]).'#section-'.$section);

        try {
            $action();
        } catch (WorkflowException $e) {
            return $back->withInput()->withErrors([$section => $e->getMessage()]);
        }

        // "Save & Next" (2026-11-14): saved, the officer lands on the next section.
        $next = (string) $request->input('_next', '');
        if (in_array($next, ['basic', 'cr', 'property', 'ph', 'vigilance', 'declarations', 'documents'], true)) {
            $back = redirect()->to(route('dpc-proposals.employees.show', [$proposal, $row]).'#section-'.$next);
        }

        return $back->with('status', __('dpc.employee_details.saved'));
    }

    /**
     * The reason and document given with a record marked Missing (2026-11-05),
     * recorded as the existing missing-record justification.
     */
    private function recordMissing(Request $request, DpcEvaluationRow $row, string $kind): void
    {
        $reason = RecordReason::query()->find($request->input('missing_reason_id'));
        $document = $request->file('missing_document');

        // Every reason comes with its document, the appointment letter included (2026-11-16).
        if ($reason === null || ! $reason->appliesTo($kind) || ! $document instanceof UploadedFile) {
            return;
        }

        $second = $request->file('missing_second_document');

        app(RecordGapService::class)->record(
            $row, $kind, $request->integer('year'), $reason,
            $request->filled('missing_remarks') ? (string) $request->input('missing_remarks') : null,
            $document, $request->user(), $second instanceof UploadedFile ? $second : null,
        );
    }

    private function assertRowBelongsToProposal(DpcProposal $proposal, DpcEvaluationRow $row): void
    {
        abort_unless($row->dpc_proposal_id === $proposal->id, 404);
    }
}
