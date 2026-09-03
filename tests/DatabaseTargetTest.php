<?php

use VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget;

function makeTarget(string $clusterType = 'postgres'): DatabaseTarget
{
    return new DatabaseTarget(
        applicationId: 'app-1',
        applicationName: 'My App',
        environmentId: 'env-1',
        environmentName: 'production',
        clusterId: 'cluster-1',
        clusterName: 'main-cluster',
        clusterType: $clusterType,
        schemaName: 'forge',
    );
}

it('maps cluster type to a laravel driver', function () {
    expect(makeTarget('mysql')->driver())->toBe('mysql')
        ->and(makeTarget('mysql-8')->driver())->toBe('mysql')
        ->and(makeTarget('postgres')->driver())->toBe('pgsql')
        ->and(makeTarget('postgres-16')->driver())->toBe('pgsql');
});

it('round-trips through array without credentials', function () {
    $target = makeTarget()->withConnection([
        'protocol' => 'postgresql',
        'hostname' => 'db.example.com',
        'port' => 5432,
        'username' => 'admin',
        'password' => 'secret',
    ])->withDefaultSeeder('SanitizeSeeder');

    $array = $target->toArray();

    expect($array)->not->toHaveKey('connection')
        ->and($array)->not->toHaveKey('password')
        ->and($array['defaultSeeder'])->toBe('SanitizeSeeder');

    $restored = DatabaseTarget::fromArray($array);

    expect($restored->clusterId)->toBe('cluster-1')
        ->and($restored->schemaName)->toBe('forge')
        ->and($restored->defaultSeeder)->toBe('SanitizeSeeder')
        ->and($restored->connection)->toBeNull();
});

it('attaches credentials immutably', function () {
    $target = makeTarget();
    $withCreds = $target->withConnection([
        'protocol' => 'postgresql',
        'hostname' => 'h',
        'port' => 5432,
        'username' => 'u',
        'password' => 'p',
    ]);

    expect($target->connection)->toBeNull()
        ->and($withCreds->connection)->toMatchArray(['hostname' => 'h']);
});
