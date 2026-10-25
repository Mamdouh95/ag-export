<?php

use Agriserv\Exports\Http\Controllers\ExportDownloadController;
use Agriserv\Exports\Http\Controllers\ExportsController;
use Illuminate\Support\Facades\Route;

$routes = config('exports.routes', []);

Route::group([
    'prefix'     => $routes['prefix'] ?? 'exports',
    'middleware' => $routes['middleware'] ?? ['web'],
    'as'         => $routes['name'] ?? 'exports.',
], function () use ($routes) {
    Route::get('download/{exportJob}', ExportDownloadController::class)
        ->name('download');

    // JSON status endpoints for non-Livewire UIs (the published ExportsList.vue)
    if ($routes['api'] ?? true) {
        Route::get('jobs', [ExportsController::class, 'index'])->name('jobs.index');
        Route::delete('jobs/{exportJob}', [ExportsController::class, 'destroy'])->name('jobs.destroy');
    }
});
