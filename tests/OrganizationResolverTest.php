<?php

use Illuminate\Support\Facades\Process;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
use VitisStudio\LaravelCloudDbDumper\Cloud\OrganizationResolver;

function tokensFixture(): array
{
    return [
        ['token' => '1|aaa', 'source' => '~/.config/cloud/config.json', 'organization' => 'Vitis Studio'],
        ['token' => '2|bbb', 'source' => '~/.config/cloud/config.json', 'organization' => 'Sidecar'],
    ];
}

it('matches a saved organization by name, ignoring case', function () {
    expect(OrganizationResolver::match(tokensFixture(), 'sidecar'))
        ->toBe(['organization' => 'Sidecar', 'token' => '2|bbb']);
});

it('returns null when no token belongs to the organization', function () {
    expect(OrganizationResolver::match(tokensFixture(), 'Nope'))->toBeNull();
});

it('resolves the preferred organization without prompting', function () {
    Process::fake(['*' => Process::result(json_encode(tokensFixture()))]);

    $resolved = (new OrganizationResolver(new CloudCli))->resolve('Vitis Studio');

    expect($resolved)->toBe(['organization' => 'Vitis Studio', 'token' => '1|aaa']);
});

it('takes the sole token when only one organization is authenticated', function () {
    Process::fake(['*' => Process::result(json_encode([tokensFixture()[1]]))]);

    $resolved = (new OrganizationResolver(new CloudCli))->resolve('Unknown Org');

    expect($resolved['organization'])->toBe('Sidecar');
});

it('throws when no tokens are saved', function () {
    Process::fake(['*' => Process::result(json_encode([]))]);

    expect(fn () => (new OrganizationResolver(new CloudCli))->resolve())
        ->toThrow(RuntimeException::class, 'No Laravel Cloud API tokens found.');
});
