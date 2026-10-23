<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Owner\DashboardController;
use App\Http\Controllers\Owner\PasswordController;
use App\Http\Controllers\Owner\SecurityController;
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
});

Route::middleware(['auth', 'session.lifetime'])->group(function () {
    Route::post('auth/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('auth/verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('auth/verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('auth/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('auth/dashboard', DashboardController::class)->name('dashboard');
    Route::get('auth/security', [SecurityController::class, 'show'])->name('security');
    Route::put('auth/security/password', [PasswordController::class, 'update'])
        ->middleware('origin.check')
        ->name('security.password');
    Route::post('auth/security/sign-out-others', [SecurityController::class, 'signOutOthers'])->name('security.sign-out-others');
    Route::post('auth/security/sign-out-everywhere', [SecurityController::class, 'signOutEverywhere'])->name('security.sign-out-everywhere');
});
