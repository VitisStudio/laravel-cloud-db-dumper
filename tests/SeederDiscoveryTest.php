<?php

use VitisStudio\LaravelCloudDbDumper\Seeders\SeederDiscovery;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/seeders-'.uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

it('returns an empty array when the directory does not exist', function () {
    expect((new SeederDiscovery('/no/such/dir'))->all())->toBe([]);
});

it('discovers and namespaces php seeder files, sorted', function () {
    touch($this->dir.'/UserSeeder.php');
    touch($this->dir.'/DatabaseSeeder.php');
    touch($this->dir.'/readme.txt');

    $seeders = (new SeederDiscovery($this->dir))->all();

    expect($seeders)->toBe([
        'Database\\Seeders\\DatabaseSeeder',
        'Database\\Seeders\\UserSeeder',
    ]);
});

it('honours a custom namespace', function () {
    touch($this->dir.'/SanitizeSeeder.php');

    $seeders = (new SeederDiscovery($this->dir, 'App\\Seeds\\'))->all();

    expect($seeders)->toBe(['App\\Seeds\\SanitizeSeeder']);
});
