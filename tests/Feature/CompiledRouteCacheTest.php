<?php

namespace Tests\Feature;

use App\Routing\CompiledRouteCollection;
use App\Routing\Router;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Deploy-defect regression (release 02, Defect 2): with the
 * compiled route cache active, GET / returned 405 on the
 * subdirectory mount while every other path answered. The
 * mechanism and the fix are documented on
 * App\Routing\CompiledRouteCollection; this test pins the
 * observable contract at both mounts.
 *
 * Each test compiles the live route definitions and installs
 * the result through Router::setCompiledRoutes(), the exact
 * call the generated route cache file makes at boot, so the
 * requests below run against the compiled matcher rather than
 * the dynamic route collection.
 */
class CompiledRouteCacheTest extends TestCase
{
    use RefreshDatabase;

    private function installCompiledRoutes(): void
    {
        $router = $this->app->make('router');

        $this->assertInstanceOf(
            Router::class,
            $router,
            'the application router must be installed; the cache file calls setCompiledRoutes on it'
        );

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
        $router->setCompiledRoutes($routes->compile());

        $this->assertInstanceOf(
            CompiledRouteCollection::class,
            $router->getRoutes(),
            'the compiled collection must be the application class carrying the mount-root guard'
        );
    }

    private function assertBatteryPathsAnswer(): void
    {
        $this->get('/')->assertOk();
        $this->call('HEAD', '/')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/auth/login')->assertOk();
        $this->get('/about')->assertOk();
        $this->get('/about/')->assertOk();
        $this->get(route('health'))->assertOk();
    }

    public function test_battery_paths_answer_with_compiled_routes_at_the_subdirectory_mount(): void
    {
        $this->installCompiledRoutes();

        $this->assertBatteryPathsAnswer();
    }

    public function test_battery_paths_answer_with_compiled_routes_at_the_root_mount(): void
    {
        $this->refreshApplication();

        config(['app.url' => 'http://localhost', 'session.path' => '/']);
        URL::forceRootUrl('http://localhost');
        $this->artisan('migrate:fresh');

        $this->installCompiledRoutes();

        $this->assertBatteryPathsAnswer();
    }

    public function test_the_router_contract_still_resolves_for_type_hints(): void
    {
        $this->assertInstanceOf(Router::class, $this->app->make(Registrar::class));
    }
}
