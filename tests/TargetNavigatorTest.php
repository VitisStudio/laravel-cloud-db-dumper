<?php

use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
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
