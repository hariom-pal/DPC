<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\CadreController;
use App\Http\Controllers\Admin\CommitteeConfigurationController;
use App\Http\Controllers\Admin\CommitteeTypeController;
use App\Http\Controllers\Admin\DesignationController;
use App\Http\Controllers\Admin\DistrictController;
use App\Http\Controllers\Admin\DpcTypeController;
use App\Http\Controllers\Admin\IntegrationStatusController;
use App\Http\Controllers\Admin\PayScaleController;
use App\Http\Controllers\Admin\RecruitmentRuleController;
use App\Http\Controllers\Admin\ReservationCategoryController;
use App\Http\Controllers\Admin\ReservationPolicyController;
use App\Http\Controllers\Admin\ReservationRosterController;
use App\Http\Controllers\Admin\ReservationRosterPointController;
use App\Http\Controllers\Admin\ReviewReasonController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VacancyTypeController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\VerificationController;
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
use App\Http\Controllers\Dpc\DpcImportController;
use App\Http\Controllers\Dpc\DpcProposalController;
use App\Http\Controllers\Dpc\ReviewProposalController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PreDpc\PreDpcProposalController;
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

        // Pre-DPC Proposal workflow (department Authorized Officer / Administrative Head).
        Route::middleware('role:'.Role::AuthorizedOfficer->value.'|'.Role::AdministrativeHead->value)
            ->prefix('pre-dpc')
            ->name('pre-dpc.')
            ->group(function (): void {
                Route::get('/', [PreDpcProposalController::class, 'index'])->name('index');
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
                Route::put('/{dpcProposal}/committee', [DpcProposalController::class, 'updateCommittee'])->name('committee.update');
                Route::get('/{dpcProposal}/working-officers', [DpcProposalController::class, 'workingOfficers'])->name('working-officers');
                Route::put('/{dpcProposal}/working-officers', [DpcProposalController::class, 'updateWorkingOfficers'])->name('working-officers.update');
                Route::get('/{dpcProposal}/seniority-list', [DpcProposalController::class, 'seniorityList'])->name('seniority-list');
                Route::put('/{dpcProposal}/seniority-list', [DpcProposalController::class, 'updateSeniorityList'])->name('seniority-list.update');
                // AH review walkthrough (M4 Phase 7, PPT S21–S25) — ends in the single approval.
                Route::get('/{dpcProposal}/review/{step?}', [DpcProposalController::class, 'review'])
                    ->whereNumber('step')
                    ->name('review');
                Route::get('/{dpcProposal}/evaluation-chart', [DpcProposalController::class, 'evaluationChart'])->name('evaluation-chart');
                Route::put('/{dpcProposal}/evaluation-chart', [DpcProposalController::class, 'updateEvaluationChart'])->name('evaluation-chart.update');
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
        Route::middleware('role:'.Role::Chairman->value.'|'.Role::Member->value)
            ->prefix('cgpsc')
            ->name('cgpsc.')
            ->group(function (): void {
                Route::get('/allotments', [AllotmentController::class, 'index'])->name('allotments.index');
                Route::post('/allotments/{dpcProposal}', [AllotmentController::class, 'store'])->name('allotments.store');
                Route::post('/allotments/{dpcProposal}/reallot', [AllotmentController::class, 'reallot'])->name('allotments.reallot');
                Route::get('/allotments/{dpcProposal}/history', [AllotmentController::class, 'history'])->name('allotments.history');

                // Member acceptance and scheduling (M6 Phase 2, PPT S29–S30).
                Route::get('/schedules', [SchedulingController::class, 'index'])->name('schedules.index');
                Route::post('/schedules/{dpcProposal}/accept', [SchedulingController::class, 'accept'])->name('schedules.accept');
                Route::post('/schedules/{dpcProposal}', [SchedulingController::class, 'store'])->name('schedules.store');
                Route::post('/schedules/{dpcProposal}/reschedule', [SchedulingController::class, 'reschedule'])->name('schedules.reschedule');
                Route::get('/schedules/{dpcProposal}/history', [SchedulingController::class, 'history'])->name('schedules.history');

                // The DPC sitting (M7 Phase 1, PPT S31–S34). `{proceeding}`
                // binds by UUID — sequential ids never appear in a URL.
                Route::get('/proceedings', [ProceedingController::class, 'index'])->name('proceedings.index');
                Route::post('/proceedings/{dpcProposal}/open', [ProceedingController::class, 'open'])->name('proceedings.open');
                Route::get('/proceedings/{proceeding}', [ProceedingController::class, 'show'])->name('proceedings.show');
                Route::get('/proceedings/{proceeding}/history', [ProceedingController::class, 'history'])->name('proceedings.history');

                // Commission corrections (M7 Phase 2, PPT S33 EDIT / TRIBAL).
                // DELETE withdraws; it never deletes — the withdrawal is
                // itself an appended correction.
                Route::get('/proceedings/{proceeding}/corrections', [ProceedingCorrectionController::class, 'index'])->name('proceedings.corrections.index');
                Route::post('/proceedings/{proceeding}/corrections', [ProceedingCorrectionController::class, 'store'])->name('proceedings.corrections.store');
                Route::delete('/proceedings/{proceeding}/corrections/{correction}', [ProceedingCorrectionController::class, 'destroy'])->name('proceedings.corrections.destroy');

                // CALCULATE, result, approval and cancellation (M7 Phase 3,
                // PPT S33–S34).
                Route::post('/proceedings/{proceeding}/calculate', [ProceedingResultController::class, 'calculate'])->name('proceedings.calculate');
                Route::get('/proceedings/{proceeding}/result', [ProceedingResultController::class, 'show'])->name('proceedings.result');
                Route::post('/proceedings/{proceeding}/approve', [ProceedingResultController::class, 'approve'])->name('proceedings.approve');
                Route::post('/proceedings/{proceeding}/cancel', [ProceedingResultController::class, 'cancel'])->name('proceedings.cancel');

                // Held promotions and generated documents (M7 Phase 4,
                // PPT S33-S34, S45). Documents are a separate controller from
                // approval: approval creates the decision, documents render it.
                Route::post('/proceedings/{proceeding}/holds/detect', [ProceedingHoldController::class, 'detect'])->name('proceedings.holds.detect');
                Route::post('/proceedings/{proceeding}/holds', [ProceedingHoldController::class, 'store'])->name('proceedings.holds.store');
                Route::delete('/proceedings/{proceeding}/holds/{hold}', [ProceedingHoldController::class, 'destroy'])->name('proceedings.holds.destroy');

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
                Route::resource('districts', DistrictController::class)->except(['show']);
                Route::resource('designations', DesignationController::class)->except(['show']);
                Route::resource('pay-scales', PayScaleController::class)
                    ->parameters(['pay-scales' => 'payScale'])
                    ->except(['show']);
                Route::resource('reservation-categories', ReservationCategoryController::class)
                    ->parameters(['reservation-categories' => 'reservationCategory'])
                    ->except(['show']);
                Route::resource('review-reasons', ReviewReasonController::class)
                    ->parameters(['review-reasons' => 'reviewReason'])
                    ->except(['show']);
                Route::resource('vacancy-types', VacancyTypeController::class)
                    ->parameters(['vacancy-types' => 'vacancyType'])
                    ->except(['show']);
                Route::resource('dpc-types', DpcTypeController::class)
                    ->parameters(['dpc-types' => 'dpcType'])
                    ->except(['show']);
                Route::resource('cadres', CadreController::class)->except(['show']);
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
                Route::resource('committee-types', CommitteeTypeController::class)
                    ->parameters(['committee-types' => 'committeeType'])
                    ->except(['show']);
                Route::resource('committee-configurations', CommitteeConfigurationController::class)
                    ->parameters(['committee-configurations' => 'committeeConfiguration'])
                    ->except(['show']);
                Route::resource('recruitment-rules', RecruitmentRuleController::class)
                    ->parameters(['recruitment-rules' => 'recruitmentRule'])
                    ->except(['show']);
                Route::post('recruitment-rules/{recruitmentRule}/version', [RecruitmentRuleController::class, 'newVersion'])
                    ->name('recruitment-rules.version');
                Route::get('recruitment-rules/{recruitmentRule}/download', [RecruitmentRuleController::class, 'download'])
                    ->name('recruitment-rules.download');

                Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
                Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
                Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
            });
    });
});
