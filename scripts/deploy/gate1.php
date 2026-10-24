<?php
// gate1.php — cPanel Git VC deployment task 3 (see .cpanel.yml).
//
// Gate 1 production-order probe (deploy-protocol.md) against the release
// that release-assemble.sh just assembled, BEFORE any flip. It boots the
// release through bootstrap/app.php and constructs the HTTP kernel BEFORE
// bootstrap, exactly as the production entry (public/index.php ->
// Application::handleRequest) does — the order whose violation shipped
// the release 03 all-404 defect past a green suite. The kernel's router
// must be the router the routes loaded into, and every probe path must
// return its designed status (200 for all five at the staging mount).
//
// Contract:
//   - Reads shared/deploy-state/LATEST (written by release-assemble.sh)
//     to find the release under test; assembles nothing, flips nothing.
//   - Exit 1 on ANY failure: missing state, router mismatch, zero
//     routes, or any probe status other than 200. Under cPanel's
//     abort-on-failure semantics that alone stops the deployment before
//     the flip task; in addition, exit 1 means the READY marker below
//     is never written, and flip.sh refuses to run without it.
//   - On a full pass, and only then, writes shared/deploy-state/READY
//     with the REL and HEAD it verified, for flip.sh to match against.

$stage = '/home/easyrefe/er2-staging';
$stateDir = $stage.'/shared/deploy-state';
$latestFile = $stateDir.'/LATEST';

function gate1_fail(string $msg): never
{
    fwrite(STDERR, "GATE1_FAIL: $msg\n");
    exit(1);
}

if (!is_file($latestFile)) {
    gate1_fail("no assembly state at $latestFile (release-assemble.sh did not run or did not finish)");
}
$state = parse_ini_file($latestFile);
$rel = $state['REL'] ?? '';
$head = $state['HEAD'] ?? '';
if ($rel === '' || $head === '') {
    gate1_fail('assembly state is missing REL or HEAD');
}
$base = $stage.'/releases/'.$rel;
if (!is_dir($base)) {
    gate1_fail("release dir missing: $base");
}
echo "GATE1_RELEASE=$rel\n";
echo "GATE1_HEAD=$head\n";

// The release on disk must be the assembly the state file describes.
$cloneHead = trim((string) shell_exec('git -C /home/easyrefe/repos/easy-refer-v2 rev-parse HEAD'));
if ($cloneHead !== $head) {
    gate1_fail("clone HEAD ($cloneHead) does not match assembled HEAD ($head)");
}

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';

// Construct the kernel BEFORE bootstrap: the production entry order.
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$ref = new ReflectionProperty($kernel, 'router');
$ref->setAccessible(true);
$kernelRouter = $ref->getValue($kernel);
$boundRouter = $app->make('router');
$same = $kernelRouter === $boundRouter;
echo 'kernel_router_same_as_bound='.($same ? 'yes' : 'no').PHP_EOL;
if (!$same) {
    gate1_fail('kernel router is not the bound router (release 03 defect shape)');
}

$first = true;
foreach (['/', '/up', '/auth/login', '/sitemap.xml', '/robots.txt'] as $p) {
    $url = 'https://easy-refer.com/new'.($p === '/' ? '/' : $p);
    $r = Illuminate\Http\Request::create($url, 'GET', [], [], [], [
        'SCRIPT_NAME' => '/new/index.php',
        'SCRIPT_FILENAME' => '/home/easyrefe/public_html/new/index.php',
    ]);
    $app->instance('request', $r);
    if ($first) {
        $kernel->bootstrap();
        $count = app('router')->getRoutes()->count();
        echo 'kernel_route_count='.$count.PHP_EOL;
        if ($count === 0) {
            gate1_fail('router has zero routes after bootstrap');
        }
        $first = false;
    }
    $resp = $kernel->handle($r);
    $status = $resp->getStatusCode();
    echo $p.' -> '.$status.PHP_EOL;
    $kernel->terminate($r, $resp);
    if ($status !== 200) {
        gate1_fail("probe $p returned $status, designed 200");
    }
}

// Full pass: the READY marker is the only thing that lets flip.sh run.
file_put_contents($stateDir.'/READY', "REL=$rel\nHEAD=$head\nVERIFIED_UTC=".gmdate('Y-m-d\TH:i:s\Z')."\n");
echo "READY_MARKER_WRITTEN\n";
echo "GATE1_DONE\n";
