<?php

namespace VitisStudio\LaravelCloudDbDumper\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use VitisStudio\LaravelCloudDbDumper\LaravelCloudDbDumperServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Laravel Prompts keeps its terminal, interactivity and fallback mode
        // in statics. Running an artisan command switches on fallback mode and
        // Prompt::fake() swaps the terminal for a mock, and both survive into
        // whatever test runs next. Start every test from a known state.
        $prompt = new ReflectionClass(Prompt::class);
        $prompt->setStaticPropertyValue('terminal', new Terminal);
        // fallbackWhen() is `$condition || $shouldFallback`, so it can only ever
        // switch fallback on. Reflection is the only way back.
        $prompt->setStaticPropertyValue('shouldFallback', false);
        Prompt::interactive(false);

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'VitisStudio\\LaravelCloudDbDumper\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelCloudDbDumperServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        /*
         foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__ . '/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
         }
         */
    }
}
