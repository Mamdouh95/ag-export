<?php

namespace Agriserv\Exports\Jobs;

use Agriserv\Exports\Models\ExportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ProcessExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $exportJobId,
        public object $export,
        public ?string $writerType = null,
    ) {
    }

    public function tries(): int
    {
        return (int) (config('exports.queue.tries') ?? 3);
    }

    public function timeout(): int
    {
        return (int) (config('exports.queue.timeout') ?? 1800);
    }

    public function backoff(): array
    {
        $b = config('exports.queue.backoff');
        return is_array($b) && $b ? $b : [30, 120, 300];
    }

    public function handle(): void
    {
        $record = ExportJob::find($this->exportJobId);
        if (!$record) {
            return; // record was deleted while queued
        }

        if ($record->isCompleted()) {
            return; // idempotency: re-running a finished job is a no-op
        }

        $record->markProcessing();

        $disk     = $record->disk ?: config('exports.disk', 'local');
        $basePath = trim((string) config('exports.path', 'exports'), '/');
        $relative = ($basePath !== '' ? $basePath . '/' : '') . $record->id . '/' . $record->filename;

        try {
            Excel::store($this->export, $relative, $disk, $this->writerType);

            $size = null;
            try {
                $size = Storage::disk($disk)->size($relative);
            } catch (Throwable) {
                // size lookup is best-effort
            }

            $record->markCompleted($relative, $size);
        } catch (Throwable $e) {
            $record->markFailed($e->getMessage());
            throw $e; // let the queue retry per config
        }
    }

    public function failed(Throwable $e): void
    {
        $record = ExportJob::find($this->exportJobId);
        if ($record && !$record->isCompleted()) {
            $record->markFailed($e->getMessage());
        }
    }
}
