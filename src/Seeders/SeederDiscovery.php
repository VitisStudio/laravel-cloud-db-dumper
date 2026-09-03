<?php

namespace VitisStudio\LaravelCloudDbDumper\Seeders;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SplFileInfo;

/**
 * Enumerates the seeder classes defined in the host application's
 * database/seeders directory, so a post-restore sanitize step can offer them
 * as a selectable list.
 */
class SeederDiscovery
{
    public function __construct(
        protected readonly string $seedersPath,
        protected readonly string $namespace = 'Database\\Seeders\\',
    ) {
        //
    }

    /**
     * Fully-qualified seeder class names found on disk, sorted.
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        if (! File::isDirectory($this->seedersPath)) {
            return [];
        }

        $classes = collect(File::files($this->seedersPath))
            ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
            ->map(fn (SplFileInfo $file): string => $this->namespace.Str::before($file->getFilename(), '.php'))
            ->values()
            ->all();

        sort($classes);

        return $classes;
    }
}
