<div
    @if($this->hasActive)
        wire:poll.4s
    @endif
    class="agriserv-exports-list"
>
    @php
        $statusMeta = [
            \Agriserv\Exports\Models\ExportJob::STATUS_PENDING    => ['ar' => 'في الانتظار',   'class' => 'badge bg-secondary'],
            \Agriserv\Exports\Models\ExportJob::STATUS_PROCESSING => ['ar' => 'جاري التنفيذ',  'class' => 'badge bg-info text-dark'],
            \Agriserv\Exports\Models\ExportJob::STATUS_COMPLETED  => ['ar' => 'مكتمل',         'class' => 'badge bg-success'],
            \Agriserv\Exports\Models\ExportJob::STATUS_FAILED     => ['ar' => 'فشل',           'class' => 'badge bg-danger'],
        ];
    @endphp

    @if($this->exports->isEmpty())
        <div class="text-muted text-center py-4">لا توجد طلبات تصدير حتى الآن.</div>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>الملف</th>
                        <th>الحالة</th>
                        <th>الحجم</th>
                        <th>أُنشئ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->exports as $export)
                        @php
                            $meta = $statusMeta[$export->status] ?? ['ar' => $export->status, 'class' => 'badge bg-light text-dark'];
                            $size = $export->file_size
                                ? number_format($export->file_size / 1024, 1) . ' KB'
                                : '—';
                        @endphp
                        <tr wire:key="export-{{ $export->id }}">
                            <td>
                                <div>{{ $export->label ?: $export->filename }}</div>
                                @if($export->label)
                                    <small class="text-muted">{{ $export->filename }}</small>
                                @endif
                            </td>
                            <td>
                                <span class="{{ $meta['class'] }}">{{ $meta['ar'] }}</span>
                                @if($export->isFailed() && $export->error)
                                    <div><small class="text-danger">{{ \Illuminate\Support\Str::limit($export->error, 120) }}</small></div>
                                @endif
                            </td>
                            <td>{{ $size }}</td>
                            <td>
                                <small class="text-muted" title="{{ $export->created_at }}">
                                    {{ $export->created_at?->diffForHumans() }}
                                </small>
                            </td>
                            <td class="text-end">
                                @if($export->isCompleted())
                                    <a class="btn btn-sm btn-primary" href="{{ $export->downloadUrl() }}">
                                        تحميل
                                    </a>
                                @endif
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    wire:click="deleteExport('{{ $export->id }}')"
                                    wire:confirm="حذف هذا التصدير؟"
                                >
                                    حذف
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
