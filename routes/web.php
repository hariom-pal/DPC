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
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
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
Route::post('/login', [LoginController::class, 'store'])->name('login.store');

Route::get('/register', [RegistrationController::class, 'create'])->name('register');
Route::post('/register', [RegistrationController::class, 'store'])->name('register.store');

Route::get('/verify', [VerificationController::class, 'show'])->name('verify.show');
Route::post('/verify', [VerificationController::class, 'store'])->name('verify.store');

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

        // CGPSC Admin area (master data & user management).
        Route::middleware('role:'.Role::Admin->value)
            ->prefix('admin')
            ->name('admin.')
            ->group(function (): void {
                Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
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
