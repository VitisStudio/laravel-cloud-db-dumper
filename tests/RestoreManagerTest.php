<?php

use VitisStudio\LaravelCloudDbDumper\Restorers\RestoreManager;

function localConfig(string $driver): array
{
    return [
        'driver' => $driver,
        'host' => '127.0.0.1',
        'port' => $driver === 'mysql' ? 3306 : 5432,
        'database' => 'local_app',
        'username' => 'sail',
        'password' => 'password',
    ];
}

it('builds a postgres restore command with PGPASSWORD and binary path', function () {
    $manager = new RestoreManager(
        localConfig('pgsql'),
        ['psql' => '/opt/pg/bin/psql'],
    );

    [$command, $env] = $manager->postgresRestoreCommand('/dumps/forge.sql');

    // escapeshellarg() quotes with ' on POSIX and " on Windows, so build the
    // expectation the same way rather than hardcoding one platform's quoting.
    expect($command)->toContain('/opt/pg/bin/psql')
        ->and($command)->toContain('--dbname='.escapeshellarg('local_app'))
        ->and($command)->toContain('--file='.escapeshellarg('/dumps/forge.sql'))
        ->and($env)->toBe(['PGPASSWORD' => 'password']);
});

it('falls back to the bare binary name when no path configured', function () {
    $manager = new RestoreManager(localConfig('pgsql'));

    [$command] = $manager->postgresRestoreCommand('/dumps/forge.sql');

    expect($command)->toStartWith('psql ');
});

it('builds a mysql restore command with MYSQL_PWD and stdin redirect', function () {
    $manager = new RestoreManager(
        localConfig('mysql'),
        ['mysql' => '/usr/local/bin/mysql'],
    );

    [$command, $env] = $manager->mySqlRestoreCommand('/dumps/app.sql');

    expect($command)->toContain('/usr/local/bin/mysql')
        ->and($command)->toContain('--user='.escapeshellarg('sail'))
        ->and($command)->toContain('< '.escapeshellarg('/dumps/app.sql'))
        ->and($env)->toBe(['MYSQL_PWD' => 'password']);
});

it('throws when restoring a missing dump file', function () {
    $manager = new RestoreManager(localConfig('pgsql'));

    $manager->restore('/does/not/exist.sql');
})->throws(RuntimeException::class);
