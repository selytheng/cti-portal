<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps({
    kpis: Object,
    velocity: Array,
    severityDist: Object,
    tlpDist: Object,
    iocVerdicts: Object,
    topActors: Object,
    topFocusKeywords: Object,
    feedHealth: Array,
    recentRuns: Array,
});

const velocityChart = computed(() => ({
    series: [
        { name: 'Vulnerabilities', data: props.velocity.map((v) => v.vul) },
        { name: 'Malware/Campaigns', data: props.velocity.map((v) => v.mal) },
    ],
    options: {
        chart: { type: 'area', toolbar: { show: false }, background: 'transparent' },
        theme: { mode: 'dark' },
        xaxis: { categories: props.velocity.map((v) => v.date.slice(5)) },
        colors: ['#6366f1', '#f43f5e'],
        stroke: { curve: 'smooth', width: 2 },
        dataLabels: { enabled: false },
        grid: { borderColor: '#374151' },
    },
}));

const severityChart = computed(() => ({
    series: Object.values(props.severityDist ?? {}),
    options: {
        chart: { type: 'donut', background: 'transparent' },
        labels: Object.keys(props.severityDist ?? {}),
        theme: { mode: 'dark' },
        colors: ['#dc2626', '#f97316', '#f59e0b', '#3b82f6', '#64748b'],
        legend: { position: 'bottom' },
    },
}));

const tlpChart = computed(() => ({
    series: [{ name: 'CTIs', data: Object.values(props.tlpDist ?? {}) }],
    options: {
        chart: { type: 'bar', background: 'transparent', toolbar: { show: false } },
        theme: { mode: 'dark' },
        xaxis: { categories: Object.keys(props.tlpDist ?? {}) },
        colors: ['#8b5cf6'],
        grid: { borderColor: '#374151' },
    },
}));

const actorsChart = computed(() => ({
    series: [{ name: 'Mentions', data: Object.values(props.topActors ?? {}) }],
    options: {
        chart: { type: 'bar', background: 'transparent', toolbar: { show: false } },
        theme: { mode: 'dark' },
        xaxis: { categories: Object.keys(props.topActors ?? {}) },
        colors: ['#f43f5e'],
        plotOptions: { bar: { horizontal: true } },
        grid: { borderColor: '#374151' },
    },
}));

const iocChart = computed(() => ({
    series: Object.values(props.iocVerdicts ?? {}),
    options: {
        chart: { type: 'donut', background: 'transparent' },
        labels: Object.keys(props.iocVerdicts ?? {}),
        theme: { mode: 'dark' },
        colors: ['#dc2626', '#f59e0b', '#10b981', '#64748b'],
        legend: { position: 'bottom' },
    },
}));

function fmt(v) {
    return v ? new Date(v).toLocaleString() : 'never';
}

function duration(run) {
    if (!run.started_at) return '—';
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
    }[status] ?? 'bg-gray-500/20 text-gray-500';
}
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Executive Dashboard</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <!-- ===== KPI cards (spec Section 3) ===== -->
                <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="text-3xl font-bold text-indigo-600 dark:text-indigo-400">{{ kpis.total }}</div>
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total CTI</div>
                        <div class="mt-1 text-xs text-gray-400">24h {{ kpis.last24h }} · 7d {{ kpis.last7d }} · 30d {{ kpis.last30d }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="text-3xl font-bold text-amber-600 dark:text-amber-400">{{ kpis.recentlyUpdated }}</div>
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Recently Updated</div>
                        <div class="mt-1 text-xs text-gray-400">{{ kpis.developing }} still developing</div>
                    </div>
                    <div class="rounded-lg border border-red-500/40 bg-red-500/5 p-4 shadow-sm dark:bg-red-500/10">
                        <div class="text-3xl font-bold text-red-600 dark:text-red-400">{{ kpis.criticalHigh }}</div>
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Critical / High</div>
                        <div class="mt-1 text-xs text-gray-400">{{ kpis.kevCves }} with KEV CVEs</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ kpis.maliciousIocs }}</div>
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Malicious IOCs</div>
                        <div class="mt-1 text-xs text-gray-400">of {{ kpis.totalIocs }} tracked</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="text-3xl font-bold text-gray-800 dark:text-gray-200">{{ kpis.activeFeeds }}</div>
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Active Feeds</div>
                        <div class="mt-1 text-xs" :class="kpis.failingFeeds > 0 ? 'text-red-500' : 'text-gray-400'">
                            {{ kpis.failingFeeds }} failing
                        </div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="text-3xl font-bold text-gray-800 dark:text-gray-200">{{ kpis.activeVerifiers }}</div>
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">IOC Verifiers</div>
                        <div class="mt-1 text-xs text-gray-400">
                            queue {{ kpis.queuedRuns }} · failed 24h {{ kpis.failedRuns24h }}
                        </div>
                    </div>
                </div>

                <!-- ===== Threat velocity ===== -->
                <div class="mb-6 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Threat Velocity — Vulnerabilities vs Malware/Campaigns (14d)</h3>
                    <VueApexCharts height="260" :options="velocityChart.options" :series="velocityChart.series" />
                </div>

                <!-- ===== Distributions ===== -->
                <div class="mb-6 grid gap-4 lg:grid-cols-3">
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Severity Distribution</h3>
                        <VueApexCharts height="240" :options="severityChart.options" :series="severityChart.series" />
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">TLP Distribution</h3>
                        <VueApexCharts height="240" :options="tlpChart.options" :series="tlpChart.series" />
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">IOC Verdicts</h3>
                        <VueApexCharts height="240" :options="iocChart.options" :series="iocChart.series" />
                    </div>
                </div>

                <!-- ===== Top actors + focus keywords ===== -->
                <div class="mb-6 grid gap-4 lg:grid-cols-2">
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Top Threat Actors</h3>
                        <VueApexCharts v-if="Object.keys(topActors ?? {}).length" height="220" :options="actorsChart.options" :series="actorsChart.series" />
                        <p v-else class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            No attribution data yet — enable an AI provider for actor extraction.
                        </p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Top Focus Keyword Matches</h3>
                        <div v-if="Object.keys(topFocusKeywords ?? {}).length" class="space-y-2">
                            <div v-for="(count, kw) in topFocusKeywords" :key="kw" class="flex items-center gap-2 text-sm">
                                <span class="w-40 truncate text-gray-700 dark:text-gray-300">{{ kw }}</span>
                                <div class="h-2 grow overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                    <div class="h-full rounded-full bg-indigo-500" :style="{ width: Math.round((count / Math.max(...Object.values(topFocusKeywords))) * 100) + '%' }" />
                                </div>
                                <span class="w-8 text-end text-gray-500">{{ count }}</span>
                            </div>
                        </div>
                        <p v-else class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            No focus keyword matches yet — configure keywords in the Configuration Center.
                        </p>
                    </div>
                </div>

                <!-- ===== Pipeline telemetry (spec Section 3) ===== -->
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="border-b border-gray-200 p-4 dark:border-gray-700">
                            <h3 class="font-semibold text-gray-800 dark:text-gray-200">RSS Feed Health</h3>
                        </div>
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                                <tr><th class="px-4 py-2">Feed</th><th class="px-4 py-2">Last fetch</th><th class="px-4 py-2">HTTP</th><th class="px-4 py-2">Fails</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="feed in feedHealth" :key="feed.id">
                                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">
                                        {{ feed.name }}
                                        <span v-if="!feed.is_active" class="text-xs text-gray-400">(inactive)</span>
                                    </td>
                                    <td class="px-4 py-2 text-gray-500">{{ fmt(feed.last_fetched_at) }}</td>
                                    <td class="px-4 py-2">
                                        <span v-if="feed.last_status_code" :class="feed.last_status_code === 200 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500'">
                                            {{ feed.last_status_code }}
                                        </span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                    <td class="px-4 py-2" :class="feed.failure_count > 0 ? 'text-red-500' : 'text-gray-400'">{{ feed.failure_count }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="border-b border-gray-200 p-4 dark:border-gray-700">
                            <h3 class="font-semibold text-gray-800 dark:text-gray-200">Recent Query Runs</h3>
                        </div>
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                                <tr><th class="px-4 py-2">ID</th><th class="px-4 py-2">Trigger</th><th class="px-4 py-2">Window</th><th class="px-4 py-2">New/Updated</th><th class="px-4 py-2">Duration</th><th class="px-4 py-2">Status</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="run in recentRuns" :key="run.id">
                                    <td class="px-4 py-2 font-mono text-gray-600 dark:text-gray-300">#{{ run.id }}</td>
                                    <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ run.user?.name ?? 'System' }}</td>
                                    <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ run.lookback_days }}d</td>
                                    <td class="px-4 py-2">
                                        <span class="text-emerald-600 dark:text-emerald-400">{{ run.ctis_created }}</span>
                                        /
                                        <span class="text-amber-600 dark:text-amber-400">{{ run.ctis_updated }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-gray-500">{{ duration(run) }}</td>
                                    <td class="px-4 py-2"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', statusClass(run.status)]">{{ run.status }}</span></td>
                                </tr>
                                <tr v-if="recentRuns.length === 0"><td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No runs yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
