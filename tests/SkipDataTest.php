<?php

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use VitisStudio\LaravelCloudDbDumper\Commands\LaravelCloudDbDumperCommand;
use VitisStudio\LaravelCloudDbDumper\Restorers\RestoreManager;

function skipDataConfig(string $driver): array
{
    return ['driver' => $driver, 'host' => 'h', 'port' => 5432, 'database' => 'd', 'username' => 'u', 'password' => 'p'];
}

it('keeps the definitions of skipped tables and drops only their rows', function () {
    $dump = tempnam(sys_get_temp_dir(), 'dump-');
    file_put_contents($dump, implode("\n", [
        'CREATE TABLE public.jobs (id bigint NOT NULL, payload text);',
        'CREATE TABLE public.users (id bigint NOT NULL);',
        'ALTER TABLE public.users OWNER TO neon_superuser;',
        'COPY public.jobs (id, payload) FROM stdin;',
        '1	GRANT ALL ON everything',
        '\.',
        'COPY public.users (id) FROM stdin;',
        '7',
        '\.',
        "SELECT pg_catalog.setval('public.jobs_id_seq', 1, true);",
        '',
    ]));

    $filtered = (new RestoreManager(skipDataConfig('pgsql'), skipDataTables: ['jobs']))
        ->withoutSkippedData($dump);

    expect(file_get_contents($filtered))->toBe(implode("\n", [
        'CREATE TABLE public.jobs (id bigint NOT NULL, payload text);',
        'CREATE TABLE public.users (id bigint NOT NULL);',
        'ALTER TABLE public.users OWNER TO neon_superuser;',
        'COPY public.users (id) FROM stdin;',
        '7',
        '\.',
        "SELECT pg_catalog.setval('public.jobs_id_seq', 1, true);",
        '',
    ]));

    @unlink($dump);
    @unlink($filtered);
});

it('drops mysqldump inserts for skipped tables only', function () {
    $dump = tempnam(sys_get_temp_dir(), 'dump-');
    file_put_contents($dump, "CREATE TABLE `sessions` (`id` varchar(255));\nINSERT INTO `sessions` VALUES ('a');\nINSERT INTO `users` VALUES (1);\n");

    $filtered = (new RestoreManager(skipDataConfig('mysql'), skipDataTables: ['sessions']))
        ->withoutSkippedData($dump);

    expect(file_get_contents($filtered))
        ->toBe("CREATE TABLE `sessions` (`id` varchar(255));\nINSERT INTO `users` VALUES (1);\n");

    @unlink($dump);
    @unlink($filtered);
});

it('matches bare names in any schema and qualified names only in theirs', function (string $dumped, bool $skipped) {
    $manager = new RestoreManager(skipDataConfig('pgsql'), skipDataTables: ['Jobs', 'audit.events']);

    expect($manager->skipsDataFor($dumped))->toBe($skipped);
})->with([
    ['public.jobs', true],
    ['other.jobs', true],
    ['"public"."jobs"', true],
    ['`jobs`', true],
    ['audit.events', true],
    ['public.events', false],
    ['public.job_batches', false],
]);

it('reads --skip-data alone as the configured list', function () {
    $command = new LaravelCloudDbDumperCommand;
    $input = new ArrayInput(['--skip-data' => [null]], $command->getDefinition());

    expect($input->getOption('skip-data'))->toBe([null]);

    $input = new ArgvInput(['artisan', '--skip-data'], $command->getDefinition());
    expect($input->getOption('skip-data'))->toBe([null]);

    $input = new ArgvInput(['artisan'], $command->getDefinition());
    expect($input->getOption('skip-data'))->toBe([]);
});
