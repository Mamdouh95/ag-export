<?php

namespace Agriserv\Exports\Support;

use Agriserv\Exports\Jobs\ProcessExportJob;
use Agriserv\Exports\Models\ExportJob;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class QueuedExports
{
    /**
     * Persist an ExportJob row and push the worker onto the queue.
     *
     * @param  object       $export     Maatwebsite export instance (FromQuery / FromCollection / etc.)
     * @param  string       $filename   Final filename, e.g. "invoices-2026-05-17.xlsx"
     * @param  array{
     *     user?: Model|Authenticatable|null,
     *     label?: string|null,
     *     disk?: string|null,
     *     expires_at?: \DateTimeInterface|null,
     * } $options
     */
    public function queue(object $export, string $filename, array $options = []): ExportJob
    {
        $user = $options['user'] ?? (auth()->check() ? auth()->user() : null);
        $disk = $options['disk'] ?? config('exports.disk', 'local');

        $retention = (int) config('exports.retention_days', 7);
        $expiresAt = $options['expires_at']
            ?? ($retention > 0 ? now()->addDays($retention) : null);

        $record = ExportJob::create([
            'user_type'  => $user ? $user::class : null,
            'user_id'    => $user?->getKey(),
            'label'      => $options['label'] ?? null,
            'filename'   => $filename,
            'disk'       => $disk,
            'status'     => ExportJob::STATUS_PENDING,
            'expires_at' => $expiresAt,
        ]);

        $cfg = config('exports.queue', []);

        $job = (new ProcessExportJob($record->id, $export))
            ->onConnection($cfg['connection'] ?? null)
            ->onQueue($cfg['queue'] ?? 'exports');

        dispatch($job);

        return $record;
    }
}
