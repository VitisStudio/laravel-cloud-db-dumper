<?php

use VitisStudio\LaravelCloudDbDumper\Support\GitRepository;

it('reduces remote urls to owner/repo', function (string $url, ?string $expected) {
    expect(GitRepository::parseFullName($url))->toBe($expected);
})->with([
    ['git@github.com:vitisstudio/laravel-cloud-db-dumper.git', 'vitisstudio/laravel-cloud-db-dumper'],
    ['git@github.com:vitisstudio/laravel-cloud-db-dumper', 'vitisstudio/laravel-cloud-db-dumper'],
    ['https://github.com/vitisstudio/laravel-cloud-db-dumper.git', 'vitisstudio/laravel-cloud-db-dumper'],
    ['https://github.com/vitisstudio/laravel-cloud-db-dumper', 'vitisstudio/laravel-cloud-db-dumper'],
    ['ssh://git@github.com/vitisstudio/laravel-cloud-db-dumper.git', 'vitisstudio/laravel-cloud-db-dumper'],
    ['https://gitlab.com/group/subgroup/project.git', 'subgroup/project'],
    ['', null],
    ['not-a-remote', null],
]);
