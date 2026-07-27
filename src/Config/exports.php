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
    | Streaming writer
    |--------------------------------------------------------------------------
    | Applies to exports implementing Agriserv\Exports\Contracts\ShouldStream,
    | which are written row-by-row with OpenSpout instead of being assembled in
    | memory by PhpSpreadsheet.
    |
    | chunk_size    Rows fetched per DB round trip during the keyset walk.
    | right_to_left RTL sheet direction for xlsx (Arabic reports).
    | date_format   Excel number format for real date/time cells. Without one,
    |               Excel renders the raw serial (45678.5) instead of a date.
    | temp_folder   Where OpenSpout buffers sheet parts before zipping. Point at
    |               a roomy volume if /tmp is small; null uses the system temp.
    */
    'stream' => [
        'chunk_size'    => (int) env('EXPORTS_STREAM_CHUNK', 1000),
        'right_to_left' => (bool) env('EXPORTS_STREAM_RTL', true),
        'date_format'   => env('EXPORTS_STREAM_DATE_FORMAT', 'yyyy-mm-dd hh:mm'),
        'temp_folder'   => env('EXPORTS_STREAM_TEMP'),
    ],

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
