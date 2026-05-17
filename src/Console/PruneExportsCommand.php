<?php

namespace Agriserv\Exports\Console;

use Agriserv\Exports\Models\ExportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PruneExportsCommand extends Command
{
    protected $signature = 'exports:prune
        {--days= : Override the configured retention in days}
        {--dry-run : List what would be removed without deleting}';

    protected $description = 'Delete expired export files and their tracking rows.';

    public function handle(): int
    {
        $table = config('exports.table', 'export_jobs');
        if (!Schema::hasTable($table)) {
            $this->warn("Table \"{$table}\" does not exist. Run `php artisan migrate` before pruning.");
            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?? config('exports.retention_days', 7));
        $dry  = (bool) $this->option('dry-run');

        $query = ExportJob::query()->where(function ($q) use ($days) {
            $q->expired();
            if ($days > 0) {
                $q->orWhere('created_at', '<', now()->subDays($days));
            }
        });

        $count = (clone $query)->count();
        $this->info("Found {$count} export(s) eligible for pruning.");

        if ($count === 0) {
            return self::SUCCESS;
        }

        $query->chunkById(200, function ($batch) use ($dry) {
            foreach ($batch as $record) {
                $this->line(" - {$record->id} ({$record->filename}, {$record->status})");
                if ($dry) continue;

                $record->deleteFile();
                $record->delete();
            }
        });

        return self::SUCCESS;
    }
}
