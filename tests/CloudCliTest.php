<?php

use Illuminate\Support\Facades\Process;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCliException;

it('decodes json output', function () {
    Process::fake([
        '*' => Process::result(json_encode([['id' => 'app-1', 'name' => 'My App']])),
    ]);

    expect((new CloudCli)->applications())->toBe([['id' => 'app-1', 'name' => 'My App']]);

    Process::assertRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'application:list --json -n --no-ansi',
    ));
});

it('surfaces the cli error message from the json envelope on stderr', function () {
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: json_encode(['error' => true, 'message' => 'Multiple API tokens found.']),
            exitCode: 1,
        ),
    ]);

    expect(fn () => (new CloudCli)->applications())
        ->toThrow(CloudCliException::class, 'Multiple API tokens found.');
});

it('flags an ambiguous organization', function () {
    $exception = new CloudCliException('Multiple API tokens found. Run `cloud repo:config`...');

    expect($exception->requiresOrganization())->toBeTrue()
        ->and($exception->requiresAuthentication())->toBeFalse();

    expect((new CloudCliException('Not authenticated. Run `cloud auth`.'))->requiresAuthentication())->toBeTrue();
});

it('fails loudly on non-json output', function () {
    Process::fake(['*' => Process::result('not json at all')]);

    expect(fn () => (new CloudCli)->clusters())
        ->toThrow(CloudCliException::class, 'unexpected output');
});

it('runs as a given api token without ever printing it', function () {
    Process::fake(['*' => Process::result(json_encode([]))]);

    (new CloudCli('cloud'))->withApiToken('1|secret-token')->applications();

    Process::assertRan(fn ($process) => ($process->environment['LARAVEL_CLOUD_TOKEN'] ?? null) === '1|secret-token');
});

it('turns xdebug off in the cloud cli it shells out to', function () {
    Process::fake(['*' => Process::result(json_encode([]))]);

    (new CloudCli)->applications();

    Process::assertRan(fn ($process) => ($process->environment['XDEBUG_MODE'] ?? null) === 'off');
});

it('decodes json that another extension wrote noise around', function () {
    $noise = 'Xdebug: [Step Debug] Could not connect to debugging client. '
        .'Tried: localhost:9003 (through xdebug.client_host/xdebug.client_port).';

    Process::fake([
        '*' => Process::result($noise."\n".json_encode([['id' => 'app-1', 'name' => 'Field Ops']])."\n".$noise),
    ]);

    expect((new CloudCli)->applications())->toBe([['id' => 'app-1', 'name' => 'Field Ops']]);
});

it('finds the cli error envelope buried in noise', function () {
    $noise = 'Xdebug: [Step Debug] Could not connect to debugging client.';

    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: $noise."\n".json_encode(['error' => true, 'message' => 'Cluster not found.'])."\n".$noise,
            exitCode: 1,
        ),
    ]);

    expect(fn () => (new CloudCli)->clusters())
        ->toThrow(CloudCliException::class, 'Cluster not found.');
});

it('keeps noise out of the failure it reports', function () {
    $noise = 'Xdebug: [Step Debug] Could not connect to debugging client. Tried: localhost:9003.';

    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: $noise."\n".'Something broke upstream.'."\n".$noise,
            exitCode: 1,
        ),
    ]);

    try {
        (new CloudCli)->clusters();
        expect(false)->toBeTrue('expected a failure');
    } catch (CloudCliException $e) {
        expect($e->getMessage())->toContain('Something broke upstream.')
            ->and($e->getMessage())->not->toContain('Xdebug');
    }
});

it('reports the stream that carried the explanation, whichever it was', function () {
    Process::fake([
        '*' => Process::result(output: 'psql: fatal: role does not exist', errorOutput: '', exitCode: 1),
    ]);

    expect(fn () => (new CloudCli)->clusters())
        ->toThrow(CloudCliException::class, 'psql: fatal: role does not exist');
});

it('handles pretty-printed json and noise-only streams', function () {
    $noise = 'Xdebug: [Step Debug] Could not connect. Tried: localhost:9003.';
    $pretty = json_encode(['id' => 'c1', 'schemas' => [['id' => 's1']]], JSON_PRETTY_PRINT);

    expect(CloudCli::decodeJson($noise."\n".$pretty."\n".$noise))
        ->toBe(['id' => 'c1', 'schemas' => [['id' => 's1']]])
        ->and(CloudCli::decodeJson($noise))->toBeNull()
        ->and(CloudCli::decodeJson(''))->toBeNull()
        ->and(CloudCli::withoutNoise($noise."\nreal error\n".$noise))->toBe('real error');
});

it('parses the cli version out of its banner', function () {
    expect(CloudCli::parseVersion('  Cloud  v0.5.2'))->toBe('0.5.2')
        ->and(CloudCli::parseVersion("Xdebug: [Step Debug] nope\n Cloud  v0.6.1 "))->toBe('0.6.1')
        ->and(CloudCli::parseVersion('no version here'))->toBeNull();
});

it('refuses to run against a cli that is too old', function () {
    Process::fake(['*' => Process::result(' Cloud  v0.5.2')]);

    expect(fn () => (new CloudCli)->ensureSupportedVersion())
        ->toThrow(CloudCliException::class, 'The cloud CLI is v0.5.2');
});

it('accepts a cli at or above the minimum', function (string $version) {
    Process::fake(['*' => Process::result(" Cloud  v{$version}")]);

    (new CloudCli)->ensureSupportedVersion();
})->with(['0.5.3', '0.6.1', '1.0.0'])->throwsNoExceptions();

it('does not block a cli whose version it cannot read', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'not found', exitCode: 127)]);

    (new CloudCli)->ensureSupportedVersion();
})->throwsNoExceptions();

it('checks the version before it walks the user through any pickers', function () {
    Process::fake([
        '*--version*' => Process::result(' Cloud  v0.5.2'),
        '*' => Process::result(json_encode([])),
    ]);

    $this->artisan('db:pull')
        ->assertFailed()
        ->expectsOutputToContain('The cloud CLI is v0.5.2');

    // Nothing was fetched: the run stopped at the gate.
    Process::assertNotRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'application:list',
    ));
});

it('asks for unmasked tokens, since masked ones authenticate as nothing', function () {
    Process::fake(['*' => Process::result(json_encode([
        ['token' => '1|real-token', 'source' => 'config.json', 'organization' => 'Ram Jack'],
    ]))]);

    (new CloudCli)->tokens();

    Process::assertRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'auth:token --list --show-sensitive',
    ));
});

it('refuses a masked token instead of forwarding one that will 401', function () {
    Process::fake(['*' => Process::result(json_encode([
        ['token' => '*****7ed7', 'source' => 'config.json', 'organization' => 'Ram Jack'],
    ]))]);

    expect(fn () => (new CloudCli)->tokens())
        ->toThrow(CloudCliException::class, 'masked API tokens');
});

it('recognises a masked token', function () {
    expect(CloudCli::looksMasked('*****7ed7'))->toBeTrue()
        ->and(CloudCli::looksMasked(''))->toBeTrue()
        ->and(CloudCli::looksMasked('3296|g1IXojeNYzSjaa8Ywv44ss3LBpz4sIHV'))->toBeFalse();
});

it('refuses a payload that is not the record it asked for', function () {
    // A bogus id 404s internally, the CLI swallows it and returns the sole
    // record in the organization instead — exit 0, clean JSON, wrong database.
    Process::fake(['*' => Process::result(json_encode([
        'id' => 'some-other-cluster', 'name' => 'not what you asked for',
        'connection' => ['hostname' => 'h', 'password' => 'p'],
    ]))]);

    expect(fn () => (new CloudCli)->clusterWithCredentials('the-one-i-asked-for'))
        ->toThrow(CloudCliException::class, 'returned "some-other-cluster" instead');
});

it('accepts a payload whose id matches', function () {
    Process::fake(['*' => Process::result(json_encode(['id' => 'mine', 'name' => 'mine']))]);

    expect((new CloudCli)->environment('mine')['id'])->toBe('mine');
});

it('recovers a token listing that one dead token broke', function () {
    $tokens = json_encode([['token' => '1|real', 'source' => 'config.json', 'organization' => 'Ram Jack']]);

    Process::fake([
        '*auth:token*' => Process::sequence()
            ->push(Process::result(output: '', errorOutput: json_encode([
                'error' => true, 'message' => 'Unauthorized (401) Response: { "message": "Invalid API token" }',
            ]), exitCode: 1))
            ->push(Process::result($tokens)),
        '*application:list*' => Process::result(output: '', errorOutput: 'Multiple API tokens found.', exitCode: 1),
    ]);

    expect((new CloudCli)->tokens())->toHaveCount(1);
});
