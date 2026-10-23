<?php

namespace Tests\Feature;

use App\Routing\Router;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Production entry regression: the provider-time router swap
 * (the original Defect 2 wiring) never reached the HTTP kernel
 * the production entry builds, because handleRequest()
 * constructs the kernel before providers register. The kernel
 * captured a plain framework router with no routes and every
 * routed page answered 404 under public/index.php, while the
 * test harness, which resolves its kernel after bootstrap,
 * always saw the swapped router and passed.
 *
 * This test cannot see the defect through the harness, so it
 * runs the production entry in a subprocess (see
 * tests/Support/ProductionEntryProbe.php): a fresh application
 * from bootstrap/app.php, kernel constructed first, then a
 * real handle() of the mount root through that kernel.
 */
class ProductionEntryOrderTest extends TestCase
{
    /**
     * Run the probe at the given mount and return its facts.
     *
     * @return array<string, string>
     */
    private function runProbe(string $appUrl, string $sessionPath): array
    {
        $database = tempnam(sys_get_temp_dir(), 'er2_entry_probe_');

        $this->assertNotFalse($database, 'a disposable database file is required for the probe');

        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/ProductionEntryProbe.php')],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'APP_URL' => $appUrl,
                'SESSION_PATH' => $sessionPath,
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $database,
            ],
            null,
            120
        );

        try {
            $process->run();

            $this->assertTrue(
                $process->isSuccessful(),
                "the production entry probe must run cleanly:\n".$process->getOutput().$process->getErrorOutput()
            );

            $facts = [];

            foreach (explode("\n", $process->getOutput()) as $line) {
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', trim($line), 2);
                    $facts[$key] = $value;
                }
            }

            return $facts;
        } finally {
            @unlink($database);
        }
    }

    private function assertProductionEntryServes(array $facts): void
    {
        $this->assertSame(Router::class, $facts['kernel_router_class'] ?? null);
        $this->assertSame('yes', $facts['kernel_router_same_as_bound'] ?? null);
        $this->assertSame('yes', $facts['kernel_router_same_as_typed'] ?? null);
        $this->assertSame('yes', $facts['post_bootstrap_same_as_bound'] ?? null);
        $this->assertGreaterThan(0, (int) ($facts['route_count'] ?? 0));
        $this->assertSame($facts['route_count'] ?? null, $facts['handled_by_kernel_router_routes'] ?? null);
        $this->assertSame('200', $facts['status'] ?? null);
        $this->assertNotSame('404', $facts['status'] ?? null);
    }

    public function test_kernel_built_in_production_order_shares_the_application_router_at_the_subdirectory_mount(): void
    {
        $this->assertProductionEntryServes($this->runProbe('http://localhost/new', '/new'));
    }

    public function test_kernel_built_in_production_order_shares_the_application_router_at_the_root_mount(): void
    {
        $this->assertProductionEntryServes($this->runProbe('http://localhost', '/'));
    }
}
