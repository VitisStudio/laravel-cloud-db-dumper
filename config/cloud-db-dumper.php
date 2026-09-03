<?php

// config for VitisStudio/LaravelCloudDbDumper
return [

    /*
    |--------------------------------------------------------------------------
    | Cloud CLI binary
    |--------------------------------------------------------------------------
    |
    | Path to the Laravel Cloud CLI binary used to navigate applications,
    | environments and database clusters. Defaults to "cloud" on the PATH.
    |
    */
    'cloud_binary' => env('CLOUD_BINARY', 'cloud'),

    /*
    |--------------------------------------------------------------------------
    | Cloud organization
    |--------------------------------------------------------------------------
    |
    | Since Cloud CLI 0.5 one machine can hold an API token per organization,
    | and the CLI refuses to guess when it is not attached to a terminal. Name
    | the organization here to pin it; leave null to be prompted once and have
    | the choice remembered in the preferences file.
    |
    */
    'organization' => env('CLOUD_ORGANIZATION'),

    /*
    |--------------------------------------------------------------------------
    | Backup storage
    |--------------------------------------------------------------------------
    |
    | Default directory dumps are written to and the preferences file that
    | remembers the last selected database target. Neither should be committed.
    |
    */
    'backup_path' => database_path('backups'),

    'prefs_file' => base_path('.db-backup-prefs.json'),

    /*
    |--------------------------------------------------------------------------
    | Dump / restore binaries
    |--------------------------------------------------------------------------
    |
    | Absolute paths to the database client binaries. Leave null to discover
    | them on the system PATH. Set these when the binaries live outside the
    | PATH (e.g. DBngin installs them under /Users/Shared/DBngin/...).
    |
    */
    'binaries' => [
        'pg_dump' => env('PG_DUMP_PATH'),
        'pg_restore' => env('PG_RESTORE_PATH'),
        'psql' => env('PSQL_PATH'),
        'mysqldump' => env('MYSQLDUMP_PATH'),
        'mysql' => env('MYSQL_PATH'),
    ],

];
