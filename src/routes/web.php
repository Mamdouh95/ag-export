<?php

use Agriserv\Exports\Http\Controllers\ExportDownloadController;
use Illuminate\Support\Facades\Route;

$routes = config('exports.routes', []);

Route::group([
    'prefix'     => $routes['prefix'] ?? 'exports',
    'middleware' => $routes['middleware'] ?? ['web'],
    'as'         => $routes['name'] ?? 'exports.',
], function () {
    Route::get('download/{exportJob}', ExportDownloadController::class)
        ->name('download');
});
