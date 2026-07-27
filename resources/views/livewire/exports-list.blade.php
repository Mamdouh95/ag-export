@php
    use Agriserv\Exports\Models\ExportJob;
    use Illuminate\Support\Str;

    $statusMeta = [
        ExportJob::STATUS_PENDING    => ['label' => 'في الانتظار',  'class' => 'bg-gray-100 text-gray-700'],
        ExportJob::STATUS_PROCESSING => ['label' => 'جاري التنفيذ', 'class' => 'bg-sky-100 text-sky-800'],
        ExportJob::STATUS_COMPLETED  => ['label' => 'مكتمل',        'class' => 'bg-emerald-100 text-emerald-800'],
        ExportJob::STATUS_FAILED     => ['label' => 'فشل',          'class' => 'bg-red-100 text-red-700'],
    ];
@endphp

<div
    @if($this->hasActive)
        wire:poll.4s
    @endif
    class="agriserv-exports-list bg-white"
    dir="rtl"
>
    @if($this->exports->isEmpty())
        <div class="px-4 py-8 text-center text-sm text-gray-500">
            لا توجد طلبات تصدير حتى الآن.
        </div>
    @else
        <ul class="divide-y divide-gray-100 max-h-96 overflow-y-auto">
            @foreach($this->exports as $export)
                @php
                    $meta = $statusMeta[$export->status] ?? ['label' => $export->status, 'class' => 'bg-gray-100 text-gray-700'];
                    $size = $export->file_size ? number_format($export->file_size / 1024, 1) . ' KB' : null;
                    $rows = $export->total_rows ? number_format($export->total_rows) . ' صف' : null;
                @endphp
                <li class="px-4 py-3 hover:bg-gray-50" wire:key="export-{{ $export->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium text-gray-900 truncate">
                                {{ $export->label ?: $export->filename }}
                            </div>

                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium {{ $meta['class'] }}">
                                    {{ $meta['label'] }}
                                </span>
                                @if($rows)
                                    <span aria-hidden="true">•</span>
                                    <span>{{ $rows }}</span>
                                @endif
                                @if($size)
                                    <span aria-hidden="true">•</span>
                                    <span>{{ $size }}</span>
                                @endif
                                <span aria-hidden="true">•</span>
                                <span title="{{ $export->created_at }}">
                                    {{ $export->created_at?->diffForHumans() }}
                                </span>
                            </div>

                            @if($export->isFailed() && $export->error)
                                <div class="mt-1 text-xs text-red-600 break-words">
                                    {{ Str::limit($export->error, 120) }}
                                </div>
                            @endif
                        </div>

                        <div class="flex items-center gap-1 shrink-0">
                            @if($export->isCompleted())
                                <a
                                    href="{{ $export->downloadUrl() }}"
                                    class="inline-flex items-center justify-center w-8 h-8 rounded-md bg-emerald-600 text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    title="تحميل"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                                    </svg>
                                </a>
                            @endif

                            <button
                                type="button"
                                wire:click="deleteExport('{{ $export->id }}')"
                                wire:confirm="حذف هذا التصدير؟"
                                class="inline-flex items-center justify-center w-8 h-8 rounded-md text-gray-400 hover:text-red-600 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500"
                                title="حذف"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
