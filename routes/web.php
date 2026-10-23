<?php

use App\Http\Controllers\Auth\AccountUnlockController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\ReferController;
use App\Http\Controllers\StaffRedemptionController;
use App\Http\Controllers\TwilioWebhookController;
use App\Http\Controllers\Owner\DashboardController;
use App\Http\Controllers\Owner\EmailChangeController;
use App\Http\Controllers\Owner\QrPageController;
use App\Http\Controllers\Owner\ReviewsController;
use App\Http\Controllers\Owner\RewardTextController;
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

// The public customer flow (spec Section 3). Static segments are
// registered before the {token} routes so they win.
Route::get('/refer/verify', [ReferController::class, 'verifyForm'])->name('refer.verify');
Route::post('/refer/verify', [ReferController::class, 'verifyCheck'])->middleware('throttle:20,1')->name('refer.verify.check');
Route::post('/refer/verify/resend', [ReferController::class, 'resend'])->name('refer.verify.resend');
Route::post('/refer/verify/change', [ReferController::class, 'changeNumber'])->name('refer.verify.change');
Route::get('/refer/share', [ReferController::class, 'share'])->name('refer.share');
Route::get('/refer/share/qr', [ReferController::class, 'shareQr'])->name('refer.share.qr');
Route::get('/refer/r/{token}', [ReferController::class, 'friendLanding'])->name('refer.friend');
Route::post('/refer/r/{token}', [ReferController::class, 'friendClaim'])->middleware('throttle:30,1')->name('refer.friend.claim');
Route::get('/refer/redeem/{token}', [ReferController::class, 'redeemPage'])->name('refer.redeem.page');
Route::get('/refer/redeem/{token}/qr', [ReferController::class, 'redeemQr'])->name('refer.redeem.qr');
Route::get('/refer/claim/{token}', [ReferController::class, 'claimPage'])->name('refer.claim.page');
Route::get('/refer/claim/{token}/qr', [ReferController::class, 'claimQr'])->name('refer.claim.qr');
Route::get('/refer/{token}', [ReferController::class, 'landing'])->name('refer.landing');
Route::post('/refer/{token}', [ReferController::class, 'enroll'])->middleware('throttle:30,1')->name('refer.enroll');

// The staff scan-and-confirm screens (spec Section 6): no auth, by
// design; the token and the per-render nonce are the controls.
Route::get('/redeem/{token}', [StaffRedemptionController::class, 'show'])->name('redeem.show');
Route::post('/redeem/{token}/confirm', [StaffRedemptionController::class, 'confirm'])->middleware('throttle:120,1')->name('redeem.confirm');

// Twilio callbacks (signature-verified in the controller).
Route::post('/webhooks/twilio/status', TwilioWebhookController::class)->name('webhooks.twilio.status');

// Public marketing (spec Section 2). The attribution middleware
// captures the first touch on these pages only (spec Section 16):
// never on /refer, never on the dashboard.
Route::middleware('attribution')->group(function () {
    Route::get('/', [MarketingController::class, 'home'])->name('home');
    Route::get('about', [MarketingController::class, 'about'])->name('about');
    Route::get('faq', [MarketingController::class, 'faq'])->name('faq');
    Route::get('legal', [MarketingController::class, 'legal'])->name('legal');
    Route::get('refund', [MarketingController::class, 'refund'])->name('refund');
    Route::get('blog', [MarketingController::class, 'blog'])->name('blog');
    Route::get('blog/{slug}', [MarketingController::class, 'blogPost'])->name('blog.post');

    // The four paid landing pages (spec Section 2.6), one template
    // and a per-vertical content map.
    Route::get('for-{vertical}', [MarketingController::class, 'vertical'])
        ->whereIn('vertical', ['barbers', 'salons', 'cafes', 'boutiques'])
        ->name('vertical');
});

// Lead capture and consent (spec Sections 2.1, 2.7, 16). Real
// endpoints with budgets and confirmation states; a dead form is a
// defect (design-system.md Section 5 item 4).
Route::post('newsletter', [LeadController::class, 'newsletter'])
    ->middleware('throttle:10,60')
    ->name('newsletter');
Route::get('newsletter/confirm/{token}', [LeadController::class, 'confirmNewsletter'])
    ->name('newsletter.confirm');
Route::post('starter-kit', [LeadController::class, 'starterKit'])
    ->middleware('throttle:10,60')
    ->name('leads.starter-kit');
Route::post('consent', [ConsentController::class, 'store'])->name('consent');

// Owner area (spec Sections 4 and 8). Public auth routes are the guest
// group below; every other /auth route sits behind the single auth
// guard group, and the route-inventory test proves none is missing.
// Sensitive state-changing endpoints additionally verify the request
// origin (spec Section 8.4).
Route::middleware(['guest', 'attribution'])->group(function () {
    Route::get('auth/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('auth/register', [RegisteredUserController::class, 'store'])
        ->middleware(['throttle:5,1', 'origin.check'])
        ->name('register.store');

    Route::get('auth/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('auth/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('origin.check')
        ->name('login.store');

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
    Route::get('auth/reviews', [ReviewsController::class, 'index'])->name('reviews');
    Route::post('auth/reviews/referrals/{referral}/release', [ReviewsController::class, 'releaseReferral'])->name('reviews.referrals.release');
    Route::post('auth/reviews/referrals/{referral}/deny', [ReviewsController::class, 'denyReferral'])->name('reviews.referrals.deny');
    Route::post('auth/reviews/rewards/{reward}/release', [ReviewsController::class, 'releaseReward'])->name('reviews.rewards.release');
    Route::post('auth/reviews/rewards/{reward}/deny', [ReviewsController::class, 'denyReward'])->name('reviews.rewards.deny');
    Route::get('auth/qr', [QrPageController::class, 'index'])->name('owner.qr');
    Route::get('auth/qr/image', [QrPageController::class, 'image'])->name('owner.qr.image');
    Route::post('auth/qr/download', [QrPageController::class, 'download'])->name('owner.qr.download');
    Route::get('auth/qr/poster/{variant}', [QrPageController::class, 'poster'])->name('owner.qr.poster');
    Route::post('auth/qr/regenerate', [QrPageController::class, 'regenerate'])->name('owner.qr.regenerate');
    Route::post('auth/reward-texts', [RewardTextController::class, 'update'])->name('owner.reward-texts');
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
