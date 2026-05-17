<?php

namespace Agriserv\Exports\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * @property string $id
 * @property string|null $user_type
 * @property int|null $user_id
 * @property string|null $label
 * @property string $filename
 * @property string $disk
 * @property string|null $path
 * @property string $status
 * @property int|null $total_rows
 * @property int|null $file_size
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ExportJob extends Model
{
    use HasUuids;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';

    protected $guarded = [];

    protected $casts = [
        'total_rows'   => 'integer',
        'file_size'    => 'integer',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function getTable(): string
    {
        return config('exports.table', 'export_jobs');
    }

    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool    { return $this->status === self::STATUS_PENDING; }
    public function isProcessing(): bool { return $this->status === self::STATUS_PROCESSING; }
    public function isCompleted(): bool  { return $this->status === self::STATUS_COMPLETED; }
    public function isFailed(): bool     { return $this->status === self::STATUS_FAILED; }
    public function isFinished(): bool   { return $this->isCompleted() || $this->isFailed(); }

    public function markProcessing(): void
    {
        $this->forceFill([
            'status'     => self::STATUS_PROCESSING,
            'started_at' => $this->started_at ?? now(),
        ])->save();
    }

    public function markCompleted(string $path, ?int $fileSize, ?int $totalRows = null): void
    {
        $this->forceFill([
            'status'      => self::STATUS_COMPLETED,
            'path'        => $path,
            'file_size'   => $fileSize,
            'total_rows'  => $totalRows ?? $this->total_rows,
            'finished_at' => now(),
            'error'       => null,
        ])->save();
    }

    public function markFailed(?string $error): void
    {
        $this->forceFill([
            'status'      => self::STATUS_FAILED,
            'error'       => $error ? mb_substr($error, 0, 5000) : null,
            'finished_at' => now(),
        ])->save();
    }

    public function deleteFile(): void
    {
        if ($this->path && Storage::disk($this->disk)->exists($this->path)) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }

    public function downloadUrl(?int $minutes = 30): ?string
    {
        if (!$this->isCompleted() || !$this->path) {
            return null;
        }

        return URL::temporarySignedRoute(
            config('exports.routes.name', 'exports.') . 'download',
            now()->addMinutes($minutes),
            ['exportJob' => $this->id]
        );
    }

    public function scopeForUser($q, $user)
    {
        if (!$user) return $q->whereRaw('1=0');
        return $q->where('user_type', $user::class)->where('user_id', $user->getKey());
    }

    public function scopeUnfinished($q)
    {
        return $q->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    public function scopeExpired($q)
    {
        return $q->whereNotNull('expires_at')->where('expires_at', '<', now());
    }
}
