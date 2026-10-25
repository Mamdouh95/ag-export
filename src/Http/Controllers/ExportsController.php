<?php

namespace Agriserv\Exports\Http\Controllers;

use Agriserv\Exports\Models\ExportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ExportsController extends Controller
{
    /**
     * The signed-in user's latest exports, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 15), 1), 50);

        $exports = ExportJob::query()
            ->forUser($request->user())
            ->latest('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'data'       => $exports->map->toListItem()->values(),
            'has_active' => $exports->contains(fn (ExportJob $e) => !$e->isFinished()),
        ]);
    }

    public function destroy(Request $request, string $exportJob): JsonResponse
    {
        $record = ExportJob::query()->forUser($request->user())->whereKey($exportJob)->first();

        if (!$record) {
            abort(404);
        }

        $record->deleteFile();
        $record->delete();

        return response()->json(['deleted' => true]);
    }
}
