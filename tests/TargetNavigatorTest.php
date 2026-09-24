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

function applicationsFixture(): array
{
    return [
        [
            'id' => 'app-1',
            'name' => 'acme-web',
            'repositoryFullName' => 'acme/web',
            'defaultEnvironmentId' => 'env-prod',
            'environments' => [
                ['id' => 'env-prod', 'name' => 'production', 'databaseSchemaId' => 'schema-9'],
                ['id' => 'env-stg', 'name' => 'staging', 'databaseSchemaId' => 'schema-1'],
            ],
        ],
        [
            'id' => 'app-2',
            'name' => 'acme-api',
            'repositoryFullName' => 'acme/api',
            'defaultEnvironmentId' => 'env-api',
            'environments' => [
                ['id' => 'env-api', 'name' => 'production', 'databaseSchemaId' => 'schema-2'],
            ],
        ],
    ];
}

it('matches an item by id or by name', function () {
    $apps = applicationsFixture();

    expect(TargetNavigator::findByIdentifier($apps, 'app-2')['name'])->toBe('acme-api')
        ->and(TargetNavigator::findByIdentifier($apps, 'acme-web')['id'])->toBe('app-1')
        ->and(TargetNavigator::findByIdentifier($apps, 'nope'))->toBeNull();
});

it('filters applications by the repository they deploy from', function () {
    expect(TargetNavigator::matchingRepository(applicationsFixture(), 'acme/api'))->toHaveCount(1)
        ->and(TargetNavigator::matchingRepository(applicationsFixture(), 'ACME/API')[0]['id'])->toBe('app-2')
        ->and(TargetNavigator::matchingRepository(applicationsFixture(), 'someone/else'))->toBe([])
        ->and(TargetNavigator::matchingRepository(applicationsFixture(), null))->toBe([]);
});

it('resolves application and environment from identifiers without prompting', function () {
    Process::fake([
        '*application:list*' => Process::result(json_encode(applicationsFixture())),
        '*database-cluster:list*' => Process::result(json_encode([
            [
                'id' => 'cluster-b',
                'name' => 'cluster-b',
                'type' => 'postgres',
                'schemas' => [
                    ['id' => 'schema-1', 'name' => 'acme_staging'],
                    ['id' => 'schema-9', 'name' => 'acme_production'],
                ],
            ],
        ])),
    ]);

    $target = (new TargetNavigator(new CloudCli))->navigate('acme-web', 'staging');

    expect($target->applicationId)->toBe('app-1')
        ->and($target->environmentName)->toBe('staging')
        ->and($target->schemaName)->toBe('acme_staging')
        ->and($target->driver())->toBe('pgsql');
});

it('uses the environments embedded in the application listing', function () {
    Process::fake([
        '*application:list*' => Process::result(json_encode([applicationsFixture()[1]])),
        '*database-cluster:list*' => Process::result(json_encode([
            [
                'id' => 'cluster-a',
                'name' => 'cluster-a',
                'type' => 'mysql-8',
                'schemas' => [['id' => 'schema-2', 'name' => 'acme_api']],
            ],
        ])),
    ]);

    // A sole application, a sole environment and a sole database resolve with
    // no prompts at all.
    $target = (new TargetNavigator(new CloudCli))->navigate();

    expect($target->applicationName)->toBe('acme-api')
        ->and($target->environmentName)->toBe('production')
        ->and($target->schemaName)->toBe('acme_api')
        ->and($target->driver())->toBe('mysql');

    Process::assertNotRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'environment:list',
    ));
});

it('fails on an identifier that matches no application', function () {
    Process::fake(['*application:list*' => Process::result(json_encode(applicationsFixture()))]);

    expect(fn () => (new TargetNavigator(new CloudCli))->navigate('ghost-app'))
        ->toThrow(RuntimeException::class, 'Unable to resolve application "ghost-app"');
});
