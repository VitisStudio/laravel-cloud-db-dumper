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

it('stops psql on the first error instead of letting it exit clean', function () {
    $manager = new RestoreManager(localConfig('pgsql'));

    [$command] = $manager->postgresRestoreCommand('/dumps/forge.sql');

    expect($command)->toContain('--set=ON_ERROR_STOP=1')
        ->and($command)->toContain('--single-transaction');
});

it('strips ownership and privilege statements, leaving COPY data alone', function () {
    $dump = tempnam(sys_get_temp_dir(), 'dump').'.sql';

    file_put_contents($dump, <<<'SQL'
CREATE TABLE public.notes (body text);
ALTER TABLE public.notes OWNER TO laravel;
ALTER DEFAULT PRIVILEGES FOR ROLE cloud_admin IN SCHEMA public GRANT ALL ON TABLES TO neon_superuser WITH GRANT OPTION;
GRANT USAGE ON SCHEMA public TO neon_superuser;
COPY public.notes (body) FROM stdin;
GRANT USAGE ON SCHEMA public TO nobody;
ALTER TABLE public.spoof OWNER TO imposter;
\.
CREATE INDEX notes_body ON public.notes (body);
SQL);

    $stripped = (new RestoreManager(localConfig('pgsql')))->withoutOwnership($dump);
    $contents = (string) file_get_contents($stripped);

    unlink($dump);
    unlink($stripped);

    expect($contents)->toContain('CREATE TABLE public.notes')
        ->and($contents)->toContain('CREATE INDEX notes_body')
        // The two lines inside the COPY block are data, not statements.
        ->and($contents)->toContain('GRANT USAGE ON SCHEMA public TO nobody;')
        ->and($contents)->toContain('ALTER TABLE public.spoof OWNER TO imposter;')
        ->and($contents)->not->toContain('OWNER TO laravel')
        ->and($contents)->not->toContain('neon_superuser');
});

it('drops a privilege statement that runs across several lines', function () {
    $dump = tempnam(sys_get_temp_dir(), 'dump').'.sql';

    file_put_contents($dump, <<<'SQL'
GRANT ALL ON TABLE public.notes
    TO neon_superuser
    WITH GRANT OPTION;
CREATE INDEX notes_body ON public.notes (body);
SQL);

    $stripped = (new RestoreManager(localConfig('pgsql')))->withoutOwnership($dump);
    $contents = (string) file_get_contents($stripped);

    unlink($dump);
    unlink($stripped);

    expect($contents)->toBe('CREATE INDEX notes_body ON public.notes (body);');
});
