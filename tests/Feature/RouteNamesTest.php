<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Route names are unique across the table. A duplicate name is
 * not a cosmetic issue: it makes `php artisan route:cache` fail
 * on the server ("Another route has already been assigned name
 * [register]"), which the Phase 1 staging deploy hit. Guard the
 * deploy fix here permanently.
 */
class RouteNamesTest extends TestCase
{
    public function test_route_names_are_unique(): void
    {
        $seen = [];
        $duplicates = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            if (isset($seen[$name])) {
                $duplicates[] = $name;
            }

            $seen[$name] = true;
        }

        $this->assertSame([], $duplicates, 'Duplicate route names break route:cache.');
    }

    public function test_auth_post_routes_have_their_own_names(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertSame('auth/register', $routes->getByName('register.store')->uri());
        $this->assertSame('auth/login', $routes->getByName('login.store')->uri());
        $this->assertSame('auth/register', $routes->getByName('register')->uri());
        $this->assertSame('auth/login', $routes->getByName('login')->uri());
    }
}
