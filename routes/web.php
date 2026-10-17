<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditTrailController;
use App\Http\Controllers\Admin\CgpscChairmanController;
use App\Http\Controllers\Admin\ClassMasterController;
use App\Http\Controllers\Admin\CommitteeConfigurationController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DepartmentPostController;
use App\Http\Controllers\Admin\DesignationController;
use App\Http\Controllers\Admin\DistrictController;
use App\Http\Controllers\Admin\DpcRuleController;
use App\Http\Controllers\Admin\DpcTypeController;
use App\Http\Controllers\Admin\IntegrationStatusController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\PayScaleController;
use App\Http\Controllers\Admin\PortalNoticeController;
use App\Http\Controllers\Admin\PreDpcLinkController;
use App\Http\Controllers\Admin\QualificationController;
use App\Http\Controllers\Admin\RecordReasonController;
use App\Http\Controllers\Admin\ReservationCategoryController;
use App\Http\Controllers\Admin\ReservationPolicyController;
use App\Http\Controllers\Admin\ReservationRosterController;
use App\Http\Controllers\Admin\ReservationRosterPointController;
use App\Http\Controllers\Admin\ReviewReasonController;
use App\Http\Controllers\Admin\UserAssignmentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VacancyMasterController;
use App\Http\Controllers\Admin\VacancyTypeController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\RoleSwitchController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\Candidate\CandidateDpcController;
use App\Http\Controllers\Cgpsc\AllotmentController;
use App\Http\Controllers\Cgpsc\CirculationConsentController;
use App\Http\Controllers\Cgpsc\CirculationDocumentController;
use App\Http\Controllers\Cgpsc\ProceedingController;
use App\Http\Controllers\Cgpsc\ProceedingCorrectionController;
use App\Http\Controllers\Cgpsc\ProceedingDocumentController;
use App\Http\Controllers\Cgpsc\ProceedingHoldController;
use App\Http\Controllers\Cgpsc\ProceedingResultController;
use App\Http\Controllers\Cgpsc\SchedulingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dpc\CirculationController;
use App\Http\Controllers\Dpc\DpcCalculationController;
use App\Http\Controllers\Dpc\DpcCommitteeMemberController;
use App\Http\Controllers\Dpc\DpcEmployeeDetailsController;
use App\Http\Controllers\Dpc\DpcImportController;
use App\Http\Controllers\Dpc\DpcProposalController;
use App\Http\Controllers\Dpc\DpcProposalVerificationController;
use App\Http\Controllers\Dpc\DpcRecordGapController;
use App\Http\Controllers\Dpc\ImportTemplateController;
use App\Http\Controllers\Dpc\ReviewProposalController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PreDpc\PreDpcProposalController;
use App\Http\Controllers\Proceeding\CommitteeSittingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SelfInspection\SelfInspectionController;
use App\Http\Middleware\EnsureCanWorkAnAllotment;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'))->name('home');

Route::get('/health', HealthController::class)->name('health');

Route::get('/lang/{locale}', function (string $locale) {
    if (in_array($locale, config('app.supported_locales', ['en']), true)) {
        session(['locale' => $locale]);
    }

    return redirect()->back();
})->name('lang.switch');

/*
 | Authentication & department registration (PPT S5–S6)
 */
Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');

Route::get('/register', [RegistrationController::class, 'create'])->name('register');
Route::post('/register', [RegistrationController::class, 'store'])->name('register.store');

Route::get('/verify', [VerificationController::class, 'show'])->name('verify.show');
Route::post('/verify', [VerificationController::class, 'store'])->middleware('throttle:otp-verify')->name('verify.store');

/*
 | Self-Inspection & Assessment (PPT S49–S51) — M10.
 |
 | Deliberately outside `auth`. An individual under consideration has no account
 | in this system: they exist as a row in the officer chart their department
 | filed, which is why S50 identifies them by facts rather than by credentials
 | (BD-19, expressed as `dpc.self_inspection.authentication.driver`).
 |
 | S51 has two buttons — CLOSE and PRINT — and no objection route, because the
 | deck has none: it sends the officer to the department in person and states the
 | Commission will consider no representation (BD-20).
 */
Route::prefix('self-inspection')->name('self-inspection.')->group(function (): void {
    Route::get('/', [SelfInspectionController::class, 'landing'])->name('landing');
    Route::get('/identify', [SelfInspectionController::class, 'create'])->name('identify');
    Route::post('/identify', [SelfInspectionController::class, 'store'])
        ->middleware('throttle:self-inspection')->name('identify.store');
    Route::get('/record', [SelfInspectionController::class, 'show'])->name('show');
    Route::get('/record/print', [SelfInspectionController::class, 'print'])->name('print');
    Route::post('/close', [SelfInspectionController::class, 'close'])->name('close');
});

/*
 | Authenticated area
 */
Route::middleware('auth')->group(function (): void {
    // Reachable even while a first-login password change is pending.
    Route::get('/password/change', [PasswordChangeController::class, 'show'])->name('password.change.show');
    Route::post('/password/change', [PasswordChangeController::class, 'update'])->name('password.change.update');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Application screens require the system-issued password to be changed.
    Route::middleware(EnsurePasswordChanged::class)->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        /*
         | The sitting, from a committee member's side (business instruction,
         | 2026-09-07).
         |
         | **Outside the `/cgpsc` group on purpose.** Everything in there is guarded
         | on `role:chairman|member`, and a committee member holds neither — item 7
         | forbids granting a Commission role for sitting on one DPC's committee. So
         | these routes carry no role guard at all, and every action asks
         | `CommitteeSittingService::isSeatedOn()` instead: authorisation is a seat
         | on *this* proposal's committee, which is a relationship rather than an
         | office.
         |
         | `{dpcProposal}` and `{row}` bind by UUID like every other aggregate.
         */
        Route::prefix('sitting')->name('sitting.')->group(function (): void {
            Route::get('/', [CommitteeSittingController::class, 'index'])->name('index');
            Route::get('/{dpcProposal}', [CommitteeSittingController::class, 'show'])->name('show');
            Route::get('/{dpcProposal}/candidate/{row}', [CommitteeSittingController::class, 'candidate'])->name('candidate');
            Route::post('/{dpcProposal}/candidate/{row}/call', [CommitteeSittingController::class, 'call'])->name('call');
            Route::post('/{dpcProposal}/candidate/{row}/conclude', [CommitteeSittingController::class, 'conclude'])->name('conclude');
        });

        /*
         | Item 9 (2026-08-26): one account may hold several roles, and switches
         | between them.
         |
         | No `role:` middleware — every authenticated account may switch, and what
         | it may switch *to* is the set it has been granted, which
         | `ActiveRoleService::switchTo()` enforces. Gating this on a role would
         | mean the capacity you are currently in decided whether you could leave
         | it.
         |
         | A POST, so it cannot be triggered by following a link. See the
         | controller.
         */
        Route::post('/acting-as', RoleSwitchController::class)->name('acting-as');

        /*
         | In-app notifications (M11). Every role, no role middleware: the feed
         | contains only what was addressed to the signed-in user, and ownership
         | is enforced in the service rather than by route scoping.
         */
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

        /*
         | Reports (M11). One screen for every role, scoped by the service rather
         | than split into a departmental and a Commission copy — two copies is
         | two places for the scope to be got wrong, and the wrong answer here
         | discloses another department's promotion figures.
         */
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

        /*
         | Blank upload templates for S16–S18 (M11).
         |
         | Its own screen rather than a button beside each upload control, because
         | those screens are M4's and M4 is architecture-frozen — putting it there
         | needs a Freeze Exception under AD-22. The capability ships now; moving
         | the button is two lines once the exception is granted.
         */
        Route::middleware('role:'.Role::AuthorizedOfficer->value.'|'.Role::AdministrativeHead->value)
            ->group(function (): void {
                Route::get('/dpc-templates', [ImportTemplateController::class, 'index'])
                    ->name('dpc-import-templates.index');
                Route::get('/dpc-templates/{template}', [ImportTemplateController::class, 'download'])
                    ->name('dpc-import-templates.download');

                /*
                 | The same template, for **one DPC** (business instruction,
                 | 2026-09-06: *"the person downloading it belongs to a department —
                 | they should not type that department's post, it should already be
                 | in the CSV"*).
                 |
                 | The two post columns are constant across an S16 or S17 file — every
                 | officer on it is being considered for the same promotion — so the
                 | worked example carries this proposal's own pair rather than a
                 | generic one. Scoped by the proposal's policy, unlike the blank
                 | template above, because it discloses that DPC's posts.
                 */
                Route::get('/{dpcProposal}/dpc-template/{template}', [ImportTemplateController::class, 'downloadFor'])
                    ->name('dpc-import-templates.download-for');
            });

        /*
         | The Promotion Candidate's own DPC (change item 17).
         |
         | **Two routes, and that is the whole surface.** The register lists exactly
         | what a candidate may see — own DPC, own status, timeline, notifications,
         | the published result — and forbids everything else by name. Notifications
         | are the shared screen every role uses; the rest is these two.
         |
         | `{dpcProposal}` is deliberately **not** route-model-bound. Binding would
         | resolve any proposal by UUID and leave the scoping to a policy; the
         | controller instead resolves it only from the set of proposals the
         | candidate is named on, so an unrelated UUID is a 404 rather than a
         | guarded 403.
         */
        Route::middleware('role:'.Role::PromotionCandidate->value)
            ->prefix('my-dpc')
            ->name('my-dpc.')
            ->group(function (): void {
                Route::get('/', [CandidateDpcController::class, 'index'])->name('index');
                Route::get('/{dpcProposal}', [CandidateDpcController::class, 'show'])->name('show');
            });

        // Pre-DPC Proposal workflow (department Authorized Officer / Administrative Head).
        Route::middleware('role:'.Role::AuthorizedOfficer->value.'|'.Role::AdministrativeHead->value)
            ->prefix('pre-dpc')
            ->name('pre-dpc.')
            ->group(function (): void {
                Route::get('/', [PreDpcProposalController::class, 'index'])->name('index');

                /*
                 | Auto-population (change item 5): the Pay Scale, Class and
                 | Promotion Duration a chosen pair of posts determines.
                 |
                 | Declared before `/{preDpc}` so `post-attributes` is never
                 | mistaken for a proposal identifier. Read-only, scoped to the
                 | signed-in officer's own department by the resolver.
                 */
                Route::get('/post-attributes', [PreDpcProposalController::class, 'derivedAttributes'])
                    ->name('post-attributes');

                Route::get('/create', [PreDpcProposalController::class, 'create'])->name('create');
                Route::post('/', [PreDpcProposalController::class, 'store'])->name('store');
                Route::get('/{preDpc}', [PreDpcProposalController::class, 'show'])->name('show');
                Route::get('/{preDpc}/edit', [PreDpcProposalController::class, 'edit'])->name('edit');
                Route::put('/{preDpc}', [PreDpcProposalController::class, 'update'])->name('update');
                Route::post('/{preDpc}/submit', [PreDpcProposalController::class, 'submit'])->name('submit');
                Route::post('/{preDpc}/approve', [PreDpcProposalController::class, 'approve'])->name('approve');
                Route::post('/{preDpc}/return', [PreDpcProposalController::class, 'return'])->name('return');
                Route::post('/{preDpc}/cancel', [PreDpcProposalController::class, 'cancel'])->name('cancel');
                Route::post('/{preDpc}/amend', [PreDpcProposalController::class, 'amend'])->name('amend');

                /*
                 | Discarding a draft (business instruction, 2026-09-06).
                 |
                 | `DELETE`, and soft: `PreDpcProposalService::delete()` sets
                 | `deleted_at` and leaves the row. Guarded by
                 | `PreDpcProposalPolicy::delete()`, which permits it only while the
                 | proposal is a Draft — a returned or approved one has been seen by
                 | somebody, and `cancel` is its ending.
                 */
                Route::delete('/{preDpc}', [PreDpcProposalController::class, 'destroy'])->name('destroy');
            });

        // DPC Proposal workflow (M4 Phase 1: post selection + submit/approve/return/cancel).
        Route::middleware('role:'.Role::AuthorizedOfficer->value.'|'.Role::AdministrativeHead->value)
            ->prefix('dpc-proposals')
            ->name('dpc-proposals.')
            ->group(function (): void {
                Route::get('/', [DpcProposalController::class, 'index'])->name('index');
                Route::get('/create', [DpcProposalController::class, 'create'])->name('create');

                // Review DPC (M8 Phase 1, PPT S35/S37). Declared before the
                // `{dpcProposal}` routes so `reviews` is never mistaken for a
                // proposal identifier.
                Route::get('/reviews/create', [ReviewProposalController::class, 'create'])->name('reviews.create');
                Route::post('/reviews', [ReviewProposalController::class, 'store'])->name('reviews.store');
                Route::post('/', [DpcProposalController::class, 'store'])->name('store');
                Route::get('/{dpcProposal}', [DpcProposalController::class, 'show'])->name('show');
                // The complete correction chain (architect Directive 4).
                Route::get('/{dpcProposal}/review-chain', [ReviewProposalController::class, 'chain'])->name('reviews.chain');
                Route::get('/{dpcProposal}/edit', [DpcProposalController::class, 'edit'])->name('edit');
                Route::put('/{dpcProposal}', [DpcProposalController::class, 'update'])->name('update');
                Route::get('/{dpcProposal}/committee', [DpcProposalController::class, 'committee'])->name('committee');

                /*
                 | Item 6's lookup. Declared before the `PUT` only for legibility —
                 | they differ by method — but kept beside it so the two halves of
                 | the committee screen are read together.
                 */
                Route::get(
                    '/{dpcProposal}/committee/search',
                    [DpcProposalController::class, 'searchCommitteeMembers'],
                )->name('committee.search');

                Route::put('/{dpcProposal}/committee', [DpcProposalController::class, 'updateCommittee'])->name('committee.update');
                Route::get('/{dpcProposal}/working-officers', [DpcProposalController::class, 'workingOfficers'])->name('working-officers');
                Route::put('/{dpcProposal}/working-officers', [DpcProposalController::class, 'updateWorkingOfficers'])->name('working-officers.update');
                Route::get('/{dpcProposal}/seniority-list', [DpcProposalController::class, 'seniorityList'])->name('seniority-list');
                Route::put('/{dpcProposal}/seniority-list', [DpcProposalController::class, 'updateSeniorityList'])->name('seniority-list.update');

                /*
                 | Suggestion 4. A candidate account has no mobile and no e-mail — a
                 | Seniority List carries neither — so a reset has no channel to send
                 | a code on. The department reissues instead, which is where
                 | credential handover already sits, and the guard is the proposal's
                 | own list rather than a role.
                 */
                Route::post(
                    '/{dpcProposal}/candidates/reissue-password',
                    [DpcProposalController::class, 'reissueCandidatePassword'],
                )->name('candidates.reissue-password');
                // AH review walkthrough (M4 Phase 7, PPT S21–S25) — ends in the single approval.
                Route::get('/{dpcProposal}/review/{step?}', [DpcProposalController::class, 'review'])
                    ->whereNumber('step')
                    ->name('review');
                Route::get('/{dpcProposal}/evaluation-chart', [DpcProposalController::class, 'evaluationChart'])->name('evaluation-chart');
                // The pre-filled S18 template: every officer the Seniority List
                // projected, with only the evaluation columns left blank
                // (business directive, 2026-08-22). Declared before the PUT so
                // the two read in the order an officer uses them.
                Route::get('/{dpcProposal}/evaluation-chart/template', [DpcProposalController::class, 'evaluationChartTemplate'])->name('evaluation-chart.template');
                Route::put('/{dpcProposal}/evaluation-chart', [DpcProposalController::class, 'updateEvaluationChart'])->name('evaluation-chart.update');
                /*
                 | Missing mandatory records for one officer (change register of
                 | 2026-08-27, items 14 to 19).
                 |
                 | Beside the Evaluation Chart rather than inside it: the reason and
                 | the PDF are a *justification for* a year rather than data in it,
                 | and a per-year file upload on the chart's single all-officers form
                 | would lose everybody's data on one bad file.
                 |
                 | The officer is addressed by their **seniority number**, scoped to
                 | the proposal — the chart's own identifier, so the URL reads as the
                 | S18 row it is, and `scopeBindings()` stops one proposal's URL
                 | reaching another's officer.
                 */
                Route::scopeBindings()->group(function (): void {
                    Route::get(
                        '/{dpcProposal}/officers/{evaluationRow:sn}/missing-records',
                        [DpcRecordGapController::class, 'index'],
                    )->name('record-gaps.index');
                    Route::post(
                        '/{dpcProposal}/officers/{evaluationRow:sn}/missing-records',
                        [DpcRecordGapController::class, 'store'],
                    )->name('record-gaps.store');
                    Route::get(
                        '/{dpcProposal}/officers/{evaluationRow:sn}/missing-records/{recordGap}/document',
                        [DpcRecordGapController::class, 'download'],
                    )->name('record-gaps.download');
                    Route::delete(
                        '/{dpcProposal}/officers/{evaluationRow:sn}/missing-records/{recordGap}',
                        [DpcRecordGapController::class, 'destroy'],
                    )->name('record-gaps.destroy');
                });
                /*
                 | Employee-wise details — "Complete Details" for each officer on the
                 | Seniority List (Coder Instructions, points 2 and 3, rule 4.1).
                 | Inside the proposal, addressed like the missing-records screen by
                 | the officer's seniority number, and scoped so one proposal's URL
                 | cannot reach another's officer. Writes are gated on `update`.
                 */
                Route::get('/{dpcProposal}/employees', [DpcEmployeeDetailsController::class, 'index'])
                    ->name('employees.index');
                Route::scopeBindings()->prefix('/{dpcProposal}/employees/{evaluationRow:sn}')->name('employees.')->group(function (): void {
                    Route::get('/', [DpcEmployeeDetailsController::class, 'show'])->name('show');
                    Route::put('/basic', [DpcEmployeeDetailsController::class, 'updateBasic'])->name('basic');
                    Route::post('/cr', [DpcEmployeeDetailsController::class, 'saveCrYear'])->name('cr.save');
                    Route::delete('/cr/{year}', [DpcEmployeeDetailsController::class, 'removeCrYear'])->whereNumber('year')->name('cr.remove');
                    Route::post('/property', [DpcEmployeeDetailsController::class, 'savePropertyYear'])->name('property.save');
                    Route::delete('/property/{year}', [DpcEmployeeDetailsController::class, 'removePropertyYear'])->whereNumber('year')->name('property.remove');
                    Route::put('/ph', [DpcEmployeeDetailsController::class, 'savePh'])->name('ph');
                    Route::post('/documents', [DpcEmployeeDetailsController::class, 'uploadDocument'])->name('documents.store');
                    Route::get('/documents/{document}', [DpcEmployeeDetailsController::class, 'download'])->name('documents.download');
                });
                /*
                 | Rule 1.1 — the department's pre-submission confirmations. A step of
                 | the proposal like S15–S18; the controller gates writes on `update`.
                 */
                Route::get('/{dpcProposal}/verification', [DpcProposalVerificationController::class, 'index'])
                    ->name('verification');
                Route::post('/{dpcProposal}/verification/{item}', [DpcProposalVerificationController::class, 'store'])
                    ->name('verification.store');
                Route::delete('/{dpcProposal}/verification/{item}', [DpcProposalVerificationController::class, 'destroy'])
                    ->name('verification.destroy');
                Route::get(
                    '/{dpcProposal}/verification/{verification}/minutes',
                    [DpcProposalVerificationController::class, 'download'],
                )->name('verification.minutes');
                // Excel import (M4 Phase 6): Preview -> Validation -> Error Report -> Commit.
                Route::post('/{dpcProposal}/imports', [DpcImportController::class, 'store'])->name('imports.store');
                Route::get('/{dpcProposal}/imports/{importBatch}', [DpcImportController::class, 'show'])->name('imports.show');
                Route::get('/{dpcProposal}/imports/{importBatch}/errors', [DpcImportController::class, 'errors'])->name('imports.errors');
                Route::post('/{dpcProposal}/imports/{importBatch}/commit', [DpcImportController::class, 'commit'])->name('imports.commit');
                Route::post('/{dpcProposal}/imports/{importBatch}/cancel', [DpcImportController::class, 'cancel'])->name('imports.cancel');

                // CALCULATE and calculation history (M5 Phase 4). Runs are
                // addressed by UUID only; officers within a run by their
                // chart serial number, never by a database id.
                // Throttled: a calculation writes permanent evidence that no
                // code path can delete, so the button must not be a way to
                // flood the evidence tables.
                Route::post('/{dpcProposal}/calculate', [DpcCalculationController::class, 'store'])
                    ->middleware('throttle:calculate')
                    ->name('calculate');
                Route::get('/{dpcProposal}/calculations', [DpcCalculationController::class, 'index'])->name('calculations.index');
                Route::get('/{dpcProposal}/calculations/{calculationRun}', [DpcCalculationController::class, 'show'])->name('calculations.show');
                Route::get('/{dpcProposal}/calculations/{calculationRun}/officers/{sn}', [DpcCalculationController::class, 'explain'])
                    ->whereNumber('sn')
                    ->name('calculations.explain');

                Route::post('/{dpcProposal}/submit', [DpcProposalController::class, 'submit'])->name('submit');
                Route::post('/{dpcProposal}/approve', [DpcProposalController::class, 'approve'])->name('approve');
                Route::post('/{dpcProposal}/return', [DpcProposalController::class, 'return'])->name('return');
                Route::post('/{dpcProposal}/cancel', [DpcProposalController::class, 'cancel'])->name('cancel');
            });

        // Circulation DPC — the department's side (M9 Phase 1, PPT S45/S47).
        //
        // Its own prefix, not a child of `dpc-proposals`: a Circulation is its
        // own aggregate and is not a proposal (AD-24). Nesting it under the
        // proposal routes would say the opposite in the one place a reader
        // looks first.
        Route::middleware('role:'.Role::AuthorizedOfficer->value.'|'.Role::AdministrativeHead->value)
            ->prefix('dpc-circulations')
            ->name('dpc-circulations.')
            ->group(function (): void {
                Route::get('/', [CirculationController::class, 'index'])->name('index');
                // Declared before `{dpcCirculation}` so `create` is never
                // mistaken for an identifier.
                Route::get('/create', [CirculationController::class, 'create'])->name('create');
                Route::post('/', [CirculationController::class, 'store'])->name('store');
                Route::get('/{dpcCirculation}', [CirculationController::class, 'show'])->name('show');
                Route::post('/{dpcCirculation}/submit', [CirculationController::class, 'submit'])->name('submit');
                Route::post('/{dpcCirculation}/approve', [CirculationController::class, 'approve'])->name('approve');
                Route::post('/{dpcCirculation}/return', [CirculationController::class, 'return'])->name('return');
                Route::post('/{dpcCirculation}/cancel', [CirculationController::class, 'cancel'])->name('cancel');
            });

        // CGPSC workflow — allotment and scheduling (M6, PPT S26–S30).
        /*
         | Member acceptance and scheduling (M6 Phase 2, PPT S29–S30).
         |
         | **A sibling of the CGPSC group rather than a child of it**, and the
         | difference is the middleware. Everything else under `cgpsc` is the
         | Commission's own work and is gated on `role:chairman|member`; these four
         | actions belong to *the allottee*, who since 2026-09-07 need not hold
         | either role — a DPC is allotted to a member of its own committee, and
         | committee membership is deliberately not an office.
         |
         | Nested inside the role group this could not be expressed, because group
         | middleware accumulates and a child cannot drop its parent's. Gated on the
         | role, the allottee was refused the only screens that can move their own
         | DPC, and it stopped dead.
         */
        Route::middleware(EnsureCanWorkAnAllotment::class)
            ->prefix('cgpsc')
            ->name('cgpsc.')
            ->group(function (): void {
                Route::get('/schedules', [SchedulingController::class, 'index'])->name('schedules.index');
                Route::post('/schedules/{dpcProposal}/accept', [SchedulingController::class, 'accept'])->name('schedules.accept');

                /*
                 | Declining (business instruction, 2026-09-06). The counterpart of
                 | accepting: the allottee refuses with a reason, the allotment
                 | leaves the current scope, and the DPC is back on the CGPSC
                 | Chairman's allotment screen to be given to somebody else.
                 */
                Route::post('/schedules/{dpcProposal}/decline', [SchedulingController::class, 'decline'])->name('schedules.decline');
                Route::post('/schedules/{dpcProposal}', [SchedulingController::class, 'store'])->name('schedules.store');
                Route::post('/schedules/{dpcProposal}/reschedule', [SchedulingController::class, 'reschedule'])->name('schedules.reschedule');
                Route::get('/schedules/{dpcProposal}/history', [SchedulingController::class, 'history'])->name('schedules.history');

                /*
                 | The sitting itself (M7, PPT S31–S34), and the same reasoning: it
                 | is conducted by **the allottee**, who need hold no role.
                 |
                 | `DpcProceedingPolicy` was already written this way and did not
                 | need changing for the part that matters — `view()` and `act()`
                 | both bind the individual (`conducted_by === $user->id`) and name
                 | no role at all, which is narrower than a role test rather than
                 | wider. Only `viewAny()` and `open()` asked for one, and only
                 | because at those two moments no proceeding exists yet to
                 | authorise against.
                 |
                 | **`approve`, `cancel` and the documents stay behind the role
                 | gate**, deliberately. That is where the result is approved and
                 | signed, and whether somebody holding no office may sign one is a
                 | business question rather than a defect — it is recorded as open
                 | and is not decided here.
                 */
                Route::get('/proceedings', [ProceedingController::class, 'index'])->name('proceedings.index');
                Route::post('/proceedings/{dpcProposal}/open', [ProceedingController::class, 'open'])->name('proceedings.open');
                Route::get('/proceedings/{proceeding}', [ProceedingController::class, 'show'])->name('proceedings.show');
                Route::get('/proceedings/{proceeding}/history', [ProceedingController::class, 'history'])->name('proceedings.history');
                Route::get('/proceedings/{proceeding}/corrections', [ProceedingCorrectionController::class, 'index'])->name('proceedings.corrections.index');
                Route::post('/proceedings/{proceeding}/corrections', [ProceedingCorrectionController::class, 'store'])->name('proceedings.corrections.store');
                Route::delete('/proceedings/{proceeding}/corrections/{correction}', [ProceedingCorrectionController::class, 'destroy'])->name('proceedings.corrections.destroy');
                Route::post('/proceedings/{proceeding}/calculate', [ProceedingResultController::class, 'calculate'])->name('proceedings.calculate');
                Route::get('/proceedings/{proceeding}/result', [ProceedingResultController::class, 'show'])->name('proceedings.result');
                Route::post('/proceedings/{proceeding}/holds/detect', [ProceedingHoldController::class, 'detect'])->name('proceedings.holds.detect');
                Route::post('/proceedings/{proceeding}/holds', [ProceedingHoldController::class, 'store'])->name('proceedings.holds.store');
                Route::delete('/proceedings/{proceeding}/holds/{hold}', [ProceedingHoldController::class, 'destroy'])->name('proceedings.holds.destroy');
            });

        Route::middleware('role:'.Role::Chairman->value.'|'.Role::Member->value)
            ->prefix('cgpsc')
            ->name('cgpsc.')
            ->group(function (): void {
                /*
                 | Item 9, as clarified: the CGPSC Chairman designates who conducts
                 | each DPC — a member of that DPC's committee, or themselves.
                 |
                 | Guarded on `role:chairman` alone rather than on this group's
                 | `chairman|member`, because a Member designating the DPC Chairman
                 | is exactly the thing the register gives to the Chairman.
                 */
                Route::post(
                    '/dpc-proposals/{dpcProposal}/committee/{member}/designate-chairman',
                    [DpcCommitteeMemberController::class, 'designateChairman'],
                )->middleware('role:'.Role::Chairman->value)->name('committee.designate-chairman');

                Route::get('/allotments', [AllotmentController::class, 'index'])->name('allotments.index');
                Route::post('/allotments/{dpcProposal}', [AllotmentController::class, 'store'])->name('allotments.store');
                Route::post('/allotments/{dpcProposal}/reallot', [AllotmentController::class, 'reallot'])->name('allotments.reallot');
                Route::get('/allotments/{dpcProposal}/history', [AllotmentController::class, 'history'])->name('allotments.history');

                // The DPC sitting (M7 Phase 1, PPT S31–S34). `{proceeding}`
                // binds by UUID — sequential ids never appear in a URL.

                // Commission corrections (M7 Phase 2, PPT S33 EDIT / TRIBAL).
                // DELETE withdraws; it never deletes — the withdrawal is
                // itself an appended correction.

                // CALCULATE, result, approval and cancellation (M7 Phase 3,
                // PPT S33–S34).
                Route::post('/proceedings/{proceeding}/approve', [ProceedingResultController::class, 'approve'])->name('proceedings.approve');
                Route::post('/proceedings/{proceeding}/cancel', [ProceedingResultController::class, 'cancel'])->name('proceedings.cancel');

                // Held promotions and generated documents (M7 Phase 4,
                // PPT S33-S34, S45). Documents are a separate controller from
                // approval: approval creates the decision, documents render it.

                // Circulation DPC — the Commission's side (M9 Phase 2, PPT
                // S46/S48). `{dpcCirculation}` binds by UUID. Declared before
                // the document routes so nothing shadows it.
                Route::get('/circulations', [CirculationConsentController::class, 'index'])->name('circulations.index');
                Route::get('/circulations/{dpcCirculation}', [CirculationConsentController::class, 'show'])->name('circulations.show');
                Route::post('/circulations/{dpcCirculation}/items/{item}/decide', [CirculationConsentController::class, 'decide'])->name('circulations.decide');
                Route::post('/circulations/{dpcCirculation}/consent', [CirculationConsentController::class, 'consent'])->name('circulations.consent');
                Route::post('/circulations/{dpcCirculation}/sign', [CirculationConsentController::class, 'sign'])->name('circulations.sign');
                Route::post('/circulations/{dpcCirculation}/return', [CirculationConsentController::class, 'returnToDepartment'])->name('circulations.return');

                // Circulation documents (M9 Phase 3, PPT S45/S48). Publication
                // is refused while BD-14 is unresolved (design §23.1); the
                // documents are still generated, signed and verifiable.
                Route::get('/circulations/{dpcCirculation}/documents', [CirculationDocumentController::class, 'index'])->name('circulations.documents.index');
                Route::post('/circulations/{dpcCirculation}/documents', [CirculationDocumentController::class, 'store'])->name('circulations.documents.store');
                Route::post('/circulations/{dpcCirculation}/documents/publish', [CirculationDocumentController::class, 'publish'])->name('circulations.documents.publish');
                Route::get('/circulations/{dpcCirculation}/documents/{document}', [CirculationDocumentController::class, 'download'])->name('circulations.documents.download');
                Route::post('/circulations/{dpcCirculation}/documents/{document}/verify', [CirculationDocumentController::class, 'verify'])->name('circulations.documents.verify');

                Route::get('/proceedings/{proceeding}/documents', [ProceedingDocumentController::class, 'index'])->name('proceedings.documents.index');
                Route::post('/proceedings/{proceeding}/documents', [ProceedingDocumentController::class, 'store'])->name('proceedings.documents.store');
                Route::post('/proceedings/{proceeding}/documents/publish', [ProceedingDocumentController::class, 'publish'])->name('proceedings.documents.publish');
                Route::get('/proceedings/{proceeding}/documents/{document}', [ProceedingDocumentController::class, 'show'])->name('proceedings.documents.show');
            });

        // CGPSC Admin area (master data & user management).
        Route::middleware('role:'.Role::Admin->value)
            ->prefix('admin')
            ->name('admin.')
            ->group(function (): void {
                Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
                Route::get('/integrations', IntegrationStatusController::class)->name('integrations');

                /*
                 | Search, CSV export and bulk upload, for every master the registry
                 | describes (business instruction, 2026-09-06).
                 |
                 | One set of routes rather than three per master screen: `{master}`
                 | is a key resolved through `MasterRegistry`, which is the
                 | allow-list — an unknown key 404s rather than reaching a model.
                 |
                 | Search itself needs no route: it is a `?q=` on the screen's own
                 | index, applied by each master controller.
                 */
                Route::get('master-data/{master}/export', [MasterDataController::class, 'export'])
                    ->name('master-data.export');
                Route::get('master-data/{master}/template', [MasterDataController::class, 'template'])
                    ->name('master-data.template');
                Route::post('master-data/{master}/import', [MasterDataController::class, 'import'])
                    ->name('master-data.import');

                // Audit trail (M11). Read-only by design: no create, update,
                // delete or purge route exists here, and none should be added —
                // an audit record editable from the application that writes it
                // is not an audit record. Retention is permanent by ruling.
                Route::get('/audit-trail', [AuditTrailController::class, 'index'])->name('audit-trail.index');
                Route::get('/audit-trail/export', [AuditTrailController::class, 'export'])->name('audit-trail.export');
                Route::get('/audit-trail/{source}/{id}', [AuditTrailController::class, 'show'])
                    ->whereNumber('id')->name('audit-trail.show');
                // Department Master — the catalogue registration (S6) draws
                // from. Registration still owns the registration transition.
                Route::resource('departments', DepartmentController::class)->except(['show']);

                /*
                 | Vacancy Master — inside Department Management, for the department
                 | in the URL (no department picker). Year-wise position of each
                 | mapped post, in four steps: sanctioned strength for the year, working
                 | strength on 1 January, vacancies during the year, summary.
                 */
                Route::prefix('departments/{department}/vacancy-master')
                    ->name('departments.vacancy-master.')
                    ->controller(VacancyMasterController::class)
                    ->group(function (): void {
                        Route::get('/', 'index')->name('index');
                        Route::get('/{year}/posts/{post}', 'show')->whereNumber('year')->name('show');
                        Route::post('/{year}/posts/{post}/open', 'open')->whereNumber('year')->name('open');
                        Route::post('/{year}/posts/{post}/sanctioned-strength', 'saveSanctionedStrength')->whereNumber('year')->name('sanctioned-strength');
                        Route::get('/{year}/posts/{post}/sanctioned-strength/{sanctionedStrength}/document', 'sanctionedStrengthDocument')->whereNumber('year')->name('sanctioned-strength.document');
                        Route::post('/{year}/posts/{post}/working-strength', 'saveWorkingStrength')->whereNumber('year')->name('working-strength');
                        Route::get('/{year}/posts/{post}/working-strength/{workingStrength}/document', 'workingStrengthDocument')->whereNumber('year')->name('working-strength.document');
                        Route::post('/{year}/posts/{post}/entries', 'storeEntry')->whereNumber('year')->name('entries.store');
                        Route::post('/{year}/posts/{post}/entries/{entry}/verify', 'verifyEntry')->whereNumber('year')->name('entries.verify');
                        Route::post('/{year}/posts/{post}/entries/{entry}/withdraw', 'withdrawEntry')->whereNumber('year')->name('entries.withdraw');
                        Route::get('/{year}/posts/{post}/entries/{entry}/document', 'entryDocument')->whereNumber('year')->name('entries.document');
                        Route::post('/{year}/posts/{post}/finalize', 'finalize')->whereNumber('year')->name('finalize');
                        Route::post('/{year}/posts/{post}/revise', 'revise')->whereNumber('year')->name('revise');
                    });
                Route::resource('districts', DistrictController::class)->except(['show']);
                Route::resource('designations', DesignationController::class)->except(['show']);
                // Class Master (change item 1). `service-classes` rather than
                // `classes` because the latter reads as a PHP concept in a URL.
                Route::resource('service-classes', ClassMasterController::class)
                    ->parameters(['service-classes' => 'classMaster'])
                    ->except(['show']);
                Route::resource('pay-scales', PayScaleController::class)
                    ->parameters(['pay-scales' => 'payScale'])
                    ->except(['show']);
                Route::resource('reservation-categories', ReservationCategoryController::class)
                    ->parameters(['reservation-categories' => 'reservationCategory'])
                    ->except(['show']);
                Route::resource('review-reasons', ReviewReasonController::class)
                    ->parameters(['review-reasons' => 'reviewReason'])
                    ->except(['show']);
                /*
                 | Why a mandatory CR or Loyalty record is missing (change register of
                 | 2026-08-27, item 16). Beside the Review Reason master rather than
                 | with the DPC screens, because it is a master an Administrator
                 | maintains and an officer only reads.
                 */
                Route::resource('record-reasons', RecordReasonController::class)
                    ->parameters(['record-reasons' => 'recordReason'])
                    ->except(['show']);
                Route::resource('vacancy-types', VacancyTypeController::class)
                    ->parameters(['vacancy-types' => 'vacancyType'])
                    ->except(['show']);
                Route::resource('dpc-types', DpcTypeController::class)
                    ->parameters(['dpc-types' => 'dpcType'])
                    ->except(['show']);
                /*
                 | The Department Post Mapping is effective-dated and authorised by
                 | a Government Order, so it has three routes beyond the resource:
                 | the details page (which shows the order and every version of its
                 | PDF), the supersession that a new order produces, and the
                 | download. All three are declared before the resource so their
                 | literal segments are not read as a mapping's route key.
                 */
                Route::get('department-posts/{post}/supersede', [DepartmentPostController::class, 'supersedeForm'])
                    ->name('department-posts.supersede.form');
                Route::post('department-posts/{post}/supersede', [DepartmentPostController::class, 'supersede'])
                    ->name('department-posts.supersede');
                /*
                 | The PDF behind one Government Order (change register of
                 | 2026-08-26, item 10). The parameter is `{order}` and not
                 | `{document}`, because a mapping now holds several orders and each
                 | carries at most one file — the order is the thing being
                 | addressed, and the binding resolves it by UUID.
                 */
                Route::get('department-posts/{post}/orders/{order}', [DepartmentPostController::class, 'downloadDocument'])
                    ->name('department-posts.documents.download');

                // `{post}` rather than `{department_post}`, matching the three
                // routes above and the controller's own signatures.
                Route::resource('department-posts', DepartmentPostController::class)
                    ->parameters(['department-posts' => 'post']);
                // The controlled list a recruitment rule's minimum qualification
                // is chosen from (replaces the free-text field).
                Route::resource('qualifications', QualificationController::class)->except(['show']);
                Route::resource('reservation-policies', ReservationPolicyController::class)
                    ->parameters(['reservation-policies' => 'reservationPolicy'])
                    ->except(['show']);
                Route::resource('reservation-rosters', ReservationRosterController::class)
                    ->parameters(['reservation-rosters' => 'reservationRoster'])
                    ->except(['show']);
                Route::post('reservation-rosters/{reservationRoster}/points', [ReservationRosterPointController::class, 'store'])
                    ->name('reservation-rosters.points.store');
                Route::delete('reservation-rosters/{reservationRoster}/points/{point}', [ReservationRosterPointController::class, 'destroy'])
                    ->name('reservation-rosters.points.destroy');
                /*
                 | The CGPSC Chairman, as an administered and dated record (change
                 | register of 2026-08-26, item 9). No `destroy`: a Chairman who
                 | leaves office is succeeded, not deleted, because every committee
                 | composed during their tenure names them.
                 */
                Route::resource('cgpsc-chairman', CgpscChairmanController::class)
                    ->parameters(['cgpsc-chairman' => 'cgpscChairman'])
                    ->only(['index', 'create', 'store', 'edit', 'update']);

                /*
                 | Item 9e: the Administrator may substitute a committee member at
                 | **any** stage, so this sits under `role:admin` rather than under
                 | the proposal's own editability policy.
                 */
                Route::get(
                    'dpc-proposals/{dpcProposal}/committee/{member}/replace',
                    [DpcCommitteeMemberController::class, 'replaceForm'],
                )->name('committee-members.replace.form');
                Route::post(
                    'dpc-proposals/{dpcProposal}/committee/{member}/replace',
                    [DpcCommitteeMemberController::class, 'replace'],
                )->name('committee-members.replace');
                /*
                 | Suggestion 3. Proposals raised before change item 11 carry no
                 | sanctioning order, because migration 360007 refused to guess one.
                 | Read-and-decide, and the decision is recorded as the
                 | Administrator's.
                 */
                // Suggestion 6: the landing page's notice board, as data.
                Route::resource('portal-notices', PortalNoticeController::class)
                    ->parameters(['portal-notices' => 'portalNotice'])
                    ->except(['show']);

                Route::get('pre-dpc-links', [PreDpcLinkController::class, 'index'])->name('pre-dpc-links.index');
                Route::post('pre-dpc-links/{dpcProposal}', [PreDpcLinkController::class, 'store'])
                    ->name('pre-dpc-links.store');

                // Declared before the resource so `align-bounds` is not read as a
                // configuration's route key.
                Route::post(
                    'committee-configurations/{committeeConfiguration}/align-bounds',
                    [CommitteeConfigurationController::class, 'alignBounds'],
                )->name('committee-configurations.align-bounds');

                Route::resource('committee-configurations', CommitteeConfigurationController::class)
                    ->parameters(['committee-configurations' => 'committeeConfiguration'])
                    ->except(['show']);

                /*
                 | DPC Rules (rules 2.1, 3.4, 3.5): the CR marks and qualifying scores
                 | and the PH reservation, version by version. Recorded and read only —
                 | a change is a new version, never an edit.
                 */
                Route::prefix('dpc-rules')->name('dpc-rules.')->group(function (): void {
                    Route::get('/', [DpcRuleController::class, 'index'])->name('index');
                    Route::get('cr-scales/create', [DpcRuleController::class, 'createCrScale'])->name('cr-scales.create');
                    Route::post('cr-scales', [DpcRuleController::class, 'storeCrScale'])->name('cr-scales.store');
                    Route::get('cr-scales/{crScale}/document', [DpcRuleController::class, 'crScaleDocument'])->name('cr-scales.document');
                    Route::get('ph-rules/create', [DpcRuleController::class, 'createPhRule'])->name('ph-rules.create');
                    Route::post('ph-rules', [DpcRuleController::class, 'storePhRule'])->name('ph-rules.store');
                    Route::get('ph-rules/{phRule}/document', [DpcRuleController::class, 'phRuleDocument'])->name('ph-rules.document');
                });

                Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
                Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
                Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

                /*
                 | Role Assignments and transfers (change items 1 and 2).
                 |
                 | DELETE releases the officer from their current posting; it never
                 | deletes an assignment. A past posting is a fact about a period
                 | that has closed, and the record of which department signed what
                 | is the whole reason the table exists — so there is no route here
                 | that can remove one, and none should be added.
                 */
                Route::get('users/{user}/assignments', [UserAssignmentController::class, 'index'])
                    ->name('users.assignments.index');
                Route::post('users/{user}/assignments', [UserAssignmentController::class, 'store'])
                    ->name('users.assignments.store');
                Route::delete('users/{user}/assignments', [UserAssignmentController::class, 'release'])
                    ->name('users.assignments.release');
            });
    });
});
