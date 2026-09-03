<?php

use VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget;
use VitisStudio\LaravelCloudDbDumper\Support\Preferences;

beforeEach(function () {
    $this->prefsPath = sys_get_temp_dir().'/db-backup-prefs-'.uniqid().'.json';
});

afterEach(function () {
    @unlink($this->prefsPath);
});

it('returns null when no preferences file exists', function () {
    expect((new Preferences($this->prefsPath))->load())->toBeNull();
});

it('saves and loads a target without persisting credentials', function () {
    $preferences = new Preferences($this->prefsPath);

    $target = (new DatabaseTarget(
        applicationId: 'app-1',
        applicationName: 'My App',
        environmentId: 'env-1',
        environmentName: 'production',
        clusterId: 'cluster-1',
        clusterName: 'main',
        clusterType: 'postgres',
        schemaName: 'forge',
    ))->withConnection([
        'protocol' => 'postgresql',
        'hostname' => 'db.example.com',
        'port' => 5432,
        'username' => 'admin',
        'password' => 'super-secret-password',
    ]);

    $preferences->save($target);

    $contents = file_get_contents($this->prefsPath);
    expect($contents)->not->toContain('super-secret-password')
        ->and($contents)->not->toContain('db.example.com');

    $loaded = $preferences->load();
    expect($loaded)->not->toBeNull()
        ->and($loaded->clusterId)->toBe('cluster-1')
        ->and($loaded->schemaName)->toBe('forge')
        ->and($loaded->connection)->toBeNull();
});

it('returns null for a malformed preferences file', function () {
    file_put_contents($this->prefsPath, '{ not valid json');

    expect((new Preferences($this->prefsPath))->load())->toBeNull();
});
