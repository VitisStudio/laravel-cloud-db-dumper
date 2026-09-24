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

it('writes outside the project and caches nothing when storage is off', function () {
    $manager = new DumpManager('/should/not/be/used', [], storeDumps: false);
    $target = makeTarget();

    $path = $manager->pathFor($target);

    expect($manager->storesDumps())->toBeFalse()
        ->and($path)->toStartWith(rtrim(sys_get_temp_dir(), '/').'/laravel-cloud-db-dumper-')
        ->and($path)->not->toContain('/should/not/be/used')
        ->and($manager->directory())->toBe($manager->directory());
});

it('reports no cached copy when storage is off even if a file is sitting there', function () {
    $manager = new DumpManager('/should/not/be/used', [], storeDumps: false);
    $target = makeTarget();

    File::ensureDirectoryExists(dirname($manager->pathFor($target)));
    File::put($manager->pathFor($target), '-- dump');

    expect($manager->cachedCopyExists($target))->toBeFalse();

    $manager->discard($manager->pathFor($target));
});

it('discards an unstored dump and its directory', function () {
    $manager = new DumpManager('/should/not/be/used', [], storeDumps: false);
    $target = makeTarget();
    $path = $manager->pathFor($target);

    File::ensureDirectoryExists(dirname($path));
    File::put($path, '-- production data');

    $manager->discard($path);

    expect(File::exists($path))->toBeFalse()
        ->and(File::isDirectory(dirname($path)))->toBeFalse();
});

it('never deletes a dump the user asked to keep', function () {
    $directory = sys_get_temp_dir().'/cloud-db-dumper-keep-'.uniqid();
    $manager = new DumpManager($directory);
    $target = makeTarget();
    $path = $manager->pathFor($target);

    File::ensureDirectoryExists($directory);
    File::put($path, '-- dump');

    $manager->discard($path);

    expect(File::exists($path))->toBeTrue();

    File::deleteDirectory($directory);
});
