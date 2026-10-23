<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The base-path tripwire at its REV-M1 scope (spec Section 11.4;
 * finding FB-04). The gate scans URL contexts only: the Section 1.3
 * support email and the bare domain in display copy are explicitly
 * permitted and must pass, while a hardcoded mount path in a URL
 * context and a hand-written absolute production URL must fail. The
 * fixtures are throwaway trees under the system temp directory; the
 * real repo is checked as the third case and by `composer gate`.
 */
class BasePathTripwireTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir().'/er2-tripwire-'.uniqid();
        mkdir($this->fixtureRoot.'/resources/views', 0777, true);
    }

    protected function tearDown(): void
    {
        $process = new Process(['rm', '-rf', $this->fixtureRoot]);
        $process->run();

        parent::tearDown();
    }

    private function runGateOnFixture(): Process
    {
        $process = new Process(['bash', base_path('scripts/check-base-path.sh'), $this->fixtureRoot]);
        $process->run();

        return $process;
    }

    private function writeLayout(string $markup): void
    {
        file_put_contents($this->fixtureRoot.'/resources/views/fixture.blade.php', $markup);
    }

    public function test_a_layout_carrying_the_mandated_support_email_passes(): void
    {
        $this->writeLayout(<<<'BLADE'
            <footer>
                <p>Questions? Email <a href="mailto:support@easy-refer.com">support@easy-refer.com</a>.</p>
                <p>Easy Refer, easy-refer.com</p>
                <p><a href="{{ route('login') }}">Sign in</a></p>
            </footer>
            BLADE);

        $process = $this->runGateOnFixture();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }

    public function test_a_hardcoded_mount_path_in_a_url_context_fails(): void
    {
        $this->writeLayout('<p><a href="/new/auth/login">Sign in</a></p>');

        $process = $this->runGateOnFixture();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('/new/', $process->getOutput());
    }

    public function test_a_hand_written_absolute_production_url_fails(): void
    {
        $this->writeLayout('<p><a href="https://easy-refer.com/auth/login">Sign in</a></p>');

        $process = $this->runGateOnFixture();

        $this->assertFalse($process->isSuccessful());
    }

    public function test_the_repository_itself_passes_the_rescoped_gate(): void
    {
        $process = new Process(['bash', base_path('scripts/check-base-path.sh')]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }
}
