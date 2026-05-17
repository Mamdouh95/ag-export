<?php

namespace Agriserv\Exports\Livewire;

use Agriserv\Exports\Models\ExportJob;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ExportsList extends Component
{
    public int $limit = 15;

    public function getExportsProperty()
    {
        $user = Auth::user();
        if (!$user) {
            return collect();
        }

        return ExportJob::query()
            ->forUser($user)
            ->latest('created_at')
            ->limit($this->limit)
            ->get();
    }

    public function getHasActiveProperty(): bool
    {
        return $this->exports->contains(fn ($e) => !$e->isFinished());
    }

    public function deleteExport(string $id): void
    {
        $user = Auth::user();
        if (!$user) return;

        $record = ExportJob::query()->forUser($user)->whereKey($id)->first();
        if (!$record) return;

        $record->deleteFile();
        $record->delete();
    }

    public function render(): View
    {
        return view('exports::livewire.exports-list');
    }
}
