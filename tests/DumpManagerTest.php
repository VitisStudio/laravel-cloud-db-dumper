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

it('parses database, driver and date out of a dump filename', function () {
    expect(DumpManager::parseFilename('forge_pgsql_2026-06-25.sql'))
        ->toBe(['database' => 'forge', 'driver' => 'pgsql', 'date' => '2026-06-25'])
        // A database name may carry the same underscore the parts are joined on.
        ->and(DumpManager::parseFilename('acme_staging_mysql_2026-01-02.sql'))
        ->toBe(['database' => 'acme_staging', 'driver' => 'mysql', 'date' => '2026-01-02']);
});

it('ignores files that are not dumps this package wrote', function (string $filename) {
    expect(DumpManager::parseFilename($filename))->toBeNull();
})->with([
    'notes.txt',
    'forge_pgsql_2026-06-25.txt',
    'forge_sqlite_2026-06-25.sql',
    'forge_pgsql_25-06-2026.sql',
    'forge_pgsql.sql',
    'dump.sql',
]);

it('lists every stored dump of one database, newest first', function () {
    $manager = new DumpManager($this->backupDir);
    $target = makeTarget(); // forge / pgsql

    File::ensureDirectoryExists($this->backupDir);
    foreach (['2026-06-20', '2026-06-25', '2026-01-02'] as $date) {
        File::put($this->backupDir."/forge_pgsql_{$date}.sql", str_repeat('x', 2048));
    }

    // Other databases, other drivers and stray files must not show up.
    File::put($this->backupDir.'/other_pgsql_2026-06-25.sql', 'x');
    File::put($this->backupDir.'/forge_mysql_2026-06-25.sql', 'x');
    File::put($this->backupDir.'/README.md', 'x');

    $dumps = $manager->existingDumps($target);

    expect($dumps)->toHaveCount(3)
        ->and(array_column($dumps, 'date'))->toBe(['2026-06-25', '2026-06-20', '2026-01-02'])
        // Compared by basename: the separator differs per platform.
        ->and(basename($dumps[0]['path']))->toBe('forge_pgsql_2026-06-25.sql')
        ->and(File::exists($dumps[0]['path']))->toBeTrue()
        ->and($dumps[0]['size'])->toBe(2048);
});

it('lists nothing when there is no backup directory yet', function () {
    expect((new DumpManager($this->backupDir))->existingDumps(makeTarget()))->toBe([]);
});

it('lists nothing when dumps are not being stored', function () {
    $manager = new DumpManager($this->backupDir, [], storeDumps: false);

    File::ensureDirectoryExists($this->backupDir);
    File::put($this->backupDir.'/forge_pgsql_2026-06-25.sql', 'x');

    expect($manager->existingDumps(makeTarget()))->toBe([]);
});

it('lists dumps of every database when no target is given', function () {
    $manager = new DumpManager($this->backupDir);

    File::ensureDirectoryExists($this->backupDir);
    File::put($this->backupDir.'/forge_pgsql_2026-06-25.sql', 'x');
    File::put($this->backupDir.'/other_mysql_2026-06-20.sql', 'x');
    File::put($this->backupDir.'/notes.md', 'x');

    $dumps = $manager->storedDumps();

    expect($dumps)->toHaveCount(2)
        ->and(array_column($dumps, 'database'))->toBe(['forge', 'other'])
        ->and(array_column($dumps, 'driver'))->toBe(['pgsql', 'mysql']);
});

it('deletes dumps but refuses paths it did not write', function () {
    $manager = new DumpManager($this->backupDir);

    File::ensureDirectoryExists($this->backupDir);
    File::put($dump = $this->backupDir.'/forge_pgsql_2026-06-25.sql', 'x');
    File::put($bystander = $this->backupDir.'/important.sql', 'keep me');
    File::put($alsoSafe = $this->backupDir.'/.env', 'keep me too');

    $deleted = $manager->delete([$dump, $bystander, $alsoSafe]);

    expect($deleted)->toBe(1)
        ->and(File::exists($dump))->toBeFalse()
        ->and(File::exists($bystander))->toBeTrue()
        ->and(File::exists($alsoSafe))->toBeTrue();
});
