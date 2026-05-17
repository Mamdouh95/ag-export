<?php

namespace Agriserv\Exports\Http\Controllers;

use Agriserv\Exports\Models\ExportJob;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $exportJob): StreamedResponse
    {
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired download link.');
        }

        /** @var ExportJob|null $record */
        $record = ExportJob::find($exportJob);

        if (!$record) {
            abort(404);
        }

        $user = Auth::user();
        if ($record->user_id && $user) {
            if ($record->user_type !== $user::class || (string) $record->user_id !== (string) $user->getKey()) {
                abort(403);
            }
        }

        if (!$record->isCompleted() || !$record->path) {
            abort(409, 'Export is not ready.');
        }

        $disk = Storage::disk($record->disk);

        if (!$disk->exists($record->path)) {
            abort(410, 'Export file is no longer available.');
        }

        return $disk->download($record->path, $record->filename);
    }
}
