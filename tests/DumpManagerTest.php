<?php

use Illuminate\Support\Carbon;
use VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget;
use VitisStudio\LaravelCloudDbDumper\Dumpers\DumpManager;

beforeEach(function () {
    $this->backupDir = sys_get_temp_dir().'/dumps-'.uniqid();
    Carbon::setTestNow(Carbon::parse('2026-06-25 10:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
    array_map('unlink', glob($this->backupDir.'/*') ?: []);
    @rmdir($this->backupDir);
});

function pgTarget(): DatabaseTarget
{
    return new DatabaseTarget(
        applicationId: 'a',
        applicationName: 'a',
        environmentId: 'e',
        environmentName: 'e',
        clusterId: 'c',
        clusterName: 'c',
        clusterType: 'postgres',
        schemaName: 'forge',
    );
}

it('builds a per-day timestamped filename keyed by schema and driver', function () {
    $manager = new DumpManager($this->backupDir);

    expect($manager->pathFor(pgTarget()))
        ->toBe($this->backupDir.'/forge_pgsql_2026-06-25.sql');
});

it('detects an existing cached copy for today', function () {
    $manager = new DumpManager($this->backupDir);

    expect($manager->cachedCopyExists(pgTarget()))->toBeFalse();

    mkdir($this->backupDir);
    touch($manager->pathFor(pgTarget()));

    expect($manager->cachedCopyExists(pgTarget()))->toBeTrue();
});

it('refuses to dump a target without credentials', function () {
    (new DumpManager($this->backupDir))->dump(pgTarget());
})->throws(InvalidArgumentException::class);
