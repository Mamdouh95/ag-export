<?php

namespace Agriserv\Exports\Support;

use Agriserv\Exports\Contracts\ShouldStream;
use Agriserv\Exports\Jobs\ProcessExportJob;
use Agriserv\Exports\Models\ExportJob;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Maatwebsite\Excel\Excel as ExcelFormat;

class QueuedExports
{
    /**
     * Persist an ExportJob row and push the worker onto the queue.
     *
     * @param  object       $export     Maatwebsite export instance (FromQuery / FromCollection / etc.)
     * @param  string       $filename   Final filename, e.g. "invoices-2026-05-17.xlsx" or "ratings-2026-05-17.csv"
     * @param  array{
     *     user?: Model|Authenticatable|null,
     *     label?: string|null,
     *     disk?: string|null,
     *     expires_at?: \DateTimeInterface|null,
     *     format?: string|null,
     * } $options
     *
     * Supported `format` values: 'xlsx' (default), 'csv', 'tsv', 'ods', 'html'.
     * If omitted, Maatwebsite auto-detects from the filename extension.
     */
    public function queue(object $export, string $filename, array $options = []): ExportJob
    {
        $user = $options['user'] ?? (auth()->check() ? auth()->user() : null);
        $disk = $options['disk'] ?? config('exports.disk', 'local');

        $writerType = isset($options['format'])
            ? $this->resolveWriterType($options['format'])
            : null;

        // Keep the plain format string too: the streaming writer builds its own
        // writer and has no use for maatwebsite's writer-type constants.
        $format = strtolower((string) ($options['format']
            ?? pathinfo($filename, PATHINFO_EXTENSION)
            ?: 'xlsx'));

        if ($export instanceof ShouldStream) {
            $this->assertStreamableFormat($export, $format);
        }

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

        $job = (new ProcessExportJob($record->id, $export, $writerType, $format))
            ->onConnection($cfg['connection'] ?? null)
            ->onQueue($cfg['queue'] ?? 'exports');

        dispatch($job);

        return $record;
    }

    /**
     * Fail at dispatch time rather than inside the worker, so a bad format
     * surfaces to whoever clicked the button.
     */
    private function assertStreamableFormat(object $export, string $format): void
    {
        if (!in_array($format, ['xlsx', 'csv', 'ods'], true)) {
            throw new InvalidArgumentException(sprintf(
                '%s implements ShouldStream, which supports xlsx, csv and ods — got "%s". '
                . 'Drop ShouldStream to use the maatwebsite writer instead.',
                $export::class,
                $format
            ));
        }
    }

    private function resolveWriterType(string $format): string
    {
        return match (strtolower($format)) {
            'xlsx' => ExcelFormat::XLSX,
            'csv'  => ExcelFormat::CSV,
            'tsv'  => ExcelFormat::TSV,
            'ods'  => ExcelFormat::ODS,
            'html' => ExcelFormat::HTML,
            default => throw new InvalidArgumentException(
                "Unsupported export format: \"{$format}\". Supported: xlsx, csv, tsv, ods, html."
            ),
        };
    }
}
