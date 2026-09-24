<?php

use Illuminate\Support\Facades\Process;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
use VitisStudio\LaravelCloudDbDumper\Cloud\TargetNavigator;

/**
 * These fixtures are real Laravel Cloud CLI payloads with the identifying
 * values swapped out. Every key, type, null and id format is exactly what the
 * CLI returned, because the assumptions this package used to make were wrong in
 * ways only real output revealed.
 */
function shape(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__."/fixtures/shapes/{$name}.json"), true);
}

it('records what the real payloads actually contain', function () {
    $application = shape('application-list')[0];
    $environment = shape('environment-list')[0];
    $cluster = shape('database-cluster-list')[0];

    expect($application['defaultEnvironmentId'])->toBeNull()
        // The embedded environments look complete and are not: this null is
        // what made the cluster lookup fail for real users.
        ->and($application['environments'][0]['databaseSchemaId'])->toBeNull()
        // The listing carries it, which is why that is what we ask for.
        ->and($environment['databaseSchemaId'])->toBe('66023719')
        // Schema ids are numeric strings, and cluster ids are not prefixed.
        ->and($cluster['id'])->toBe('blue-brook-70289993')
        ->and($cluster['type'])->toBe('neon_serverless_postgres')
        ->and($cluster['schemas'][0]['id'])->toBe('66023719');
});

it('walks a real account end to end without a single prompt', function () {
    Process::fake([
        '*application:list*' => Process::result(json_encode(shape('application-list'))),
        '*environment:list*' => Process::result(json_encode(shape('environment-list'))),
        '*database-cluster:list*' => Process::result(json_encode(shape('database-cluster-list'))),
    ]);

    $target = (new TargetNavigator(new CloudCli))->navigate();

    expect($target->applicationName)->toBe('field-ops')
        ->and($target->environmentName)->toBe('production')
        ->and($target->schemaName)->toBe('main-cluster')
        ->and($target->clusterId)->toBe('blue-brook-70289993')
        // A serverless Postgres cluster still has to dump as pgsql.
        ->and($target->driver())->toBe('pgsql');
});

it('attaches credentials from a real cluster payload', function () {
    Process::fake([
        '*database-cluster:get*' => Process::result(json_encode(shape('database-cluster-get'))),
    ]);

    $target = (new TargetNavigator(new CloudCli))->attachCredentials(
        new VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget(
            applicationId: 'app-1',
            applicationName: 'field-ops',
            environmentId: 'env-1',
            environmentName: 'production',
            clusterId: 'blue-brook-70289993',
            clusterName: 'main-cluster',
            clusterType: 'neon_serverless_postgres',
            schemaName: 'production',
        )
    );

    expect($target->connection)->toHaveKeys(['protocol', 'hostname', 'port', 'username', 'password'])
        ->and($target->driver())->toBe('pgsql');
});

it('reads a numeric schema id back out of a select option list', function () {
    // PHP turns "66023719" into an integer array key, so anything keying a
    // prompt by id has to survive the round trip.
    $schemas = shape('database-list');
    $options = [];

    foreach ($schemas as $schema) {
        $options[(string) $schema['id']] = (string) $schema['name'];
    }

    expect(array_is_list($options))->toBeFalse()
        ->and(TargetNavigator::findByIdentifier($schemas, (string) array_key_first($options)))
        ->not->toBeNull();
});
