<?php

namespace VitisStudio\LaravelCloudDbDumper;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use VitisStudio\LaravelCloudDbDumper\Commands\LaravelCloudDbDumperCommand;

class LaravelCloudDbDumperServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-cloud-db-dumper')
            ->hasConfigFile()
            ->hasCommand(LaravelCloudDbDumperCommand::class);
    }
}
