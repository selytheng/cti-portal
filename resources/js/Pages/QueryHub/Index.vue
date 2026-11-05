<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    activeQueries: Array,
    history: Object,
    perPage: Number,
    feeds: Array,
    aiIntegrations: Array,
    canExecute: Boolean,
});

const page = usePage();
const showRunModal = ref(false);
const expandedRun = ref(null);
const perPage = ref(props.perPage ?? 20);

function changePerPage() {
    router.get(route('queryhub.index'), { per_page: perPage.value }, { preserveState: true, replace: true });
}

function openRunModal() {
    form.lookback_days = 1;
    showRunModal.value = true;
}

// ===== "Run Intelligence Query" + Days-Ago pop-up (spec Section 4) =====
const form = useForm({
    lookback_days: 1,
    feed_ids: [],
    use_keywords: true,
    ai_integration_id: '',
    match_existing: true,
    match_lookback_days: 7,
});

const presets = [1, 3, 7, 14, 30];
const matchPeriods = [1, 7, 30];

const isAllFeedsSelected = computed(() => {
    return props.feeds.length > 0 && form.feed_ids.length === props.feeds.length;
});

function toggleAllFeeds() {
    if (isAllFeedsSelected.value) {
        form.feed_ids = [];
    } else {
        form.feed_ids = props.feeds.map((f) => f.id);
    }
}

function setPreset(days) {
    form.lookback_days = days;
}

function toggleFeed(id) {
    const i = form.feed_ids.indexOf(id);
    i === -1 ? form.feed_ids.push(id) : form.feed_ids.splice(i, 1);
}

function runQuery() {
    form.transform((data) => ({ ...data, ai_integration_id: data.ai_integration_id || null }))
        .post(route('queryhub.store'), {
            preserveState: true,
            onSuccess: () => {
                showRunModal.value = false;
                form.feed_ids = [];
                form.lookback_days = 1;
                form.match_lookback_days = 7;
            },
        });
}

// ===== Live updates: Reverb listener + polling fallback (spec Section 4) =====
let pollTimer = null;

function refresh() {
    router.reload({ only: ['activeQueries', 'history'] });
}

onMounted(() => {
    if (window.Echo) {
        window.Echo.private('query-runs').listen('.progress', () => refresh());
    }
    pollTimer = setInterval(refresh, 10000);
});

onUnmounted(() => {
    if (pollTimer) {
        clearInterval(pollTimer);
    }
});

// ===== formatting =====
function elapsed(run) {
    if (!run.started_at) {
        return '—';
    }
    const end = run.completed_at ? new Date(run.completed_at) : new Date();

    return Math.max(0, Math.round((end - new Date(run.started_at)) / 1000)) + 's';
}

function statusClass(status) {
    return {
        queued: 'bg-gray-500/20 text-gray-500',
        running: 'bg-indigo-500/20 text-indigo-600 dark:text-indigo-400',
        completed: 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400',
        failed: 'bg-red-500/20 text-red-600 dark:text-red-400',
        cancelled: 'bg-amber-500/20 text-amber-600 dark:text-amber-400',
    }[status];
}

function cancel(run) {
    router.post(route('queryhub.cancel', run.id), {}, { preserveState: true });
}

function toggleExpand(id) {
    expandedRun.value = expandedRun.value === id ? null : id;
}

const historyList = computed(() => props.history.data ?? props.history);
</script>

<template>
    <Head title="Query Hub" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Query Hub</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.success"
                    class="mb-4 rounded-lg border border-emerald-500/50 bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
                >
                    {{ page.props.flash.success }}
                </div>

                <!-- Run button -->
                <div class="mb-6 flex items-center justify-between">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Pull fresh threat intelligence from your configured feeds within a lookback window.
                    </p>
                    <PrimaryButton v-if="canExecute" @click="openRunModal">
                        ⚡ Run Intelligence Query
                    </PrimaryButton>
                </div>

                <!-- ===== Live Active Queries Panel (spec 4) ===== -->
                <div class="mb-8">
                    <h3 class="mb-3 text-sm font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Active Queries
                    </h3>

                    <div v-if="activeQueries.length === 0" class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
                        No queries running right now.
                    </div>

                    <div v-else class="grid gap-3">
                        <div
                            v-for="run in activeQueries"
                            :key="run.id"
                            class="rounded-lg border border-indigo-500/30 bg-white p-4 shadow-sm dark:border-indigo-500/40 dark:bg-gray-800"
                        >
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="font-mono text-gray-500 dark:text-gray-400">#{{ run.id }}</span>
                                    <span class="font-semibold text-gray-800 dark:text-gray-200">{{ run.user?.name ?? 'System Scheduler' }}</span>
                                    <span class="rounded-full bg-indigo-500/15 px-2 py-0.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400">
                                        {{ run.lookback_days }} days ago
                                    </span>
                                </div>
                                <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                    <span>⏱ {{ elapsed(run) }}</span>
                                    <SecondaryButton
                                        v-if="canExecute && (page.props.can?.manageApiKeys || run.user?.id === page.props.auth.user?.id)"
                                        type="button"
                                        @click="cancel(run)"
                                        class="!px-2 !py-1 text-xs"
                                    >
                                        Cancel
                                    </SecondaryButton>
                                </div>
                            </div>

                            <div class="mb-1 flex items-center justify-between text-xs text-gray-600 dark:text-gray-300">
                                <span class="font-semibold">{{ run.current_stage ?? run.status }}</span>
                                <span>{{ run.progress_percent }}%</span>
                            </div>
                            <div class="mb-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                <div
                                    class="h-full rounded-full bg-indigo-500 transition-all duration-500"
                                    :style="{ width: run.progress_percent + '%' }"
                                />
                            </div>

                            <div class="flex flex-wrap gap-4 text-xs text-gray-500 dark:text-gray-400">
                                <span>Feeds: {{ run.feeds_processed }}</span>
                                <span>In window: {{ run.items_in_date_window }}</span>
                                <span>Filtered: {{ run.items_filtered_exception }}</span>
                                <span>Dupes: {{ run.duplicates_skipped }}</span>
                                <span class="text-emerald-600 dark:text-emerald-400">New CTIs: {{ run.ctis_created }}</span>
                                <span class="text-amber-600 dark:text-amber-400">Updated: {{ run.ctis_updated }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== Query Execution History (spec 4) ===== -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="border-b border-gray-200 p-4 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">Query Execution History</h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-3">ID</th>
                                    <th class="px-4 py-3">Triggered By</th>
                                    <th class="px-4 py-3">Lookback</th>
                                    <th class="px-4 py-3">Started</th>
                                    <th class="px-4 py-3">Duration</th>
                                    <th class="px-4 py-3">Feeds</th>
                                    <th class="px-4 py-3">In window</th>
                                    <th class="px-4 py-3">Filtered</th>
                                    <th class="px-4 py-3">New CTIs</th>
                                    <th class="px-4 py-3">Updated</th>
                                    <th class="px-4 py-3">Dupes</th>
                                    <th class="px-4 py-3">AI</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Log</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="run in historyList" :key="run.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-4 py-3 font-mono text-gray-600 dark:text-gray-300">#{{ run.id }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ run.user?.name ?? 'System Scheduler' }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ run.lookback_days }}d</td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ run.started_at ? new Date(run.started_at).toLocaleString() : '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ elapsed(run) }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ run.feeds_processed }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ run.items_in_date_window }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ run.items_filtered_exception }}</td>
                                    <td class="px-4 py-3 font-semibold text-emerald-600 dark:text-emerald-400">{{ run.ctis_created }}</td>
                                    <td class="px-4 py-3 font-semibold text-amber-600 dark:text-amber-400">{{ run.ctis_updated }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ run.duplicates_skipped }}</td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ run.ai_provider_used ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', statusClass(run.status)]">
                                            {{ run.status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <button
                                            type="button"
                                            @click="toggleExpand(run.id)"
                                            class="text-indigo-600 hover:underline dark:text-indigo-400"
                                        >
                                            {{ expandedRun === run.id ? 'Hide' : 'View' }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="expandedRun !== null && historyList.find((r) => r.id === expandedRun)">
                                    <td colspan="14" class="bg-gray-50 px-4 py-4 dark:bg-gray-900">
                                        <div class="mb-2 flex flex-wrap gap-1">
                                            <span
                                                v-for="id in historyList.find((r) => r.id === expandedRun)?.created_cti_ids ?? []"
                                                :key="'c' + id"
                                                class="rounded-full bg-emerald-500/15 px-2 py-0.5 font-mono text-xs text-emerald-600 dark:text-emerald-400"
                                            >
                                                CTI #{{ id }} created
                                            </span>
                                            <span
                                                v-for="id in historyList.find((r) => r.id === expandedRun)?.updated_cti_ids ?? []"
                                                :key="'u' + id"
                                                class="rounded-full bg-amber-500/15 px-2 py-0.5 font-mono text-xs text-amber-600 dark:text-amber-400"
                                            >
                                                CTI #{{ id }} updated
                                            </span>
                                            <span
                                                v-if="!(historyList.find((r) => r.id === expandedRun)?.created_cti_ids ?? []).length && !(historyList.find((r) => r.id === expandedRun)?.updated_cti_ids ?? []).length"
                                                class="text-xs text-gray-500 dark:text-gray-400"
                                            >
                                                No CTIs created or updated by this run.
                                            </span>
                                        </div>
                                        <pre
                                            v-if="historyList.find((r) => r.id === expandedRun)?.error_log"
                                            class="overflow-auto rounded bg-gray-900 p-3 text-xs text-red-300"
                                        >{{ historyList.find((r) => r.id === expandedRun).error_log }}</pre>
                                    </td>
                                </tr>
                                <tr v-if="historyList.length === 0">
                                    <td colspan="14" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                        No query history yet — run your first intelligence query.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination & Per Page -->
                    <div class="flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <span>Rows per page:</span>
                            <select
                                v-model="perPage"
                                @change="changePerPage"
                                class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                            >
                                <option :value="20">20</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="1000">1000</option>
                            </select>
                            <span v-if="history.total !== undefined" class="ml-2 text-xs text-gray-500">
                                (Total: {{ history.total }})
                            </span>
                        </div>

                        <div v-if="(history.links ?? []).length > 3" class="flex flex-wrap gap-1">
                            <component
                                v-for="(link, i) in history.links"
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

        <!-- ===== Days-Ago Query Configuration Pop-Up Modal (spec 4) ===== -->
        <div v-if="showRunModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-lg bg-white p-6 dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-gray-200">Run Intelligence Query</h3>

                <form @submit.prevent="runQuery" class="space-y-5">
                    <div>
                        <InputLabel value="Lookback duration (days ago)" />
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button
                                v-for="p in presets"
                                :key="p"
                                type="button"
                                @click="setPreset(p)"
                                class="rounded-full px-3 py-1 text-xs font-semibold transition-colors"
                                :class="form.lookback_days === p
                                    ? 'bg-indigo-600 text-white'
                                    : 'bg-gray-100 text-gray-600 hover:bg-indigo-100 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-indigo-500/20'"
                            >
                                {{ p }} Day{{ p > 1 ? 's' : '' }} Ago
                            </button>
                        </div>
                        <TextInput v-model.number="form.lookback_days" type="number" min="1" max="365" class="mt-2 block w-32" required />
                        <InputError :message="form.errors.lookback_days" class="mt-1" />
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <InputLabel value="Feeds" />
                            <button
                                v-if="feeds.length > 0"
                                type="button"
                                @click="toggleAllFeeds"
                                class="text-xs font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                            >
                                {{ isAllFeedsSelected ? 'Deselect All' : 'Select All (' + feeds.length + ')' }}
                            </button>
                        </div>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ form.feed_ids.length === 0 ? 'Leave all unchecked = all active feeds' : `${form.feed_ids.length} of ${feeds.length} feed(s) selected` }}
                        </p>
                        <div class="mt-2 max-h-36 space-y-1 overflow-y-auto rounded-md border border-gray-200 p-2 dark:border-gray-700">
                            <label
                                v-for="feed in feeds"
                                :key="feed.id"
                                class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"
                            >
                                <input type="checkbox" :checked="form.feed_ids.includes(feed.id)" @change="toggleFeed(feed.id)" class="rounded" />
                                {{ feed.name }}
                            </label>
                            <div v-if="feeds.length === 0" class="text-xs text-gray-400">No active feeds configured.</div>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" v-model="form.use_keywords" class="rounded" />
                        Apply Focus / Exception keywords
                    </label>

                    <div>
                        <InputLabel value="AI provider" />
                        <select v-model="form.ai_integration_id" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">Default provider</option>
                            <option v-for="ai in aiIntegrations" :key="ai.id" :value="ai.id">
                                {{ ai.name }} ({{ ai.provider }} · {{ ai.model_id }}){{ ai.is_default ? ' ★' : '' }}
                            </option>
                        </select>
                        <p class="mt-1 text-xs text-gray-400">Without a configured provider, records are created in fallback (developing) mode.</p>
                    </div>

                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" v-model="form.match_existing" class="rounded" />
                            Match &amp; update existing developing CTIs
                        </label>

                        <div v-if="form.match_existing" class="rounded-md border border-indigo-500/20 bg-indigo-50/50 p-3 dark:border-indigo-500/30 dark:bg-indigo-950/20">
                            <InputLabel value="Period of update to match &amp; update records" class="text-xs font-semibold" />
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button
                                    v-for="d in matchPeriods"
                                    :key="d"
                                    type="button"
                                    @click="form.match_lookback_days = d"
                                    class="rounded-full px-3 py-1 text-xs font-semibold transition-colors"
                                    :class="form.match_lookback_days === d
                                        ? 'bg-indigo-600 text-white'
                                        : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                                >
                                    {{ d }} Day{{ d > 1 ? 's' : '' }} Ago
                                </button>
                            </div>
                            <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                                Checks internal database for matching CTIs updated or created within the last {{ form.match_lookback_days }} day{{ form.match_lookback_days > 1 ? 's' : '' }}.
                            </p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <SecondaryButton type="button" @click="showRunModal = false">Cancel</SecondaryButton>
                        <PrimaryButton type="submit" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            ⚡ Run Query
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
