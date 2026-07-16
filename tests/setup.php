<?php

declare(strict_types=1);

$root = sys_get_temp_dir().'/beacon-sdk-setup-'.bin2hex(random_bytes(6));
$config = $root.'/config';
mkdir($config, 0777, true);
file_put_contents($config.'/bundles.php', "<?php\n\nreturn [\n];\n");
file_put_contents($root.'/.env', "APP_ENV=dev\n");
file_put_contents($root.'/.env.example', "APP_ENV=dev\n");

$previous = getcwd();
chdir($root);
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/setup').' 2>&1', $output, $exitCode);
chdir($previous === false ? __DIR__ : $previous);

$expected = 'BEACON_ENDPOINT=https://ingest.pulseview.app';
$env = file_get_contents($root.'/.env');
$example = file_get_contents($root.'/.env.example');
$yaml = file_get_contents($config.'/packages/beacon.yaml');
$bundles = file_get_contents($config.'/bundles.php');

$ok = $exitCode === 0
    && is_string($env) && str_contains($env, $expected)
    && is_string($example) && str_contains($example, $expected)
    && is_string($yaml) && str_contains($yaml, "endpoint: '%env(BEACON_ENDPOINT)%'")
    && str_contains($yaml, 'capture_monolog_exceptions: true')
    && is_string($bundles) && str_contains($bundles, 'BeaconBundle::class');

removeTree($root);

echo ($ok ? '  ok  ' : 'FAIL  ')."setup writes official overridable endpoint\n";
exit($ok ? 0 : 1);

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path.'/'.$item;
        is_dir($child) ? removeTree($child) : unlink($child);
    }
    rmdir($path);
}
