<?php

namespace App\Routing;

use Illuminate\Routing\Router as BaseRouter;

/**
 * Companion to App\Routing\CompiledRouteCollection (release 02,
 * Defect 2): the compiled route cache file installs its routes by
 * calling setCompiledRoutes() on the router, and the stock method
 * hardcodes the framework collection class. This override installs
 * the application collection instead; matching behavior is
 * otherwise identical. Wired in AppServiceProvider.
 */
class Router extends BaseRouter
{
    /**
     * Set the compiled route collection instance.
     */
    public function setCompiledRoutes(array $routes)
    {
        $this->routes = (new CompiledRouteCollection($routes['compiled'], $routes['attributes']))
            ->setRouter($this)
            ->setContainer($this->container);

        $this->container->instance('routes', $this->routes);
    }
}
