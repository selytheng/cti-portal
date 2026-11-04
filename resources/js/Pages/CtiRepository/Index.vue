<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import ReportModal from '@/Components/Cti/ReportModal.vue';

const props = defineProps({ records: Object, filters: Object, templates: Array });

const page = usePage();
const filters = ref({
    per_page: 20,
    ...props.filters,
});
const selected = ref([]);
const reportIds = ref([]);
const showReport = ref(false);
const showFilters = ref(false);
const showStatusModal = ref(false);
const selectedStatus = ref('New');

const workflowStatuses = [
    'New',
    'Developing',
    'Under Review',
    'Verified',
    'Published',
    'False Positive',
    'Archived',
    'Not Applicable',
];

const list = computed(() => props.records.data ?? props.records);
const allSelected = computed(() => list.value.length > 0 && selected.value.length === list.value.length);

const activeFilterCount = computed(() => {
    let count = 0;
    const f = filters.value;
    if (f.search) count++;
    if (f.type) count++;
    if (f.severity) count++;
    if (f.workflow_status) count++;
    if (f.ioc_verdict) count++;
    if (f.modified_from) count++;
    if (f.modified_to) count++;
    if (f.developing) count++;
    if (f.focus_matched) count++;
    return count;
});

function applyFilters() {
    router.get(route('cti.index'), { ...filters.value }, { preserveState: true });
}

function resetFilters() {
    filters.value = { per_page: filters.value.per_page ?? 20 };
    router.get(route('cti.index'), { per_page: filters.value.per_page }, { preserveState: true });
}

function toggleAll(e) {
    selected.value = e.target.checked ? list.value.map((r) => r.id) : [];
}

function toggleRow(id) {
    const i = selected.value.indexOf(id);
    i === -1 ? selected.value.push(id) : selected.value.splice(i, 1);
}

function openBulkStatusModal() {
    if (!selected.value.length) return;
    selectedStatus.value = 'New';
    showStatusModal.value = true;
}

function confirmBulkStatus() {
    if (!selected.value.length || !selectedStatus.value) return;
    router.post(
        route('cti.bulk-status'),
        { ids: selected.value, workflow_status: selectedStatus.value },
        {
            preserveState: true,
            onSuccess: () => {
                selected.value = [];
                showStatusModal.value = false;
            },
        }
    );
}

function bulkDestroy() {
    if (!selected.value.length) return;
    if (!confirm(`Delete ${selected.value.length} CTIs? This cannot be undone.`)) return;
    router.post(route('cti.bulk-destroy'), { ids: selected.value }, { preserveState: true, onSuccess: () => (selected.value = []) });
}

function exportCsv() {
    window.location = route('cti.export-csv', selected.value.length ? { ids: selected.value } : {});
}

function exportStix() {
    window.location = route('cti.export-stix', selected.value.length ? { ids: selected.value } : {});
}

function quickReport(ids) {
    reportIds.value = ids;
    showReport.value = true;
}

function destroy(record) {
    if (confirm(`Delete ${record.cti_id}? This cannot be undone.`)) {
        router.delete(route('cti.destroy', record.id));
    }
}

function fmt(v) {
    return v ? new Date(v).toLocaleString() : '—';
}

function sevClass(sev) {
    return {
        Critical: 'bg-red-500/20 text-red-600 dark:text-red-400',
        High: 'bg-orange-500/20 text-orange-600 dark:text-orange-400',
        Medium: 'bg-amber-500/20 text-amber-600 dark:text-amber-400',
        Low: 'bg-blue-500/20 text-blue-600 dark:text-blue-400',
        Informational: 'bg-slate-500/20 text-slate-500',
    }[sev] ?? 'bg-gray-500/20 text-gray-500';
}

function isRecentlyUpdated(r) {
    return r.modified_at && r.created_at && new Date(r.modified_at) > new Date(r.created_at);
}
</script>

<template>
    <Head title="CTI Repository" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">CTI Repository</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.success"
                    class="mb-4 rounded-lg border border-emerald-500/50 bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
                >
                    {{ page.props.flash.success }}
                </div>

                <!-- Top bar with Filter Toggle -->
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <SecondaryButton
                        type="button"
                        @click="showFilters = !showFilters"
                        class="flex items-center gap-2"
                    >
                        <span>{{ showFilters ? '✕ Hide Filter' : '🔍 Open Filter to Search' }}</span>
                        <span
                            v-if="activeFilterCount > 0"
                            class="rounded-full bg-indigo-500/20 px-2 py-0.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400"
                        >
                            {{ activeFilterCount }} active
                        </span>
                    </SecondaryButton>
                </div>

                <!-- Filter bar (hidden initially, toggled by button) -->
                <div
                    v-if="showFilters"
                    class="mb-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <form @submit.prevent="applyFilters" class="flex flex-wrap items-end gap-3">
                        <div class="grow sm:grow-0 sm:w-72">
                            <label class="mb-1 block text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Full-text search</label>
                            <TextInput v-model="filters.search" type="search" placeholder="Subject, summary, CVE ID…" class="w-full" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Type</label>
                            <select v-model="filters.type" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option value="">—</option><option value="Vul">Vul</option><option value="Mal">Mal</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Severity</label>
                            <select v-model="filters.severity" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option value="">—</option>
                                <option v-for="s in ['Critical','High','Medium','Low','Informational']" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Workflow</label>
                            <select v-model="filters.workflow_status" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option value="">—</option>
                                <option v-for="s in workflowStatuses" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">IOC verdict</label>
                            <select v-model="filters.ioc_verdict" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option value="">—</option>
                                <option v-for="v in ['Malicious','Suspicious','Clean','Unknown']" :key="v" :value="v">{{ v }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Modified from</label>
                            <TextInput v-model="filters.modified_from" type="date" class="w-36" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Modified to</label>
                            <TextInput v-model="filters.modified_to" type="date" class="w-36" />
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" v-model="filters.developing" class="rounded" /> Developing
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" v-model="filters.focus_matched" class="rounded" /> Focus matched
                        </label>
                        <div class="flex gap-2">
                            <PrimaryButton type="submit">Apply</PrimaryButton>
                            <SecondaryButton type="button" @click="resetFilters">Reset</SecondaryButton>
                        </div>
                    </form>
                </div>

                <!-- Bulk bar -->
                <div
                    v-if="selected.length"
                    class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-indigo-500/40 bg-indigo-500/10 p-4 text-sm"
                >
                    <span class="font-semibold text-indigo-600 dark:text-indigo-400">{{ selected.length }} selected</span>
                    <SecondaryButton type="button" @click="openBulkStatusModal">Set status</SecondaryButton>
                    <SecondaryButton type="button" @click="exportCsv">Export CSV</SecondaryButton>
                    <SecondaryButton type="button" @click="exportStix">Export STIX 2.1</SecondaryButton>
                    <SecondaryButton type="button" @click="quickReport(selected)">Consolidated Report</SecondaryButton>
                    <SecondaryButton type="button" @click="bulkDestroy" class="!text-red-600 dark:!text-red-400">Delete</SecondaryButton>
                </div>

                <!-- Table -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-3"><input type="checkbox" :checked="allSelected" @change="toggleAll" class="rounded" /></th>
                                    <th class="px-4 py-3">CTI ID</th>
                                    <th class="px-4 py-3">Subject</th>
                                    <th class="px-4 py-3">Type</th>
                                    <th class="px-4 py-3">Severity</th>
                                    <th class="px-4 py-3">TLP</th>
                                    <th class="px-4 py-3">Workflow</th>
                                    <th class="px-4 py-3">IOCs</th>
                                    <th class="px-4 py-3">Modified</th>
                                    <th class="px-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="record in list" :key="record.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-4 py-3">
                                        <input type="checkbox" :checked="selected.includes(record.id)" @change="toggleRow(record.id)" class="rounded" />
                                    </td>
                                    <td class="px-4 py-3 font-mono text-gray-600 dark:text-gray-300">{{ record.cti_id }}</td>
                                    <td class="max-w-sm px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ record.subject }}</span>
                                            <span v-if="isRecentlyUpdated(record)" class="rounded-full bg-amber-500/20 px-2 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400">Recently Updated</span>
                                            <span v-if="record.is_developing" class="rounded-full bg-blue-500/20 px-2 py-0.5 text-xs font-semibold text-blue-600 dark:text-blue-400">Developing</span>
                                        </div>
                                        <div v-if="(record.matched_focus_keywords ?? []).length" class="mt-1 flex flex-wrap gap-1">
                                            <span v-for="kw in record.matched_focus_keywords" :key="kw" class="rounded-full bg-indigo-500/15 px-2 py-0.5 text-xs text-indigo-600 dark:text-indigo-400">{{ kw }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ record.type }}</td>
                                    <td class="px-4 py-3">
                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', sevClass(record.severity)]">{{ record.severity }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ record.tlp }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ record.workflow_status }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ (record.iocs ?? []).length }}</td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ fmt(record.modified_at) }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex gap-2">
                                            <button type="button" @click="router.get(route('cti.show', record.id))" class="text-indigo-600 hover:underline dark:text-indigo-400">View</button>
                                            <button type="button" @click="quickReport([record.id])" class="text-gray-600 hover:underline dark:text-gray-300">Report</button>
                                            <button type="button" @click="destroy(record)" class="text-red-600 hover:underline dark:text-red-400">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="list.length === 0">
                                    <td colspan="10" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No CTI records match these filters.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination & Per Page -->
                    <div class="flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <span>Rows per page:</span>
                            <select
                                v-model="filters.per_page"
                                @change="applyFilters"
                                class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                            >
                                <option :value="20">20</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="1000">1000</option>
                            </select>
                            <span v-if="records.total !== undefined" class="ml-2 text-xs text-gray-500">
                                (Total: {{ records.total }})
                            </span>
                        </div>

                        <div v-if="(records.links ?? []).length > 3" class="flex flex-wrap gap-1">
                            <component
                                v-for="(link, i) in records.links"
                                :key="i"
                                :is="link.url ? Link : 'span'"
                                :href="link.url ?? undefined"
                                class="rounded px-3 py-1 text-sm"
                                :class="link.active
                                    ? 'bg-indigo-600 text-white'
                                    : (link.url ? 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' : 'text-gray-400')"
                                v-html="link.label"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bulk Status Selection Modal -->
        <div v-if="showStatusModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl dark:bg-gray-800">
                <h3 class="mb-2 text-lg font-semibold text-gray-800 dark:text-gray-200">
                    Update Workflow Status
                </h3>
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    Select the new workflow status for {{ selected.length }} selected CTI{{ selected.length > 1 ? 's' : '' }}.
                </p>

                <div class="mb-5">
                    <label class="mb-1 block text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Workflow Status
                    </label>
                    <select
                        v-model="selectedStatus"
                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    >
                        <option v-for="s in workflowStatuses" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>

                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" @click="showStatusModal = false">Cancel</SecondaryButton>
                    <PrimaryButton type="button" @click="confirmBulkStatus">Update Status</PrimaryButton>
                </div>
            </div>
        </div>

        <ReportModal :show="showReport" :templates="templates" :cti-ids="reportIds" title="CTI Report" @close="showReport = false" />
    </AuthenticatedLayout>
</template>
