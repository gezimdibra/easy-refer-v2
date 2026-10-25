<?php

use App\Http\Controllers\Auth\AccountUnlockController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Admin\AdminConsoleController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminTwoFactorController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\ReferController;
use App\Http\Controllers\StaffRedemptionController;
use App\Http\Controllers\SquareWebhookController;
use App\Http\Controllers\TwilioWebhookController;
use App\Http\Controllers\Owner\BillingCardController;
use App\Http\Controllers\Owner\DashboardController;
use App\Http\Controllers\Owner\EmailChangeController;
use App\Http\Controllers\Owner\QrPageController;
use App\Http\Controllers\Owner\ReviewsController;
use App\Http\Controllers\Owner\RewardTextController;
use App\Http\Controllers\Owner\PasswordController;
use App\Http\Controllers\Owner\CustomersController;
use App\Http\Controllers\Owner\ReferralsController;
use App\Http\Controllers\Owner\RewardsController;
use App\Http\Controllers\Owner\SecurityController;
use App\Http\Controllers\Owner\SettingsController;
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

// Square callbacks (signature-verified in the controller, spec Section 9.4).
Route::post('/webhooks/square', SquareWebhookController::class)->name('webhooks.square');

// Crawler files (landing-page-spec.md Section 7): generated from
// the route table and the app URL config, correct at both mounts.
Route::get('sitemap.xml', [MarketingController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [MarketingController::class, 'robots'])->name('robots');

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

Route::middleware(['auth', 'session.lifetime', '2fa', 'owner.open'])->group(function () {
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
    Route::post('auth/dashboard/checklist/dismiss', [DashboardController::class, 'dismissChecklist'])
        ->middleware('origin.check')
        ->name('dashboard.checklist.dismiss');
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

    // Customers, Referrals, Rewards (spec Sections 5.2 to 5.4).
    Route::get('auth/customers', [CustomersController::class, 'index'])->name('customers.index');
    Route::get('auth/customers/add', [CustomersController::class, 'create'])->name('customers.create');
    Route::post('auth/customers', [CustomersController::class, 'store'])
        ->middleware('origin.check')
        ->name('customers.store');
    Route::get('auth/customers/{membership}', [CustomersController::class, 'show'])->name('customers.show');
    Route::delete('auth/customers/{membership}', [CustomersController::class, 'destroy'])
        ->middleware('origin.check')
        ->name('customers.destroy');
    Route::get('auth/referrals', [ReferralsController::class, 'index'])->name('referrals.index');
    Route::get('auth/referrals/{referral}', [ReferralsController::class, 'show'])->name('referrals.show');
    Route::post('auth/referrals/{referral}/regenerate', [ReferralsController::class, 'regenerate'])
        ->middleware('origin.check')
        ->name('referrals.regenerate');
    Route::get('auth/rewards', [RewardsController::class, 'index'])->name('rewards.index');
    Route::post('auth/rewards/{reward}/resend', [RewardsController::class, 'resend'])
        ->middleware('origin.check')
        ->name('rewards.resend');

    // Settings (spec Section 5.5): an index of separate pages, one
    // job each. The Security page above is the Settings security
    // leg; it keeps its established routes and URL.
    Route::get('auth/settings', [SettingsController::class, 'index'])->name('settings');
    Route::get('auth/settings/profile', [SettingsController::class, 'profile'])->name('settings.profile');
    Route::post('auth/settings/profile', [SettingsController::class, 'updateProfile'])
        ->middleware('origin.check')
        ->name('settings.profile.update');
    Route::get('auth/settings/rewards', [SettingsController::class, 'rewards'])->name('settings.rewards');
    Route::post('auth/settings/rewards', [SettingsController::class, 'updateRewards'])
        ->middleware('origin.check')
        ->name('settings.rewards.update');
    Route::get('auth/settings/value', [SettingsController::class, 'value'])->name('settings.value');
    Route::post('auth/settings/value', [SettingsController::class, 'updateValue'])
        ->middleware('origin.check')
        ->name('settings.value.update');
    Route::get('auth/settings/reviews', [SettingsController::class, 'reviews'])->name('settings.reviews');
    Route::post('auth/settings/reviews', [SettingsController::class, 'updateReviews'])
        ->middleware('origin.check')
        ->name('settings.reviews.update');
    Route::get('auth/settings/notifications', [SettingsController::class, 'notifications'])->name('settings.notifications');
    Route::post('auth/settings/notifications', [SettingsController::class, 'updateNotifications'])
        ->middleware('origin.check')
        ->name('settings.notifications.update');
    Route::get('auth/settings/limits', [SettingsController::class, 'limits'])->name('settings.limits');
    Route::post('auth/settings/limits', [SettingsController::class, 'updateLimits'])
        ->middleware('origin.check')
        ->name('settings.limits.update');
    Route::get('auth/settings/billing', [SettingsController::class, 'billing'])->name('settings.billing');
    Route::get('auth/settings/billing/{record}', [SettingsController::class, 'statement'])->name('settings.billing.statement');
    Route::post('auth/settings/billing/card', [BillingCardController::class, 'store'])
        ->middleware('origin.check')
        ->name('settings.billing.card.store');
    Route::post('auth/settings/billing/card/replace', [BillingCardController::class, 'replace'])
        ->middleware(['origin.check', 'step-up'])
        ->name('settings.billing.card.replace');
    Route::post('auth/settings/billing/card/remove', [BillingCardController::class, 'remove'])
        ->middleware(['origin.check', 'step-up'])
        ->name('settings.billing.card.remove');
    Route::get('auth/settings/account', [SettingsController::class, 'account'])->name('settings.account');
    Route::post('auth/settings/checklist/reset', [SettingsController::class, 'resetChecklist'])
        ->middleware('origin.check')
        ->name('settings.checklist.reset');
    Route::post('auth/settings/account/close', [SettingsController::class, 'closeAccount'])
        ->middleware(['origin.check', 'step-up'])
        ->name('settings.account.close');
});

// Platform admin console (spec Section 15): a separate guard, a
// separate sign-in, and mandatory two-factor enforced by the
// admin.2fa middleware on every route in the group, including
// the 2FA surfaces themselves (which are the only ones reachable
// before the binding is satisfied).
Route::prefix('admin')->group(function () {
    Route::get('login', [AdminLoginController::class, 'show'])->name('admin.login');
    // No route-level throttle here on purpose: the controller's
    // own limiter is the enforcement, so every failed attempt,
    // including ones refused by the lockout itself, reaches the
    // audit write (security verifier F-08). A middleware 429
    // would swallow exactly the most attack-indicative attempts.
    Route::post('login', [AdminLoginController::class, 'store'])
        ->middleware(['origin.check'])
        ->name('admin.login.store');

    Route::middleware(['auth:admin', 'admin.2fa'])->group(function () {
        Route::post('logout', [AdminLoginController::class, 'destroy'])
            ->middleware('origin.check')
            ->name('admin.logout');
        Route::get('two-factor/setup', [AdminTwoFactorController::class, 'setup'])->name('admin.two-factor.setup');
        Route::post('two-factor/start', [AdminTwoFactorController::class, 'start'])
            ->middleware('origin.check')
            ->name('admin.two-factor.start');
        Route::post('two-factor/confirm', [AdminTwoFactorController::class, 'confirm'])
            ->middleware(['throttle:5,1', 'origin.check'])
            ->name('admin.two-factor.confirm');
        Route::get('two-factor/challenge', [AdminTwoFactorController::class, 'challenge'])->name('admin.two-factor.challenge');
        Route::post('two-factor/challenge', [AdminTwoFactorController::class, 'verifyChallenge'])
            ->middleware(['throttle:5,1', 'origin.check'])
            ->name('admin.two-factor.challenge.store');

        Route::get('/', [AdminConsoleController::class, 'home'])->name('admin.home');
        Route::get('businesses', [AdminConsoleController::class, 'businesses'])->name('admin.businesses');
        Route::get('businesses/{business}', [AdminConsoleController::class, 'business'])->name('admin.businesses.show');
        Route::post('businesses/{business}/suspend', [AdminConsoleController::class, 'suspend'])
            ->middleware('origin.check')
            ->name('admin.businesses.suspend');
        Route::post('businesses/{business}/reinstate', [AdminConsoleController::class, 'reinstate'])
            ->middleware('origin.check')
            ->name('admin.businesses.reinstate');
        Route::get('risk-feed', [AdminConsoleController::class, 'riskFeed'])->name('admin.risk-feed');
        Route::get('holds', [AdminConsoleController::class, 'holds'])->name('admin.holds');
        Route::get('audit', [AdminConsoleController::class, 'audit'])->name('admin.audit');
    });
});
