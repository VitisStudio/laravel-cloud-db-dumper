<?php

namespace VitisStudio\LaravelCloudDbDumper\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \VitisStudio\LaravelCloudDbDumper\LaravelCloudDbDumper
 */
class LaravelCloudDbDumper extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \VitisStudio\LaravelCloudDbDumper\LaravelCloudDbDumper::class;
    }
}
