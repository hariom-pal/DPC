<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AssignmentRequestController;
use App\Http\Controllers\Admin\AuditTrailController;
use App\Http\Controllers\Admin\CgpscChairmanController;
use App\Http\Controllers\Admin\ClassMasterController;
use App\Http\Controllers\Admin\CommitteeConfigurationController;
use App\Http\Controllers\Admin\CrGradeValueController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DepartmentInvitationController;
use App\Http\Controllers\Admin\DepartmentPostController;
use App\Http\Controllers\Admin\DesignationController;
use App\Http\Controllers\Admin\DistrictController;
use App\Http\Controllers\Admin\DpcTypeController;
use App\Http\Controllers\Admin\IntegrationStatusController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\PayScaleController;
use App\Http\Controllers\Admin\PortalNoticeController;
use App\Http\Controllers\Admin\PreDpcLinkController;
use App\Http\Controllers\Admin\PromotionCrRuleController;
use App\Http\Controllers\Admin\QualificationController;
use App\Http\Controllers\Admin\RecordReasonController;
use App\Http\Controllers\Admin\ReservationCategoryController;
use App\Http\Controllers\Admin\ReservationPolicyController;
use App\Http\Controllers\Admin\ReservationRosterController;
use App\Http\Controllers\Admin\ReservationRosterPointController;
use App\Http\Controllers\Admin\ReviewReasonController;
use App\Http\Controllers\Admin\SealedCoverReasonController;
use App\Http\Controllers\Admin\UserAssignmentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserIdentityController;
use App\Http\Controllers\Admin\VacancyManagementController;
use App\Http\Controllers\Admin\VacancyMasterController;
use App\Http\Controllers\Auth\ActingDepartmentController;
use App\Http\Controllers\Auth\IdentityController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\RoleSwitchController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\Candidate\CandidateDpcController;
use App\Http\Controllers\Cgpsc\AllotmentController;
use App\Http\Controllers\Cgpsc\CirculationConsentController;
use App\Http\Controllers\Cgpsc\CirculationDocumentController;
use App\Http\Controllers\Cgpsc\PreviousDpcRecordController;
use App\Http\Controllers\Cgpsc\ProceedingController;
use App\Http\Controllers\Cgpsc\ProceedingCorrectionController;
use App\Http\Controllers\Cgpsc\ProceedingDocumentController;
use App\Http\Controllers\Cgpsc\ProceedingHoldController;
use App\Http\Controllers\Cgpsc\ProceedingResultController;
use App\Http\Controllers\Cgpsc\SchedulingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Department\DepartmentOfficerController;
use App\Http\Controllers\Department\DepartmentUserController;
use App\Http\Controllers\Department\ProposalAllotmentController;
use App\Http\Controllers\Dpc\CirculationController;
use App\Http\Controllers\Dpc\DpcCalculationController;
use App\Http\Controllers\Dpc\DpcCommitteeMemberController;
use App\Http\Controllers\Dpc\DpcEmployeeDetailsController;
use App\Http\Controllers\Dpc\DpcImportController;
use App\Http\Controllers\Dpc\DpcPromotionOrderController;
use App\Http\Controllers\Dpc\DpcProposalController;
use App\Http\Controllers\Dpc\DpcProposalVerificationController;
use App\Http\Controllers\Dpc\DpcRecordGapController;
use App\Http\Controllers\Dpc\ImportTemplateController;
use App\Http\Controllers\Dpc\ReviewProposalController;
use App\Http\Controllers\EsignController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PreDpc\PreDpcProposalController;
use App\Http\Controllers\Proceeding\CommitteeSittingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SelfInspection\SelfInspectionController;
use App\Http\Middleware\EnsureAccountReady;
use App\Http\Middleware\EnsureCanWorkAnAllotment;
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

/*
 | Department Admin registration by invitation (business decision, 2026-10-26).
 | `/register` only explains that registration is by invitation; the invitation
 | link carries a one-time token whose SHA-256 alone is stored.
 */
Route::get('/register', [RegistrationController::class, 'create'])->name('register');
Route::middleware('throttle:registration')->group(function (): void {
    Route::get('/register/{token}', [RegistrationController::class, 'show'])->name('register.invitation');
    Route::post('/register/{token}', [RegistrationController::class, 'start'])->name('register.invitation.start');
    Route::get('/register/{token}/verify', [RegistrationController::class, 'verifyForm'])->name('register.invitation.verify');
    Route::post('/register/{token}/verify', [RegistrationController::class, 'verify'])
        ->middleware('throttle:otp-verify')->name('register.invitation.verify.store');
    Route::post('/register/{token}/resend', [RegistrationController::class, 'resend'])->name('register.invitation.resend');
    // DigiLocker, then the password (2026-10-27). DigiLocker answers on the one
    // registered callback, `identity.callback`.
    Route::get('/register/{token}/identity', [RegistrationController::class, 'identityForm'])->name('register.invitation.identity');
    Route::post('/register/{token}/identity', [RegistrationController::class, 'identityStart'])->middleware('throttle:otp-verify')->name('register.invitation.identity.start');
    Route::get('/register/{token}/password', [RegistrationController::class, 'passwordForm'])->name('register.invitation.password');
    Route::post('/register/{token}/password', [RegistrationController::class, 'complete'])->name('register.invitation.complete');
});

// Sign-in steps before the OTP (2026-10-27): DigiLocker identity, then the
// Aadhaar-linked-mobile declaration. They act on the pending sign-in only.
Route::post('/sign-in/cancel', [LoginController::class, 'cancel'])->name('login.cancel');
Route::get('/sign-in/identity', [IdentityController::class, 'show'])->name('identity.show');
Route::post('/sign-in/identity', [IdentityController::class, 'start'])->middleware('throttle:otp-verify')->name('identity.start');
Route::get('/sign-in/identity/callback', [IdentityController::class, 'callback'])->middleware('throttle:otp-verify')->name('identity.callback');
Route::get('/sign-in/identity/simulator', [IdentityController::class, 'simulator'])->name('identity.simulator');
Route::post('/sign-in/identity/simulator', [IdentityController::class, 'simulate'])->name('identity.simulator.submit');

Route::get('/verify', [VerificationController::class, 'show'])->name('verify.show');
Route::post('/verify', [VerificationController::class, 'store'])->middleware('throttle:otp-verify')->name('verify.store');
Route::post('/verify/resend', [VerificationController::class, 'resend'])->middleware('throttle:registration')->name('verify.resend');

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
// Coder Instructions rule 4.3: the promotion order link sent to officers and committee
// members by SMS and e-mail. Signed, so a member with no portal account can open it.
Route::get('/promotion-orders/{order}', [DpcPromotionOrderController::class, 'public'])
    ->middleware(['signed', 'throttle:60,1'])
    ->name('promotion-orders.public');

// Every authenticated route passes the account gate (Phase 1, 2026-10-27): an
// active account, a session that passed the sign-in challenge, and a changed
// system-issued password.
Route::middleware(['auth', EnsureAccountReady::class])->group(function (): void {
    // Coder Instructions rule 1.1: DPCs held offline, entered by the Commission's staff;
    // the Administrator reads them.
    Route::middleware('role:cgpsc_staff|admin')->prefix('previous-dpcs')->name('previous-dpcs.')->group(function (): void {
        Route::get('/', [PreviousDpcRecordController::class, 'index'])->name('index');
        Route::get('/create', [PreviousDpcRecordController::class, 'create'])->name('create');
        Route::post('/', [PreviousDpcRecordController::class, 'store'])->name('store');
        Route::get('/{previousDpc}', [PreviousDpcRecordController::class, 'show'])->name('show');
        Route::get('/{previousDpc}/files/{file}', [PreviousDpcRecordController::class, 'file'])
            ->whereIn('file', ['minutes', 'seniority_list', 'working_officers'])->name('file');
    });

    // Reachable even while a first-login password change is pending.
    Route::get('/password/change', [PasswordChangeController::class, 'show'])->name('password.change.show');
    Route::post('/password/change', [PasswordChangeController::class, 'update'])->name('password.change.update');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Application screens. The pending password change is enforced by
    // EnsureAccountReady on the whole authenticated group.
    Route::group([], function (): void {
        /*
         | Department Dashboard → User Management (business decision, 2026-10-26).
         | The Department Admin's own department's Authorized Officers and
         | Administrative Heads; every action is re-checked by
         | DepartmentUserAuthority, so the role alone opens nothing outside it.
         */
        Route::middleware('role:'.Role::DepartmentAdmin->value)
            ->prefix('department')
            ->name('department.')
            ->group(function (): void {
                // Department Post Mapping for the own department (2026-10-28); the
                // controller scopes every action to the Department Admin's department.
                Route::get('/posts/{post}/supersede', [DepartmentPostController::class, 'supersedeForm'])->name('posts.supersede.form');
                Route::post('/posts/{post}/supersede', [DepartmentPostController::class, 'supersede'])->name('posts.supersede');
                Route::get('/posts/{post}/orders/{order}', [DepartmentPostController::class, 'downloadDocument'])->name('posts.documents.download');
                Route::resource('posts', DepartmentPostController::class)->parameters(['posts' => 'post']);

                // The AO / AH picker (2026-10-28): suggest as the e-mail is typed, find by
                // e-mail, or add with both OTPs; a chosen officer's mobile changes by OTP.
                Route::get('/officers/search', [DepartmentOfficerController::class, 'search'])->middleware('throttle:120,1')->name('officers.search');
                Route::get('/officers/lookup', [DepartmentOfficerController::class, 'lookup'])->middleware('throttle:30,1')->name('officers.lookup');
                Route::post('/officers/{officer}/mobile/otp', [DepartmentOfficerController::class, 'sendMobileOtp'])->middleware('throttle:registration')->name('officers.mobile.otp');
                Route::put('/officers/{officer}/mobile', [DepartmentOfficerController::class, 'updateMobile'])->middleware('throttle:otp-verify')->name('officers.mobile');

                // User Management (2026-10-29): the department's officers, and releasing
                // a transferred AO/AH so another department can pick them up.
                Route::get('/users', [DepartmentUserController::class, 'index'])->name('users.index');
                Route::post('/users/{officer}/release', [DepartmentUserController::class, 'release'])->name('users.release');
                Route::post('/officers/otp', [DepartmentOfficerController::class, 'sendOtp'])->middleware('throttle:registration')->name('officers.otp');
                Route::post('/officers', [DepartmentOfficerController::class, 'store'])->middleware('throttle:otp-verify')->name('officers.store');
                Route::put('/officers/{officer}/details', [DepartmentOfficerController::class, 'updateDetails'])->name('officers.details');

                // Pre-DPC / DPC allotment to an AO and an AH (2026-10-26).
                Route::get('/allotments', [ProposalAllotmentController::class, 'index'])->name('allotments.index');
                Route::get('/allotments/pre-dpc/create', [ProposalAllotmentController::class, 'create'])->name('allotments.pre-dpc.create');
                Route::post('/allotments/pre-dpc', [ProposalAllotmentController::class, 'store'])->name('allotments.pre-dpc.store');
                Route::get('/allotments/pre-dpc/{preDpc}/edit', [ProposalAllotmentController::class, 'editPreDpc'])->name('allotments.pre-dpc.edit');
                Route::put('/allotments/pre-dpc/{preDpc}', [ProposalAllotmentController::class, 'updatePreDpc'])->name('allotments.pre-dpc.update');
                Route::get('/allotments/pre-dpc/{preDpc}/history', [ProposalAllotmentController::class, 'historyPreDpc'])->name('allotments.pre-dpc.history');
                Route::get('/allotments/dpc/{dpcProposal}/edit', [ProposalAllotmentController::class, 'editDpc'])->name('allotments.dpc.edit');
                Route::put('/allotments/dpc/{dpcProposal}', [ProposalAllotmentController::class, 'updateDpc'])->name('allotments.dpc.update');
                Route::get('/allotments/dpc/{dpcProposal}/history', [ProposalAllotmentController::class, 'historyDpc'])->name('allotments.dpc.history');

            });

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        // The eSign step before every submission and approval (2026-10-28). Each
        // action is authorised by the proposal's own policy in EsignController.
        Route::get('/esign/simulator', [EsignController::class, 'simulator'])->name('esign.simulator');
        Route::post('/esign/simulator', [EsignController::class, 'simulate'])->middleware('throttle:otp-verify')->name('esign.simulator.submit');
        Route::get('/esign/{kind}/{uuid}', [EsignController::class, 'show'])->name('esign.show');
        Route::post('/esign/{kind}/{uuid}', [EsignController::class, 'start'])->middleware('throttle:otp-verify')->name('esign.start');

        // The AO's and AH's profile at every sign-in (2026-10-28).
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::post('/profile', [ProfileController::class, 'confirm'])->name('profile.confirm');

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
        // The department an officer with several assignments acts in (Phase 6, 2026-10-27).
        Route::post('/acting-department', [ActingDepartmentController::class, 'store'])->name('acting-department');

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

        // Pre-DPC Proposal workflow (department Authorized Officer / Administrative Head;
        // the Department Admin fills in and reads its department's, 2026-10-28 —
        // every action is the policy's to allow).
        Route::middleware('role:'.Role::AuthorizedOfficer->value.'|'.Role::AdministrativeHead->value.'|'.Role::DepartmentAdmin->value)
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

                // Opened by the Department Admin with its AO and AH (A1, 2026-10-26):
                // see department.allotments.pre-dpc.*. The AO fills it in via edit.
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

        // Vacancy Management — the department's own year-wise vacancy records, kept and
        // finalized by its Authorized Officer; its Administrative Head reads them.
        Route::middleware('role:'.Role::AuthorizedOfficer->value.'|'.Role::AdministrativeHead->value)
            ->prefix('vacancy-management')
            ->group(function (): void {
                Route::get('/', [VacancyManagementController::class, 'index'])->name('vacancy-management.index');
                Route::get('/open', [VacancyManagementController::class, 'go'])->name('vacancy-management.go');
                Route::prefix('departments/{department}')
                    ->name('vacancy-records.')
                    ->controller(VacancyMasterController::class)
                    ->group(base_path('routes/vacancy-records.php'));
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
                    // Rule 2.1: the Administrative Head's decision on a PH claim.
                    Route::post('/ph/verify', [DpcEmployeeDetailsController::class, 'verifyPh'])->name('ph.verify');
                    // Departmental enquiry and the sealed cover (Coder Instructions rule 2.2).
                    Route::put('/vigilance', [DpcEmployeeDetailsController::class, 'saveVigilance'])->name('vigilance');
                    // Rule 4.4: the Administrative Head's verification of the documents.
                    Route::post('/documents/verify', [DpcEmployeeDetailsController::class, 'verifyDocument'])->name('documents.verify-all');
                    Route::post('/documents/{document}/verify', [DpcEmployeeDetailsController::class, 'verifyDocument'])->name('documents.verify');
                    // Rule 2.3 (deputation without a sanctioned setup) and rule 4.4 (promotion refused).
                    Route::put('/declarations', [DpcEmployeeDetailsController::class, 'saveDeclarations'])->name('declarations');
                    Route::post('/documents', [DpcEmployeeDetailsController::class, 'uploadDocument'])->name('documents.store');
                    Route::get('/documents/{document}', [DpcEmployeeDetailsController::class, 'download'])->name('documents.download');
                });
                /*
                 | Rule 1.1 — the department's pre-submission confirmations. A step of
                 | the proposal like S15–S18; the controller gates writes on `update`.
                 */
                // Rule 4.3: the promotion orders, issued by the Administrative Head on the approved result.
                Route::get('/{dpcProposal}/promotion-orders', [DpcPromotionOrderController::class, 'index'])->name('promotion-orders.index');
                Route::post('/{dpcProposal}/promotion-orders', [DpcPromotionOrderController::class, 'store'])->name('promotion-orders.store');
                Route::get('/{dpcProposal}/promotion-orders/{order}', [DpcPromotionOrderController::class, 'download'])->name('promotion-orders.download');
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
                // Rule 4.4: representations and complaints about the DPC, with summaries.
                Route::post('/{dpcProposal}/representations', [DpcProposalVerificationController::class, 'storeRepresentation'])
                    ->name('representations.store');
                Route::get('/{dpcProposal}/representations/{representation}', [DpcProposalVerificationController::class, 'downloadRepresentation'])
                    ->name('representations.download');
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
                // Department Admin invitations (business decision, 2026-10-26).
                Route::get('department-invitations', [DepartmentInvitationController::class, 'index'])->name('department-invitations.index');
                Route::post('department-invitations', [DepartmentInvitationController::class, 'store'])->name('department-invitations.store');
                Route::post('department-invitations/{invitation}/resend', [DepartmentInvitationController::class, 'resend'])->name('department-invitations.resend');
                Route::post('department-invitations/{invitation}/revoke', [DepartmentInvitationController::class, 'revoke'])->name('department-invitations.revoke');

                /*
                 | Vacancy Master — inside Department Management, for the department
                 | in the URL (no department picker). Year-wise position of each
                 | mapped post, in four steps: sanctioned strength for the year, working
                 | strength on 1 January, vacancies during the year, summary.
                 */
                /*
                 | Vacancy Management — the Administrator's one entry point: choose
                 | Department → Post → Year, or find a record. The record's four steps
                 | are the Vacancy Master routes below.
                 */
                Route::get('vacancy-management', [VacancyManagementController::class, 'index'])->name('vacancy-management.index');
                Route::get('vacancy-management/open', [VacancyManagementController::class, 'go'])->name('vacancy-management.go');
                Route::prefix('departments/{department}/vacancy-master')
                    ->name('departments.vacancy-master.')
                    ->controller(VacancyMasterController::class)
                    ->group(base_path('routes/vacancy-records.php'));
                Route::resource('districts', DistrictController::class)->except(['show']);
                Route::resource('designations', DesignationController::class)->except(['show']);
                // Class Master (change item 1). `service-classes` rather than
                // `classes` because the latter reads as a PHP concept in a URL.
                Route::resource('service-classes', ClassMasterController::class)
                    ->parameters(['service-classes' => 'classMaster'])
                    ->except(['show']);
                // A class's promotion rules (CR, Loyalty, Property Declaration).
                Route::get('service-classes/{classMaster}/promotion-rules', [PromotionCrRuleController::class, 'index'])
                    ->name('service-classes.promotion-rules.index');
                Route::get('service-classes/{classMaster}/promotion-rules/create', [PromotionCrRuleController::class, 'create'])
                    ->name('service-classes.promotion-rules.create');
                Route::post('service-classes/{classMaster}/promotion-rules', [PromotionCrRuleController::class, 'store'])
                    ->name('service-classes.promotion-rules.store');
                // Eligible CR Grades — the numeric values a CR may carry.
                Route::get('cr-grades', [CrGradeValueController::class, 'index'])->name('cr-grades.index');
                Route::post('cr-grades', [CrGradeValueController::class, 'store'])->name('cr-grades.store');
                Route::patch('cr-grades/{crGrade}/deactivate', [CrGradeValueController::class, 'deactivate'])->name('cr-grades.deactivate');
                Route::patch('cr-grades/{crGrade}/remarks', [CrGradeValueController::class, 'updateRemarks'])->name('cr-grades.remarks');
                Route::delete('cr-grades/{crGrade}', [CrGradeValueController::class, 'destroy'])->name('cr-grades.destroy');
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
                // Departmental enquiry and the sealed cover (Coder Instructions rule 2.2).
                Route::resource('sealed-cover-reasons', SealedCoverReasonController::class)
                    ->parameters(['sealed-cover-reasons' => 'sealedCoverReason'])
                    ->except(['show']);
                /*
                 | The Vacancy Type master has no screen (2026-10-17). The vacancy
                 | engine reads four fixed codes from `vacancy_types`, so renaming or
                 | deleting one silently dropped vacancies from a proposal. The table
                 | and its rows stay for the `dpc_vacancies` that cite them.
                 */
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
                Route::get('reservation-policies/{reservationPolicy}/document', [ReservationPolicyController::class, 'document'])
                    ->name('reservation-policies.document');
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

                Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
                Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
                Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('users.reset-password');

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

                // Transfer and additional-charge requests (Phases 7–8, 2026-10-27).
                Route::get('assignment-requests', [AssignmentRequestController::class, 'index'])->name('assignment-requests.index');
                Route::post('assignment-requests/{assignmentRequest}/decide', [AssignmentRequestController::class, 'decide'])->name('assignment-requests.decide');
                Route::get('assignment-requests/{assignmentRequest}/order', [AssignmentRequestController::class, 'document'])->name('assignment-requests.document');

                // Identity: status, correction, manual and forced re-verification (2026-10-27).
                Route::get('users/{user}/identity', [UserIdentityController::class, 'show'])->name('users.identity');
                Route::post('users/{user}/identity/correct', [UserIdentityController::class, 'correct'])->name('users.identity.correct');
                Route::post('users/{user}/identity/manual', [UserIdentityController::class, 'manual'])->name('users.identity.manual');
                Route::post('users/{user}/identity/force', [UserIdentityController::class, 'force'])->name('users.identity.force');
                Route::get('users/{user}/identity/verifications/{verification}/document', [UserIdentityController::class, 'verificationDocument'])->name('users.identity.verification-document');
                Route::get('users/{user}/identity/corrections/{correction}/document', [UserIdentityController::class, 'correctionDocument'])->name('users.identity.correction-document');
            });
    });
});
