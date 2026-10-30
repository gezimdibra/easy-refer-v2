<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * OTP-01 (owner-findings fix wave, 2026-10-25): the owner
 * tapped the MIDDLE box of the six-box code form on his phone
 * and used the iPhone's autofill suggestion; filling started
 * at the middle box and the last digits never landed. The
 * fix, in the owner's own shape: tapping ANY of the six boxes
 * moves focus to the FIRST box immediately, so autofill
 * always starts at digit one.
 *
 * The behavioral pin (OTPFocusProbe) drives the real page and
 * the real built flow.js in the WebKit rig at 390: tap box 4,
 * focus must be on box 1; type six digits, every box must
 * hold them in order and the composed field must read the
 * full code. The static pin beside it keeps the redirect
 * handler shipping with the component: if the handler is
 * removed, the suite says so even where the rig is absent.
 * On a machine without the rig the behavioral test skips
 * rather than fails; on this machine it is part of the suite.
 */
class OtpFocusProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    /**
     * Render the verification page mid-flow (an enrollment
     * pending), with the built stylesheet and the built
     * flow.js injected: the suite disables Vite, and a probe
     * of an unstyled, scriptless page would measure nothing
     * (the PosterPrintFitTest / geometry-pin pattern).
     */
    private function renderVerifyPage(): string
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '+12125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $html = $this->get(route('refer.verify'))->assertOk()->getContent();

        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        $jsFile = $manifest['resources/js/flow.js']['file'] ?? null;
        if ($cssFile === null || $jsFile === null) {
            $this->markTestSkipped('Build manifest lacks the flow bundle; the OTP probe is unmeasured on this tree.');
        }

        $origin = rtrim((string) config('app.url'), '/');
        $html = str_replace($origin, '', $html);
        $tags = '<link rel="stylesheet" href="/build/'.$cssFile.'">'
            .'<script type="module" src="/build/'.$jsFile.'"></script>';

        return str_replace('</body>', $tags.'</body>', $html);
    }

    private function probe(): array
    {
        $rig = getenv('POSTER_RIG_DIR') ?: getenv('HOME').'/workspace/webkit-spike';
        if (! is_dir($rig.'/node_modules/playwright') || ! is_dir($rig.'/browsers')) {
            $this->markTestSkipped('WebKit rig not available at '.$rig.'; OTP focus behavior is unmeasured on this machine.');
        }
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node not on PATH; OTP focus behavior is unmeasured on this machine.');
        }

        $dir = sys_get_temp_dir().'/otp-focus-'.getmypid();
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/verify.html', $this->renderVerifyPage());

        $config = $dir.'/config.json';
        file_put_contents($config, json_encode([
            'pagesDir' => $dir,
            'publicDir' => public_path(),
            'rigDir' => $rig,
            'width' => 390,
            'shotsDir' => getenv('OTP_PROBE_SHOTS') ?: null,
        ]));

        $cmd = 'POSTER_RIG_DIR='.escapeshellarg($rig)
            .' PLAYWRIGHT_BROWSERS_PATH='.escapeshellarg($rig.'/browsers')
            .' timeout 120 '.$node.' '.escapeshellarg(__DIR__.'/../Support/otp-focus-probe.mjs')
            .' '.escapeshellarg($config).' 2>/dev/null';
        $json = (string) shell_exec($cmd);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $this->fail('OTP focus probe produced no measurement. Command: '.$cmd);
        }

        return $decoded;
    }

    public function test_tapping_a_middle_box_starts_at_the_first_box_and_typing_fills_all_six_in_order(): void
    {
        $probe = $this->probe();

        $this->assertSame(6, $probe['boxCount'], 'The verification page does not render six code boxes.');
        $this->assertSame(
            0,
            $probe['activeIndexAfterTap'],
            'Tapping box 4 left focus on box '.((int) $probe['activeIndexAfterTap'] + 1).'; a code autofilled from there loses its last digits (OTP-01).'
        );
        $this->assertSame(
            ['1', '2', '3', '4', '5', '6'],
            $probe['values'],
            'Six typed digits did not fill the six boxes in order: '.json_encode($probe['values'])
        );
        $this->assertSame('123456', $probe['composed'], 'The composed code field does not hold the full typed code.');
    }

    public function test_the_focus_redirect_ships_with_the_otp_component(): void
    {
        // AU-9: the component was extracted to the shared otp.js
        // module (the auth bundle imports it too, for the 2FA
        // challenge); flow.js must import and initialize it.
        $flow = (string) file_get_contents(resource_path('js/flow.js'));
        $this->assertStringContainsString("from './otp'", $flow, 'flow.js no longer imports the shared OTP module.');
        $this->assertStringContainsString('initOtp()', $flow, 'flow.js no longer initializes the OTP module.');

        $source = (string) file_get_contents(resource_path('js/otp.js'));
        $otpAt = strpos($source, "querySelector('[data-otp]')");
        $this->assertNotFalse($otpAt, 'The OTP component is missing from flow.js.');
        $otpBlock = substr($source, $otpAt);

        // The OTP-01 redirect: a focus listener on the boxes
        // that hands focus to boxes[0], guarded so the
        // component's own auto-advance is not mistaken for a
        // tap. If the handler goes, this pin goes red even on
        // a machine where the browser probe cannot run.
        $this->assertStringContainsString('OTP-01', $otpBlock);
        $this->assertStringContainsString("addEventListener('focus'", $otpBlock);
        $this->assertStringContainsString('boxes[0].focus()', $otpBlock);
    }
}
