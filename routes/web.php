<?php

use App\Http\Controllers\Auth\AccountUnlockController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Owner\DashboardController;
use App\Http\Controllers\Owner\EmailChangeController;
use App\Http\Controllers\Owner\PasswordController;
use App\Http\Controllers\Owner\SecurityController;
use App\Http\Controllers\Owner\StepUpController;
use App\Http\Controllers\Owner\TwoFactorChallengeController;
use App\Http\Controllers\Owner\TwoFactorController;
use Illuminate\Support\Facades\Route;

// Health endpoint for deploy checks (spec Section 13, Phase 0). Reports
// only that the application is up; it exposes no configuration values.
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'easy-refer-v2',
    ]);
})->name('health');

Route::get('/', function () {
    return view('home');
})->name('home');

// Owner area (spec Sections 4 and 8). Public auth routes are the guest
// group below; every other /auth route sits behind the single auth
// guard group, and the route-inventory test proves none is missing.
// Sensitive state-changing endpoints additionally verify the request
// origin (spec Section 8.4).
Route::middleware('guest')->group(function () {
    Route::get('auth/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('auth/register', [RegisteredUserController::class, 'store'])
        ->middleware(['throttle:5,1', 'origin.check'])
        ->name('register');

    Route::get('auth/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('auth/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('origin.check')
        ->name('login');

    Route::get('auth/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('auth/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware(['throttle:5,1', 'origin.check'])
        ->name('password.email');

    Route::get('auth/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('auth/reset-password', [NewPasswordController::class, 'store'])
        ->middleware(['throttle:5,1', 'origin.check'])
        ->name('password.store');

    // One-time unlock link for the aggregate login lock (spec Section
    // 8.3, Amendment 5). The POST carries the same origin and CSRF
    // discipline as the other auth POSTs.
    Route::get('auth/unlock/{token}', [AccountUnlockController::class, 'show'])->name('account.unlock.show');
    Route::post('auth/unlock', [AccountUnlockController::class, 'store'])
        ->middleware(['throttle:5,1', 'origin.check'])
        ->name('account.unlock');
});

Route::middleware(['auth', 'session.lifetime', '2fa'])->group(function () {
    Route::post('auth/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->middleware('origin.check')
        ->name('logout');

    Route::get('auth/two-factor/challenge', [TwoFactorChallengeController::class, 'show'])
        ->name('two-factor.challenge');
    Route::post('auth/two-factor/challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware(['throttle:5,1', 'origin.check'])
        ->name('two-factor.challenge.store');

    Route::get('auth/verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('auth/verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('auth/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware(['throttle:6,1', 'origin.check'])
        ->name('verification.send');

    Route::get('auth/dashboard', DashboardController::class)->name('dashboard');
    Route::get('auth/security', [SecurityController::class, 'show'])->name('security');
    Route::post('auth/security/email', [EmailChangeController::class, 'store'])
        ->middleware(['origin.check', 'step-up'])
        ->name('security.email');
    Route::get('auth/security/step-up', [StepUpController::class, 'show'])
        ->name('security.step-up');
    Route::post('auth/security/step-up', [StepUpController::class, 'store'])
        ->middleware(['throttle:5,1', 'origin.check'])
        ->name('security.step-up.store');
    Route::post('auth/security/two-factor', [TwoFactorController::class, 'start'])
        ->middleware('origin.check')
        ->name('security.two-factor.start');
    Route::get('auth/security/two-factor/confirm', [TwoFactorController::class, 'confirmForm'])
        ->name('security.two-factor.confirm');
    Route::post('auth/security/two-factor/confirm', [TwoFactorController::class, 'confirm'])
        ->middleware('origin.check')
        ->name('security.two-factor.confirm.store');
    Route::post('auth/security/two-factor/disable', [TwoFactorController::class, 'disable'])
        ->middleware('origin.check')
        ->name('security.two-factor.disable');
    Route::put('auth/security/password', [PasswordController::class, 'update'])
        ->middleware('origin.check')
        ->name('security.password');
    Route::post('auth/security/sign-out-others', [SecurityController::class, 'signOutOthers'])
        ->middleware('origin.check')
        ->name('security.sign-out-others');
    Route::post('auth/security/sign-out-everywhere', [SecurityController::class, 'signOutEverywhere'])
        ->middleware('origin.check')
        ->name('security.sign-out-everywhere');
});
