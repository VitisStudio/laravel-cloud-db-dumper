<?php

use VitisStudio\LaravelCloudDbDumper\Support\DatabaseClients;
use VitisStudio\LaravelCloudDbDumper\Support\LocalDatabase;

function clients(array $versions): array
{
    $clients = array_map(
        fn (string $v) => ['path' => "/opt/pg/{$v}/bin/pg_dump", 'version' => $v],
        $versions,
    );

    usort($clients, fn ($a, $b) => version_compare($b['version'], $a['version']));

    return $clients;
}

it('reads a version out of a client banner', function (string $raw, ?string $expected) {
    expect(DatabaseClients::parseVersion($raw))->toBe($expected);
})->with([
    ['pg_dump (PostgreSQL) 18.1', '18.1'],
    ['pg_dump (PostgreSQL) 16.2 (Postgres.app)', '16.2'],
    ['mysqldump  Ver 8.0.36 for macos14 on arm64', '8.0.36'],
    ['mysqldump  Ver 10.11.6-MariaDB for osx10.19', '10.11.6'],
    ['no version at all', null],
]);

it('prefers a client of the same major version as the server', function () {
    // 18.1 reads an 18.6 server; the minor difference does not matter.
    expect(DatabaseClients::bestFor(clients(['15.1', '16.2', '17.0', '18.1']), '18.6')['version'])->toBe('18.1')
        ->and(DatabaseClients::bestFor(clients(['16.2', '17.4', '18.1']), '17.0')['version'])->toBe('17.4');
});

it('falls back to the oldest client that is still newer than the server', function () {
    // A client may read an older server but never a newer one, so reach up
    // only as far as needed.
    expect(DatabaseClients::bestFor(clients(['17.0', '18.1']), '16.2')['version'])->toBe('17.0');
});

it('finds nothing when every client is older than the server', function () {
    expect(DatabaseClients::bestFor(clients(['15.1', '16.2']), '18.6'))->toBeNull();
});

it('takes the newest client when the server version is unknown', function () {
    expect(DatabaseClients::bestFor(clients(['16.2', '18.1']), null)['version'])->toBe('18.1')
        ->and(DatabaseClients::bestFor([], '18.6'))->toBeNull();
});

it('reads a version out of a server banner', function (string $raw, ?string $expected) {
    expect(LocalDatabase::parseServerVersion($raw))->toBe($expected);
})->with([
    ['PostgreSQL 18.1 (Homebrew) on aarch64-apple-darwin23.4.0', '18.1'],
    ['PostgreSQL 18.6 (6569466) on x86_64-pc-linux-gnu', '18.6'],
    ['8.0.36', '8.0.36'],
    ['10.11.6-MariaDB-log', '10.11.6'],
    ['unknown', null],
]);
