<?php

/**
 * Production entry probe for ProductionEntryOrderTest: a
 * standalone script the test runs in a subprocess, so the
 * application is created exactly the way public/index.php
 * creates it, with no test harness bootstrap in between.
 *
 * It constructs the HTTP kernel before the application is
 * bootstrapped, the order Application::handleRequest() uses,
 * records the router instance the kernel captured, then
 * bootstraps, migrates the disposable database the test points
 * it at, disables Vite the way the suite does, and handles a
 * request for the mount root through that same kernel.
 *
 * Facts are printed as key=value lines for the test to assert
 * on. The mount (root or subdirectory) follows APP_URL in the
 * environment the test passes in.
 */

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\HtmlString;

$basePath = dirname(__DIR__, 2);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';

// Production order: handleRequest() makes the kernel first;
// providers register only when handle() bootstraps the app.
$kernel = $app->make(HttpKernelContract::class);

$routerProperty = new ReflectionProperty($kernel, 'router');
$routerProperty->setAccessible(true);
$kernelRouter = $routerProperty->getValue($kernel);
$boundRouter = $app->make('router');

echo 'kernel_router_class='.get_class($kernelRouter)."\n";
echo 'kernel_router_same_as_bound='.($kernelRouter === $boundRouter ? 'yes' : 'no')."\n";
echo 'kernel_router_same_as_typed='.($kernelRouter === $app->make(Illuminate\Routing\Router::class) ? 'yes' : 'no')."\n";

// Build the request the front controller would capture and
// bind it the way handle() does internally before it
// bootstraps, so the manual bootstrap below (needed to migrate
// the disposable database before dispatch) sees the same
// state handle() would create.
$appUrl = (string) (getenv('APP_URL') ?: 'http://localhost');
$mountPath = parse_url($appUrl, PHP_URL_PATH) ?: '';
$mountPath = $mountPath === '/' ? '' : rtrim($mountPath, '/');
$scriptName = $mountPath.'/index.php';

$request = Request::create(
    'http://localhost'.$mountPath.'/',
    'GET',
    [],
    [],
    [],
    ['SCRIPT_NAME' => $scriptName, 'SCRIPT_FILENAME' => '/var/www'.$scriptName]
);

$app->instance('request', $request);

// Bootstrap through the captured kernel, then prepare the
// disposable database and the Vite stub for a real dispatch.
$kernel->bootstrap();

echo 'post_bootstrap_same_as_bound='.($kernelRouter === $app->make('router') ? 'yes' : 'no')."\n";
echo 'route_count='.count($kernelRouter->getRoutes()->getRoutes())."\n";

Artisan::call('migrate:fresh', ['--force' => true]);

$app->instance(Vite::class, new class extends Vite
{
    public function __invoke($entrypoints, $buildDirectory = null)
    {
        return new HtmlString('');
    }

    public function __call($method, $parameters)
    {
        return '';
    }

    public function __toString()
    {
        return '';
    }

    public function useIntegrityKey($key)
    {
        return $this;
    }

    public function useBuildDirectory($path)
    {
        return $this;
    }

    public function useHotFile($path)
    {
        return $this;
    }

    public function withEntryPoints($entryPoints)
    {
        return $this;
    }

    public function useScriptTagAttributes($attributes)
    {
        return $this;
    }

    public function useStyleTagAttributes($attributes)
    {
        return $this;
    }

    public function usePreloadTagAttributes($attributes)
    {
        return $this;
    }

    public function preloadedAssets()
    {
        return [];
    }
});

$response = $kernel->handle($request);

echo 'status='.$response->getStatusCode()."\n";
echo 'handled_by_kernel_router_routes='.count($kernelRouter->getRoutes()->getRoutes())."\n";
