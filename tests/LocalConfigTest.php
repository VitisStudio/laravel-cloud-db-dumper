<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
use VitisStudio\LaravelCloudDbDumper\Cloud\LocalConfig;
use VitisStudio\LaravelCloudDbDumper\Cloud\TargetNavigator;

function writeCloudConfig(array $config): string
{
    $path = sys_get_temp_dir().'/cloud-db-dumper-tests/'.uniqid().'/.cloud/config.json';

    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode($config));

    return $path;
}

it('reads the ids cloud repo:config wrote', function () {
    $config = new LocalConfig(writeCloudConfig([
        'organization_id' => 'org-7',
        'application_id' => 'app-1',
        'environment_id' => 'env-stg',
    ]));

    expect($config->organizationId())->toBe('org-7')
        ->and($config->applicationId())->toBe('app-1')
        ->and($config->environmentId())->toBe('env-stg');
});

it('returns nulls when there is no .cloud/config.json', function () {
    $config = new LocalConfig(sys_get_temp_dir().'/does-not-exist/.cloud/config.json');

    expect($config->all())->toBe([])
        ->and($config->applicationId())->toBeNull()
        ->and($config->environmentId())->toBeNull();
});

it('defaults the target to the project config without prompting', function () {
    Process::fake([
        '*application:list*' => Process::result(json_encode(applicationsFixture())),
        '*environment:list*' => Process::result(json_encode(environmentsFixture())),
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

    $navigator = new TargetNavigator(
        cloud: new CloudCli,
        localConfig: new LocalConfig(writeCloudConfig([
            'application_id' => 'app-1',
            'environment_id' => 'env-prod',
        ])),
    );

    $target = $navigator->navigate();

    expect($target->applicationName)->toBe('acme-web')
        ->and($target->environmentName)->toBe('production')
        ->and($target->schemaName)->toBe('acme_production');
});

it('prefers an explicit argument over the project config', function () {
    Process::fake([
        '*application:list*' => Process::result(json_encode(applicationsFixture())),
        '*environment:list*' => Process::result(json_encode(environmentsFixture())),
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

    $navigator = new TargetNavigator(
        cloud: new CloudCli,
        localConfig: new LocalConfig(writeCloudConfig([
            'application_id' => 'app-1',
            'environment_id' => 'env-prod',
        ])),
    );

    $target = $navigator->navigate(environment: 'staging');

    expect($target->environmentName)->toBe('staging')
        ->and($target->schemaName)->toBe('acme_staging');
});
