<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    | Filesystem disk where finished export files are written.
    | Override per environment via EXPORTS_DISK.
    */
    'disk' => env('EXPORTS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Storage path prefix on the disk
    |--------------------------------------------------------------------------
    | Files land at "{path}/{export_job_uuid}/{filename}".
    */
    'path' => env('EXPORTS_PATH', 'exports'),

    /*
    |--------------------------------------------------------------------------
    | Queue connection / queue name
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('EXPORTS_QUEUE_CONNECTION', null),
        'queue'      => env('EXPORTS_QUEUE', 'exports'),
        'tries'      => (int) env('EXPORTS_TRIES', 3),
        'timeout'    => (int) env('EXPORTS_TIMEOUT', 1800), // 30 min
        'backoff'    => [30, 120, 300],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | Completed/failed export records older than this are pruned (file + row)
    | by the exports:prune command.
    */
    'retention_days' => (int) env('EXPORTS_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Download routes
    |--------------------------------------------------------------------------
    | Where the download endpoint mounts and which middleware guards it.
    | The route uses signed URLs so middleware only needs to apply the
    | application's normal auth (e.g. web, sso_auth).
    */
    'routes' => [
        'enabled'    => true,
        'prefix'     => env('EXPORTS_ROUTE_PREFIX', 'exports'),
        'middleware' => ['web'],
        'name'       => 'exports.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Database table
    |--------------------------------------------------------------------------
    */
    'table' => 'export_jobs',

];
