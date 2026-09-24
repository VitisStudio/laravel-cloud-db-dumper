<?php

/**
 * Captures real Laravel Cloud CLI payloads and writes them, redacted, to
 * tests/fixtures/cloud/. The point is the SHAPE — which keys exist, how they
 * nest, what ids look like — so this package can be tested against what the
 * CLI actually returns instead of what someone guessed it returns.
 *
 * Every value under a sensitive key is replaced before anything is written.
 * Ids and names are kept, because their format is exactly what we need.
 *
 *   php tests/fixtures/capture.php
 *
 * Run it from a project whose organization the CLI can resolve — either set
 * LARAVEL_CLOUD_TOKEN, or run `cloud repo:config {app} --organization=<name> -n`
 * first. Nothing is sent anywhere; read tests/fixtures/cloud/ before sharing it.
 */
$binary = getenv('CLOUD_BINARY') ?: (is_executable('vendor/bin/cloud') ? 'vendor/bin/cloud' : 'cloud');
$outputDir = __DIR__.'/cloud';

// Mirrors the CLI's own SensitiveValues::isSensitiveKey(), plus the host and
// user of a database connection, which are not secret but are not ours to keep.
const SENSITIVE_KEY = '/pass|secret|token|credential|private|dsn|uri|hostname|username/i';

@mkdir($outputDir, 0755, true);

/**
 * Run a cloud command and decode its JSON, or return null with a reason.
 */
function cloud(string $binary, array $command): array
{
    $full = array_merge([$binary], $command, ['--json', '-n', '--no-ansi']);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open($full, $descriptors, $pipes, null, ['XDEBUG_MODE' => 'off'] + getenv());

    if (! is_resource($process)) {
        return ['ok' => false, 'error' => 'could not start '.$binary];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    array_map('fclose', $pipes);
    $status = proc_close($process);

    $decoded = json_decode(trim($stdout), true);

    if ($status !== 0 || ! is_array($decoded)) {
        return ['ok' => false, 'error' => trim($stderr ?: $stdout) ?: "exit {$status}"];
    }

    return ['ok' => true, 'data' => $decoded];
}

/**
 * Replace every value stored under a sensitive key, at any depth.
 */
function redact(mixed $value, ?string $key = null): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = redact($v, is_string($k) ? $k : $key);
        }

        return $out;
    }

    if ($key !== null && preg_match(SENSITIVE_KEY, $key) === 1 && $value !== null) {
        return '[redacted]';
    }

    return $value;
}

function save(string $dir, string $name, array $payload): void
{
    file_put_contents(
        "{$dir}/{$name}.json",
        json_encode(redact($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
    );

    echo "  wrote {$name}.json\n";
}

echo "Capturing from: {$binary}\n\n";

$captured = [];

// 1. Applications, with the environments the listing embeds.
echo "application:list\n";
$applications = cloud($binary, ['application:list']);

if (! $applications['ok']) {
    fwrite(STDERR, "  failed: {$applications['error']}\n");
    fwrite(STDERR, "\nIf this says the organization is ambiguous, run:\n");
    fwrite(STDERR, "  {$binary} repo:config {app} --organization=<id|name|slug> -n\n");
    exit(1);
}

save($outputDir, 'application-list', $applications['data']);
$captured[] = 'application:list';

$application = $applications['data'][0] ?? null;

if ($application === null) {
    fwrite(STDERR, "No applications in this organization; nothing further to capture.\n");
    exit(1);
}

$applicationId = (string) $application['id'];
echo "  using application {$applicationId}\n";

// 2. Environments as their own listing, to compare against the embedded ones.
echo "environment:list {$applicationId}\n";
$environments = cloud($binary, ['environment:list', $applicationId]);

if ($environments['ok']) {
    save($outputDir, 'environment-list', $environments['data']);
    $captured[] = 'environment:list';
}

// 3. One environment fetched in full. This is the payload that carries
//    databaseSchemaId, which the listings above do not.
$environmentId = (string) (($environments['ok'] ? ($environments['data'][0]['id'] ?? null) : null)
    ?? $application['environments'][0]['id']
    ?? '');

if ($environmentId !== '') {
    echo "environment:get {$environmentId}\n";
    $environment = cloud($binary, ['environment:get', $environmentId]);

    if ($environment['ok']) {
        save($outputDir, 'environment-get', $environment['data']);
        $captured[] = 'environment:get';
    }
}

// 4. Clusters, and whether the listing embeds their databases.
echo "database-cluster:list\n";
$clusters = cloud($binary, ['database-cluster:list']);

if ($clusters['ok']) {
    save($outputDir, 'database-cluster-list', $clusters['data']);
    $captured[] = 'database-cluster:list';

    $clusterId = (string) ($clusters['data'][0]['id'] ?? '');

    if ($clusterId !== '') {
        // 5. A single cluster. Credentials stay masked: the key names are all
        //    we need, and --show-sensitive is deliberately not passed.
        echo "database-cluster:get {$clusterId}\n";
        $cluster = cloud($binary, ['database-cluster:get', $clusterId]);

        if ($cluster['ok']) {
            save($outputDir, 'database-cluster-get', $cluster['data']);
            $captured[] = 'database-cluster:get';
        }

        // 6. Databases asked for directly, rather than read off the cluster.
        echo "database:list {$clusterId}\n";
        $databases = cloud($binary, ['database:list', $clusterId]);

        if ($databases['ok']) {
            save($outputDir, 'database-list', $databases['data']);
            $captured[] = 'database:list';
        }
    }
}

// 7. Token listing shape only — values are redacted by key name.
echo "auth:token --list\n";
$tokens = cloud($binary, ['auth:token', '--list']);

if ($tokens['ok']) {
    save($outputDir, 'auth-token-list', $tokens['data']);
    $captured[] = 'auth:token --list';
}

echo "\nCaptured ".count($captured)." payloads into tests/fixtures/cloud/\n";
echo "Review them before sharing — every value under a key matching\n";
echo SENSITIVE_KEY." was replaced with [redacted].\n";
