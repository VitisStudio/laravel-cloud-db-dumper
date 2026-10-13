<?php

use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
use VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget;
use VitisStudio\LaravelCloudDbDumper\Cloud\PushGuard;
use VitisStudio\LaravelCloudDbDumper\Cloud\TargetNavigator;
use VitisStudio\LaravelCloudDbDumper\Restorers\RestoreManager;

function pushTarget(string $environment, string $cluster = 'cluster', string $type = 'neon_serverless_postgres', ?string $hostname = null): DatabaseTarget
{
    $target = new DatabaseTarget(
        applicationId: 'app-1',
        applicationName: 'App',
        environmentId: 'env-'.$environment,
        environmentName: $environment,
        clusterId: 'shared-id',
        clusterName: $cluster,
        clusterType: $type,
        schemaName: 'main',
    );

    return $hostname === null ? $target : $target->withConnection([
        'protocol' => 'pgsql',
        'hostname' => $hostname,
        'port' => 5432,
        'username' => 'user',
        'password' => 'secret',
    ]);
}

it('accepts a separate non-production destination', function () {
    PushGuard::assertDestination(pushTarget('prod'), pushTarget('staging'));
    PushGuard::assertDistinctConnections(
        pushTarget('prod', hostname: 'ep-one.aws.neon.tech'),
        pushTarget('staging', hostname: 'ep-two.aws.neon.tech'),
    );
})->throwsNoExceptions();

it('refuses to push into production', function (string $name) {
    PushGuard::assertDestination(pushTarget('staging'), pushTarget($name));
})->with(['prod', 'production', 'Production'])->throws(RuntimeException::class, 'named as production');

it('refuses to push an environment into itself', function () {
    PushGuard::assertDestination(pushTarget('staging'), pushTarget('staging'));
})->throws(RuntimeException::class, 'same environment');

it('refuses to push into MySQL', function () {
    PushGuard::assertDestination(pushTarget('prod', type: 'laravel_mysql_8'), pushTarget('staging', type: 'laravel_mysql_8'));
})->throws(RuntimeException::class, 'Postgres clusters only');

it('refuses a destination that resolves to the source database', function () {
    PushGuard::assertDistinctConnections(
        pushTarget('prod', hostname: 'ep-one.aws.neon.tech'),
        pushTarget('staging', hostname: 'EP-ONE.aws.neon.tech'),
    );
})->throws(RuntimeException::class, 'resolves to the source database');

it('refuses to compare targets without credentials', function () {
    PushGuard::assertDistinctConnections(pushTarget('prod'), pushTarget('staging'));
})->throws(RuntimeException::class, 'need credentials');

it('stops when a cluster id answers with a different cluster', function () {
    $cloud = Mockery::mock(CloudCli::class);
    $cloud->shouldReceive('clusterWithCredentials')->with('shared-id')->andReturn([
        'id' => 'shared-id',
        'name' => 'fieldops_prod',
        'connection' => ['hostname' => 'ep-one.aws.neon.tech', 'port' => 5432, 'username' => 'user', 'password' => 'secret'],
    ]);

    (new TargetNavigator($cloud))->attachCredentials(pushTarget('staging', cluster: 'staging-copy'));
})->throws(RuntimeException::class, 'got "fieldops_prod" back');

it('asks psql for TLS when the connection names an sslmode', function () {
    $config = ['driver' => 'pgsql', 'host' => 'h', 'port' => 5432, 'database' => 'd', 'username' => 'u', 'password' => 'p'];

    [, $local] = (new RestoreManager($config))->postgresRestoreCommand('/tmp/dump.sql');
    [, $cloud] = (new RestoreManager($config + ['sslmode' => 'require']))->postgresRestoreCommand('/tmp/dump.sql');

    expect($local)->toBe(['PGPASSWORD' => 'p'])
        ->and($cloud)->toBe(['PGPASSWORD' => 'p', 'PGSSLMODE' => 'require']);
});
