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
