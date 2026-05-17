<?php

namespace Agriserv\Exports\Facades;

use Agriserv\Exports\Models\ExportJob;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ExportJob queue(object $export, string $filename, array $options = [])
 *
 * @see \Agriserv\Exports\Support\QueuedExports
 */
class QueuedExports extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'agriserv.exports';
    }
}
