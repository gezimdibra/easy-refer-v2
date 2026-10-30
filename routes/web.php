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
use App\Http\Controllers\JoinController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\OgCardController;
use App\Http\Controllers\ReferController;
use App\Http\Controllers\StaffRedemptionController;
use App\Http\Controllers\SquareWebhookController;
use App\Http\Controllers\TwilioWebhookController;
use App\Http\Controllers\Owner\ActionCenterController;
use App\Http\Controllers\Owner\UndoController;
use App\Http\Controllers\Owner\BillingCardController;
use App\Http\Controllers\Owner\CampaignsController;
use App\Http\Controllers\Owner\DashboardController;
use App\Http\Controllers\Owner\OnboardingController;
use App\Http\Controllers\Owner\EmailChangeController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\Owner\CouponsController;
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
Route::get('/refer/og/{slug}/{version}.png', [OgCardController::class, 'show'])->name('refer.og.card');
Route::get('/refer/share/qr', [ReferController::class, 'shareQr'])->name('refer.share.qr');
Route::get('/refer/r/{token}', [ReferController::class, 'friendLanding'])->name('refer.friend');
Route::post('/refer/r/{token}', [ReferController::class, 'friendClaim'])->middleware('throttle:30,1')->name('refer.friend.claim');
Route::get('/refer/redeem/{token}', [ReferController::class, 'redeemPage'])->name('refer.redeem.page');
Route::get('/refer/redeem/{token}/qr', [ReferController::class, 'redeemQr'])->name('refer.redeem.qr');
// ID-05 (Amendment 48): the review ask's click routes — the
// tap is recorded once per receipt, then redirected to the
// business's current Google review link.
Route::get('/refer/redeem/{token}/review', [ReferController::class, 'redeemReviewRedirect'])->name('refer.redeem.review');
Route::get('/refer/claim/{token}/review', [ReferController::class, 'claimReviewRedirect'])->name('refer.claim.review');
Route::get('/refer/claim/{token}', [ReferController::class, 'claimPage'])->name('refer.claim.page');
Route::get('/refer/claim/{token}/qr', [ReferController::class, 'claimQr'])->name('refer.claim.qr');
Route::get('/join/{slug}', [JoinController::class, 'show'])->name('join.show');
// ID-11 direct coupons (S3): the batch doorway and the claim
// page. The verification step itself rides the shipped
// /refer/verify routes with pending kind "coupon".
Route::get('/coupon/{token}', [CouponController::class, 'landing'])->name('coupon.landing');
Route::post('/coupon/{token}', [CouponController::class, 'start'])->middleware('throttle:30,1')->name('coupon.start');
Route::get('/coupon/claim/{token}', [CouponController::class, 'claimPage'])->name('coupon.claim.page');
Route::get('/coupon/claim/{token}/qr', [CouponController::class, 'claimQr'])->name('coupon.claim.qr');
Route::get('/refer/{token}', [ReferController::class, 'landing'])->name('refer.landing');
Route::post('/refer/{token}', [ReferController::class, 'enroll'])->middleware('throttle:30,1')->name('refer.enroll');

// The G6 demo (Amendment 63): the landing-embedded real-flow
// demo on the flagged fictional business. Its own session, its
// own gateway, its own guard; it never touches the real flow's
// routes above.
Route::get('/demo', [\App\Http\Controllers\DemoController::class, 'show'])->name('demo.show');
Route::post('/demo/start', [\App\Http\Controllers\DemoController::class, 'start'])->middleware('throttle:60,1')->name('demo.start');
Route::get('/demo/claim', [\App\Http\Controllers\DemoController::class, 'claim'])->name('demo.claim');
Route::post('/demo/claim', [\App\Http\Controllers\DemoController::class, 'claimNext'])->name('demo.claim.next');
Route::get('/demo/form', [\App\Http\Controllers\DemoController::class, 'form'])->name('demo.form');
Route::post('/demo/form', [\App\Http\Controllers\DemoController::class, 'submitForm'])->middleware('throttle:30,1')->name('demo.form.submit');
Route::get('/demo/verify', [\App\Http\Controllers\DemoController::class, 'verifyForm'])->name('demo.verify');
Route::post('/demo/verify', [\App\Http\Controllers\DemoController::class, 'verifyCheck'])->middleware('throttle:30,1')->name('demo.verify.check');
Route::post('/demo/verify/new-code', [\App\Http\Controllers\DemoController::class, 'newCode'])->name('demo.verify.new');
Route::post('/demo/verify/change', [\App\Http\Controllers\DemoController::class, 'changeNumber'])->name('demo.verify.change');
Route::get('/demo/reward', [\App\Http\Controllers\DemoController::class, 'reward'])->name('demo.reward');
Route::get('/demo/reward/qr/{token}', [\App\Http\Controllers\DemoController::class, 'qr'])->name('demo.qr');
Route::get('/demo/counter/{token}', [\App\Http\Controllers\DemoController::class, 'counter'])->name('demo.counter');
Route::post('/demo/counter/{token}/confirm', [\App\Http\Controllers\DemoController::class, 'counterConfirm'])->middleware('throttle:120,1')->name('demo.counter.confirm');
Route::get('/demo/coda', [\App\Http\Controllers\DemoController::class, 'coda'])->name('demo.coda');
Route::post('/demo/exit', [\App\Http\Controllers\DemoController::class, 'exit'])->name('demo.exit');

// The G1 claimable shop pages (g1-claimable-design-pass.md):
// one private token URL per shop, noindex by construction (H10),
// never linked from any public page. The preview poster's code
// resolves ONLY to the page itself (H4, inert).
Route::get('/claim/{token}', [\App\Http\Controllers\ClaimableController::class, 'show'])->name('claimable.show');
Route::get('/claim/{token}/poster', [\App\Http\Controllers\ClaimableController::class, 'poster'])->name('claimable.poster');
Route::get('/claim/{token}/qr', [\App\Http\Controllers\ClaimableController::class, 'qr'])->name('claimable.qr');
Route::get('/claim/{token}/demo', [\App\Http\Controllers\ClaimableController::class, 'demo'])->name('claimable.demo');
Route::post('/claim/{token}/demo/complete', [\App\Http\Controllers\ClaimableController::class, 'demoComplete'])->name('claimable.demo.complete');
Route::get('/claim/{token}/claim', [\App\Http\Controllers\ClaimableController::class, 'claimStart'])->name('claimable.claim');
Route::post('/claim/{token}/gate/send', [\App\Http\Controllers\ClaimableController::class, 'gateSend'])->middleware('throttle:20,1')->name('claimable.gate.send');
Route::post('/claim/{token}/gate/check', [\App\Http\Controllers\ClaimableController::class, 'gateCheck'])->middleware('throttle:20,1')->name('claimable.gate.check');
Route::post('/claim/{token}/review', [\App\Http\Controllers\ClaimableController::class, 'reviewRequest'])->middleware('throttle:10,1')->name('claimable.review');
Route::post('/claim/{token}/remove', [\App\Http\Controllers\ClaimableController::class, 'remove'])->name('claimable.remove');
Route::get('/claim/{token}/contested', [\App\Http\Controllers\ClaimableController::class, 'contestedForm'])->name('claimable.contested');
Route::post('/claim/{token}/contested', [\App\Http\Controllers\ClaimableController::class, 'contestedStore'])->middleware('throttle:10,1')->name('claimable.contested.store');
Route::get('/claim-handoff/{token}', [\App\Http\Controllers\ClaimableController::class, 'handoff'])->name('claimable.handoff');

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

    // The onboarding front door (P4, OB-U1): a focused flow in
    // the signup wizard's grammar. Welcome and Finish are
    // bookends outside the five working steps; the card POST is
    // the flow's own save behind the shared CardOnFileService.
    Route::get('auth/get-started', [OnboardingController::class, 'welcome'])->name('onboarding.welcome');
    Route::get('auth/get-started/finish', [OnboardingController::class, 'finish'])->name('onboarding.finish');
    Route::post('auth/get-started/complete', [OnboardingController::class, 'complete'])
        ->middleware('origin.check')
        ->name('onboarding.complete');
    // RS2-3 (Amendment 44): the Finish screen's optional
    // first-visit value save (its own small form, never folded
    // into the door forms).
    Route::post('auth/get-started/value', [OnboardingController::class, 'storeValue'])
        ->middleware('origin.check')
        ->name('onboarding.value');
    // SK-6 (Amendment 67): the Finish screen's optional
    // "how did you hear" save (its own small form, like the
    // value save; never folded into the door forms).
    Route::post('auth/get-started/source', [OnboardingController::class, 'storeSource'])
        ->middleware('origin.check')
        ->name('onboarding.source');
    Route::post('auth/get-started/card', [OnboardingController::class, 'storeCard'])
        ->middleware('origin.check')
        ->name('onboarding.card.store');
    // RS2-2: the Location step's save (the address that used to
    // be signup Step 4, collected here since the checkpoint).
    Route::post('auth/get-started/location', [OnboardingController::class, 'storeLocation'])
        ->middleware('origin.check')
        ->name('onboarding.location.store');
    Route::get('auth/get-started/{step}', [OnboardingController::class, 'step'])->name('onboarding.step');
    Route::post('auth/dashboard/checklist/dismiss', [DashboardController::class, 'dismissChecklist'])
        ->middleware('origin.check')
        ->name('dashboard.checklist.dismiss');
    Route::get('auth/actions', [ActionCenterController::class, 'index'])->name('actions.index');
    Route::post('auth/actions/{key}/dismiss', [ActionCenterController::class, 'dismiss'])
        ->middleware('origin.check')
        ->name('actions.dismiss');
    // RS-14: the one Undo endpoint behind every reversible
    // action's success toast (token in the POST body only).
    Route::post('auth/actions/undo', [UndoController::class, 'store'])
        ->middleware('origin.check')
        ->name('actions.undo');
    Route::get('auth/security', [SecurityController::class, 'show'])->name('security');
    Route::get('auth/reviews', [ReviewsController::class, 'index'])->name('reviews');
    Route::post('auth/reviews/referrals/{referral}/release', [ReviewsController::class, 'releaseReferral'])->name('reviews.referrals.release');
    Route::post('auth/reviews/referrals/{referral}/deny', [ReviewsController::class, 'denyReferral'])->name('reviews.referrals.deny');
    Route::post('auth/reviews/rewards/{reward}/release', [ReviewsController::class, 'releaseReward'])->name('reviews.rewards.release');
    Route::post('auth/reviews/rewards/{reward}/deny', [ReviewsController::class, 'denyReward'])->name('reviews.rewards.deny');
    Route::get('auth/qr', [QrPageController::class, 'index'])->name('owner.qr');
    Route::get('auth/qr/image', [QrPageController::class, 'image'])->name('owner.qr.image');
    Route::get('auth/qr/image/{placement}', [QrPageController::class, 'imagePlacement'])->name('owner.qr.image.placement');
    Route::post('auth/qr/download', [QrPageController::class, 'download'])->name('owner.qr.download');
    Route::get('auth/qr/poster/{variant}', [QrPageController::class, 'poster'])->name('owner.qr.poster');
    Route::post('auth/qr/regenerate', [QrPageController::class, 'regenerate'])->name('owner.qr.regenerate');
    Route::post('auth/qr/placements/{placement}/regenerate', [QrPageController::class, 'regeneratePlacement'])->name('owner.qr.placement.regenerate');
    Route::post('auth/qr/placements/{placement}/rename', [QrPageController::class, 'renamePlacement'])->name('owner.qr.placement.rename');
    Route::post('auth/qr/coaching/dismiss', [QrPageController::class, 'dismissCoaching'])->name('owner.qr.coaching.dismiss');
    // ID-11 coupons (Amendment 53): the secondary Coupons tab
    // on the QR page. Static segments register before the
    // {batch} routes so they win; every {batch} and {claim}
    // parameter is implicit model binding (F-1).
    Route::get('auth/qr/coupons', [CouponsController::class, 'index'])->name('owner.qr.coupons');
    Route::post('auth/qr/coupons', [CouponsController::class, 'store'])
        ->middleware('origin.check')
        ->name('owner.qr.coupons.store');
    Route::post('auth/qr/coupons/hint/dismiss', [CouponsController::class, 'dismissHint'])
        ->middleware('origin.check')
        ->name('owner.qr.coupons.hint.dismiss');
    Route::get('auth/qr/coupons/{batch}/done', [CouponsController::class, 'done'])->name('owner.qr.coupons.done');
    Route::post('auth/qr/coupons/{batch}/kill', [CouponsController::class, 'kill'])
        ->middleware('origin.check')
        ->name('owner.qr.coupons.kill');
    Route::post('auth/qr/coupons/{batch}/flag-dismiss', [CouponsController::class, 'dismissFlag'])
        ->middleware('origin.check')
        ->name('owner.qr.coupons.flag.dismiss');
    Route::get('auth/qr/coupons/{batch}/flyer', [CouponsController::class, 'flyer'])->name('owner.qr.coupons.flyer');
    Route::get('auth/qr/coupons/{batch}/flyer/image', [CouponsController::class, 'flyerImage'])->name('owner.qr.coupons.flyer.image');
    Route::get('auth/qr/coupons/{batch}/claims', [CouponsController::class, 'claims'])->name('owner.qr.coupons.claims');
    Route::post('auth/qr/coupons/{batch}/claims/{claim}/void', [CouponsController::class, 'voidClaim'])
        ->middleware('origin.check')
        ->name('owner.qr.coupons.claims.void');
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
    Route::get('auth/security/two-factor/qr', [TwoFactorController::class, 'qr'])
        ->name('security.two-factor.qr');
    Route::post('auth/security/two-factor/confirm', [TwoFactorController::class, 'confirm'])
        ->middleware('origin.check')
        ->name('security.two-factor.confirm.store');
    Route::post('auth/security/two-factor/disable', [TwoFactorController::class, 'disable'])
        ->middleware('origin.check')
        ->name('security.two-factor.disable');
    Route::put('auth/security/password', [PasswordController::class, 'update'])
        // Throttled like the step-up endpoint above (throttle:5,1):
        // this route verifies the current password, so failed
        // attempts must be rate-limited, never unthrottled.
        ->middleware(['throttle:5,1', 'origin.check'])
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
    Route::get('auth/customers/{membership}/edit', [CustomersController::class, 'edit'])->name('customers.edit');
    Route::put('auth/customers/{membership}', [CustomersController::class, 'update'])
        ->middleware('origin.check')
        ->name('customers.update');
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

    // Bulk SMS campaigns (spec Section 10.3): compose saves a
    // draft, the confirm screen is the only send path, and the
    // send recomputes the audience server-side. Compose is
    // defined before the {campaign} routes so it never binds
    // as an id.
    Route::get('auth/campaigns', [CampaignsController::class, 'index'])->name('campaigns.index');
    Route::get('auth/campaigns/compose', [CampaignsController::class, 'create'])->name('campaigns.create');
    Route::post('auth/campaigns/estimate', [CampaignsController::class, 'estimate'])
        ->middleware('throttle:60,1')
        ->name('campaigns.estimate');
    Route::post('auth/campaigns', [CampaignsController::class, 'store'])
        ->middleware('origin.check')
        ->name('campaigns.store');
    Route::post('auth/campaigns/preset', [CampaignsController::class, 'preset'])
        ->middleware('origin.check')
        ->name('campaigns.preset');
    Route::post('auth/campaigns/quiet-day', [CampaignsController::class, 'quietDay'])
        ->middleware('origin.check')
        ->name('campaigns.quiet-day');
    Route::post('auth/campaigns/{campaign}/duplicate', [CampaignsController::class, 'duplicate'])
        ->middleware('origin.check')
        ->name('campaigns.duplicate');
    // ID-02 (Amendment 48): the recognition card's action and
    // the scoped editor — the only doors a recognition draft
    // has besides its confirm.
    Route::post('auth/campaigns/recognition', [CampaignsController::class, 'recognition'])
        ->middleware('origin.check')
        ->name('campaigns.recognition');
    Route::get('auth/campaigns/{campaign}/recognition-edit', [CampaignsController::class, 'recognitionEdit'])->name('campaigns.recognition.edit');
    Route::post('auth/campaigns/{campaign}/recognition-edit', [CampaignsController::class, 'recognitionUpdate'])
        ->middleware('origin.check')
        ->name('campaigns.recognition.update');
    Route::get('auth/campaigns/{campaign}/confirm', [CampaignsController::class, 'confirm'])->name('campaigns.confirm');
    Route::post('auth/campaigns/{campaign}/send', [CampaignsController::class, 'send'])
        ->middleware('origin.check')
        ->name('campaigns.send');
    Route::post('auth/campaigns/{campaign}/resend', [CampaignsController::class, 'resend'])
        ->middleware('origin.check')
        ->name('campaigns.resend');
    Route::get('auth/campaigns/{campaign}', [CampaignsController::class, 'show'])->name('campaigns.show');
    Route::get('auth/campaigns/{campaign}/status', [CampaignsController::class, 'status'])->name('campaigns.status');
    Route::delete('auth/campaigns/{campaign}', [CampaignsController::class, 'destroy'])
        ->middleware('origin.check')
        ->name('campaigns.destroy');

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

// Error context (gap pack 06, ERR-3): unmatched paths render the
// branded 404 from inside the web middleware group, so the session
// has run and the auth state is real when the view chooses its
// context (app vs public). Without this, a 404 raised at routing
// time reaches the exception renderer before any middleware has
// run, and a signed-in owner's context cannot be known reliably.
Route::fallback(function (\Illuminate\Http\Request $request) {
    // A URI that exists under another method is a 405, not a 404:
    // the fallback must not swallow MethodNotAllowed (pinned by
    // LogoutTest and MarketingTest).
    $allowed = [];
    foreach (app('router')->getRoutes() as $route) {
        if (str_contains($route->uri(), 'fallbackPlaceholder')) {
            continue;
        }
        if ($route->matches($request, false)) {
            $allowed = array_merge($allowed, $route->methods());
        }
    }
    $allowed = array_values(array_diff(array_unique($allowed), [$request->method(), 'HEAD']));
    if ($allowed !== []) {
        throw new \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException($allowed);
    }

    return response()->view('errors.404', [], 404);
});
