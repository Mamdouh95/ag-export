<script setup>
/**
 * Vue twin of <livewire:exports.list />. Publish with
 *   php artisan vendor:publish --tag=exports-vue
 * and include the copied file in your Tailwind sources.
 *
 * Polls while any export is pending/processing. Dispatch
 *   window.dispatchEvent(new Event('exports:refresh'))
 * after queueing an export to show it right away.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    endpoint: { type: String, default: '/exports/jobs' },
    limit: { type: Number, default: 15 },
    pollInterval: { type: Number, default: 4000 },
});

const exports = ref([]);
const hasActive = ref(false);
const loaded = ref(false);
let timer = null;

const statusMeta = {
    pending: { label: 'في الانتظار', class: 'bg-gray-100 text-gray-700' },
    processing: { label: 'جاري التنفيذ', class: 'bg-sky-100 text-sky-800' },
    completed: { label: 'مكتمل', class: 'bg-emerald-100 text-emerald-800' },
    failed: { label: 'فشل', class: 'bg-red-100 text-red-700' },
};

const isEmpty = computed(() => loaded.value && exports.value.length === 0);

function meta(status) {
    return statusMeta[status] ?? { label: status, class: 'bg-gray-100 text-gray-700' };
}

function size(bytes) {
    return bytes ? `${(bytes / 1024).toLocaleString('en', { maximumFractionDigits: 1, minimumFractionDigits: 1 })} KB` : null;
}

function rows(count) {
    return count ? `${count.toLocaleString('en')} صف` : null;
}

function headers() {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-CSRF-TOKEN': token } : {}),
    };
}

async function refresh() {
    clearTimeout(timer);

    try {
        const response = await fetch(`${props.endpoint}?limit=${props.limit}`, {
            headers: headers(),
            credentials: 'same-origin',
        });

        if (response.ok) {
            const body = await response.json();
            exports.value = body.data;
            hasActive.value = body.has_active;
        }
    } catch {
        // Network blip: keep the last list, the next poll retries.
    } finally {
        loaded.value = true;
        if (hasActive.value) {
            timer = setTimeout(refresh, props.pollInterval);
        }
    }
}

async function remove(id) {
    if (!window.confirm('حذف هذا التصدير؟')) {
        return;
    }

    await fetch(`${props.endpoint}/${id}`, {
        method: 'DELETE',
        headers: headers(),
        credentials: 'same-origin',
    });

    await refresh();
}

onMounted(() => {
    refresh();
    window.addEventListener('exports:refresh', refresh);
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    window.removeEventListener('exports:refresh', refresh);
});

defineExpose({ refresh });
</script>

<template>
    <div class="agriserv-exports-list bg-white" dir="rtl">
        <div v-if="isEmpty" class="px-4 py-8 text-center text-sm text-gray-500">
            لا توجد طلبات تصدير حتى الآن.
        </div>

        <ul v-else class="m-0 list-none p-0 divide-y divide-gray-100 max-h-96 overflow-y-auto">
            <li v-for="item in exports" :key="item.id" class="px-4 py-3 hover:bg-gray-50">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium text-gray-900 truncate">{{ item.label }}</div>

                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium" :class="meta(item.status).class">
                                {{ meta(item.status).label }}
                            </span>
                            <template v-if="rows(item.total_rows)">
                                <span aria-hidden="true">•</span>
                                <span>{{ rows(item.total_rows) }}</span>
                            </template>
                            <template v-if="size(item.file_size)">
                                <span aria-hidden="true">•</span>
                                <span>{{ size(item.file_size) }}</span>
                            </template>
                            <span aria-hidden="true">•</span>
                            <span :title="item.created_at">{{ item.created_at_human }}</span>
                        </div>

                        <div v-if="item.error" class="mt-1 text-xs text-red-600 break-words">{{ item.error }}</div>
                    </div>

                    <div class="flex items-center gap-1 shrink-0">
                        <a
                            v-if="item.download_url"
                            :href="item.download_url"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-md bg-emerald-600 text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            title="تحميل"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                            </svg>
                        </a>

                        <button
                            type="button"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-md text-gray-400 hover:text-red-600 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500"
                            title="حذف"
                            @click="remove(item.id)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3" />
                            </svg>
                        </button>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
