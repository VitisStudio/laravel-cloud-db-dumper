<?php

use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
use VitisStudio\LaravelCloudDbDumper\Cloud\OrganizationResolver;

afterEach(function () {
    // Prompt::fake() installs a Mockery terminal and forces interactive mode,
    // both static. Left in place, the next prompt reads from an exhausted mock
    // and spins until the suite runs out of memory.
    $prompt = new ReflectionClass(Prompt::class);
    $prompt->setStaticPropertyValue('terminal', new Terminal);
    Prompt::interactive(false);
});

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

it('resolves the organization the user actually picked', function () {
    Process::fake(['*' => Process::result(json_encode([
        ['token' => '1|aaa', 'source' => 'config.json', 'organization' => 'Dan Poblete'],
        ['token' => '2|bbb', 'source' => 'config.json', 'organization' => 'Sidecar'],
        ['token' => '3|ccc', 'source' => 'config.json', 'organization' => 'Ram Jack Systems Distribution'],
    ]))]);

    // Down twice lands on the third organization.
    Prompt::fake([Key::DOWN, Key::DOWN, Key::ENTER]);

    $resolved = (new OrganizationResolver(new CloudCli))->resolve();

    expect($resolved['organization'])->toBe('Ram Jack Systems Distribution')
        ->and($resolved['token'])->toBe('3|ccc');
});
