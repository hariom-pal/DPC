<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
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
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
