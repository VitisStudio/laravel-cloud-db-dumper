<?php

use Illuminate\Support\Facades\Process;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
use VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget;
use VitisStudio\LaravelCloudDbDumper\Cloud\TargetNavigator;

function clustersFixture(): array
{
    return [
        [
            'id' => 'cluster-a',
            'name' => 'cluster-a',
            'type' => 'mysql',
            'schemas' => [
                ['id' => 'schema-1', 'name' => 'app_one'],
                ['id' => 'schema-2', 'name' => 'app_two'],
            ],
        ],
        [
            'id' => 'cluster-b',
            'name' => 'cluster-b',
            'type' => 'postgres',
            'schemas' => [
                ['id' => 'schema-9', 'name' => 'forge'],
            ],
        ],
    ];
}

it('resolves the cluster that owns a schema id', function () {
    $navigator = new TargetNavigator(new CloudCli('cloud'));

    $cluster = $navigator->resolveCluster(clustersFixture(), 'schema-9');

    expect($cluster['id'])->toBe('cluster-b');
});

it('throws when no cluster owns the schema id', function () {
    $navigator = new TargetNavigator(new CloudCli('cloud'));

    $navigator->resolveCluster(clustersFixture(), 'missing-schema');
})->throws(RuntimeException::class);

it('throws when the schema id is empty', function () {
    $navigator = new TargetNavigator(new CloudCli('cloud'));

    $navigator->resolveCluster(clustersFixture(), '');
})->throws(RuntimeException::class);

it('retries with the saved organization token when the cli cannot choose one', function () {
    $cluster = [
        'id' => 'cluster-b',
        'name' => 'cluster-b',
        'type' => 'postgres',
        'connection' => [
            'protocol' => 'postgresql',
            'hostname' => 'db.example.com',
            'port' => 5432,
            'username' => 'admin',
            'password' => 'secret',
        ],
    ];

    Process::fake([
        '*auth:token*' => Process::result(json_encode([
            ['token' => '1|aaa', 'source' => 'config.json', 'organization' => 'Vitis Studio'],
            ['token' => '2|bbb', 'source' => 'config.json', 'organization' => 'Sidecar'],
        ])),
        '*database-cluster:get*' => Process::sequence()
            ->push(Process::result(
                output: '',
                errorOutput: json_encode(['error' => true, 'message' => 'Multiple API tokens found.']),
                exitCode: 1,
            ))
            ->push(Process::result(json_encode($cluster))),
    ]);

    $navigator = new TargetNavigator(new CloudCli);

    $target = new DatabaseTarget(
        applicationId: 'app-1',
        applicationName: 'My App',
        environmentId: 'env-1',
        environmentName: 'production',
        clusterId: 'cluster-b',
        clusterName: 'cluster-b',
        clusterType: 'postgres',
        schemaName: 'forge',
        organizationName: 'Sidecar',
    );

    $resolved = $navigator->attachCredentials($target);

    expect($resolved->connection['hostname'])->toBe('db.example.com')
        ->and($resolved->organizationName)->toBe('Sidecar')
        ->and($navigator->organization())->toBe('Sidecar');

    Process::assertRan(fn ($process) => ($process->environment['LARAVEL_CLOUD_TOKEN'] ?? null) === '2|bbb');
});
