<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import ReportModal from '@/Components/Cti/ReportModal.vue';

const props = defineProps({ record: Object, templates: Array });

const page = usePage();
const record = props.record;
const tab = ref('overview');
const showReport = ref(false);
const defanged = ref(true);
const expandedRevision = ref(null);
const copied = ref('');

const workflow = ref(record.workflow_status);

const tabs = [
    { id: 'overview', label: 'Overview & Narrative' },
    { id: 'cves', label: 'CVEs & Impacted Assets' },
    { id: 'iocs', label: 'IOCs & Verdict' },
    { id: 'adversary', label: 'Adversary & MITRE' },
    { id: 'timeline', label: 'Timeline & Notes' },
];

function displayValue(ioc) {
    return defanged.value ? (ioc.defanged_value ?? ioc.value) : ioc.value;
}

function copyIoc(ioc) {
    navigator.clipboard.writeText(displayValue(ioc));
    copied.value = ioc.id;
    setTimeout(() => (copied.value = ''), 1500);
}

function buildPayload(overrides = {}) {
    return {
        subject: record.subject,
        type: record.type,
        severity: record.severity,
        tlp: record.tlp,
        workflow_status: record.workflow_status,
        confidence_score: record.confidence_score ?? 50,
        summary: record.summary ?? '',
        technical_analysis: record.technical_analysis ?? '',
        mitigation: record.mitigation ?? '',
        exploit_status: record.exploit_status ?? null,
        cves: (record.cves ?? []).map((c) => c.cve),
        impact_hardware: record.impact_hardware ?? [],
        impact_software: record.impact_software ?? [],
        threat_actors: record.threat_actors ?? [],
        target_objects: record.target_objects ?? [],
        ...overrides,
    };
}

function quickStatus(s) {
    workflow.value = s;
    router.patch(route('cti.update', record.id), buildPayload({ workflow_status: s }), { preserveScroll: true });
}

function reverifyAll() {
    router.post(route('cti.reverify', record.id), {}, { preserveScroll: true });
}

function reverifyIoc(ioc) {
    router.post(route('cti.ioc-reverify', ioc.id), {}, { preserveScroll: true });
}

function destroyRecord() {
    if (confirm(`Delete ${record.cti_id}? This cannot be undone.`)) {
        router.delete(route('cti.destroy', record.id));
    }
}

// Analyst notes
const noteForm = useForm({ body: '', applicability: 'Investigating' });

function addNote() {
    noteForm.post(route('cti.notes.store', record.id), {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
}

// formatting
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

function verdictClass(verdict) {
    return {
        Malicious: 'bg-red-500/20 text-red-600 dark:text-red-400',
        Suspicious: 'bg-amber-500/20 text-amber-600 dark:text-amber-400',
        Clean: 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400',
        Unknown: 'bg-gray-500/20 text-gray-500',
    }[verdict] ?? 'bg-gray-500/20 text-gray-500';
}

const revisedAgo = computed(() => {
    if (!record.modified_at) return '';
    const mins = Math.round((Date.now() - new Date(record.modified_at)) / 60000);
    return mins < 60 ? `${mins}m ago` : `${Math.round(mins / 60)}h ago`;
});
</script>

<template>
    <Head :title="record.cti_id" />

    <AuthenticatedLayout>
        <!-- Sticky action header (spec Section 7) -->
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-sm text-gray-500 dark:text-gray-400">{{ record.cti_id }}</span>
                        <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', sevClass(record.severity)]">{{ record.severity }}</span>
                        <span class="rounded-full bg-indigo-500/15 px-2 py-0.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400">{{ record.tlp }}</span>
                        <span v-if="record.is_developing" class="rounded-full bg-blue-500/20 px-2 py-0.5 text-xs font-semibold text-blue-600 dark:text-blue-400">Developing</span>
                    </div>
                    <h2 class="mt-1 text-xl font-semibold text-gray-800 dark:text-gray-200">{{ record.subject }}</h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Published {{ fmt(record.published_at) }} ·
                        Last modified {{ fmt(record.modified_at) }} ·
                        <strong>Rev {{ record.revision_count }} · updated {{ revisedAgo }}</strong>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <select
                        :value="record.workflow_status"
                        @change="quickStatus($event.target.value)"
                        class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    >
                        <option v-for="s in ['New','Developing','Under Review','Verified','Published','False Positive','Archived']" :key="s" :value="s">{{ s }}</option>
                    </select>
                    <SecondaryButton type="button" @click="router.get(route('cti.edit', record.id))">Edit CTI</SecondaryButton>
                    <SecondaryButton type="button" @click="reverifyAll">Re-Verify IOCs</SecondaryButton>
                    <SecondaryButton type="button" @click="showReport = true">Generate Report</SecondaryButton>
                    <SecondaryButton type="button" @click="destroyRecord" class="!text-red-600 dark:!text-red-400">Delete</SecondaryButton>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.success"
                    class="mb-4 rounded-lg border border-emerald-500/50 bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
                >
                    {{ page.props.flash.success }}
                </div>

                <!-- Tabs -->
                <div class="mb-6 flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-700">
                    <button
                        v-for="t in tabs"
                        :key="t.id"
                        type="button"
                        @click="tab = t.id"
                        class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors"
                        :class="tab === t.id
                            ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                            : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                    >
                        {{ t.label }}
                    </button>
                </div>

                <!-- Overview -->
                <div v-if="tab === 'overview'" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Executive Summary</h3>
                        <p class="whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ record.summary || '—' }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Technical Analysis</h3>
                        <p class="whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ record.technical_analysis || '—' }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Mitigation</h3>
                        <p class="whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ record.mitigation || '—' }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Matched Focus Keywords</h3>
                        <div class="flex flex-wrap gap-1">
                            <span v-for="kw in record.matched_focus_keywords ?? []" :key="kw" class="rounded-full bg-indigo-500/15 px-2 py-0.5 text-xs text-indigo-600 dark:text-indigo-400">{{ kw }}</span>
                            <span v-if="(record.matched_focus_keywords ?? []).length === 0" class="text-sm text-gray-500 dark:text-gray-400">—</span>
                        </div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Contributing Sources</h3>
                        <ul class="space-y-1 text-sm">
                            <li v-for="(s, i) in record.sources ?? []" :key="i" class="text-gray-700 dark:text-gray-300">
                                <strong>{{ s.name }}</strong> —
                                <a :href="s.url" target="_blank" rel="noopener" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ s.url }}</a>
                                <span class="ms-1 text-xs text-gray-400">{{ fmt(s.published_at) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- CVEs & Assets -->
                <div v-else-if="tab === 'cves'" class="space-y-6">
                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="border-b border-gray-200 p-4 dark:border-gray-700"><h3 class="font-semibold text-gray-800 dark:text-gray-200">CVEs</h3></div>
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-900 dark:text-gray-400"><tr><th class="px-4 py-2">CVE</th><th class="px-4 py-2">CVSS</th><th class="px-4 py-2">KEV</th></tr></thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="cve in record.cves ?? []" :key="cve.cve">
                                    <td class="px-4 py-2 font-mono"><a :href="'https://nvd.nist.gov/vuln/detail/' + cve.cve" target="_blank" rel="noopener" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ cve.cve }}</a></td>
                                    <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ cve.cvss ?? '—' }}</td>
                                    <td class="px-4 py-2"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', cve.kev === 'Yes' ? 'bg-red-500/20 text-red-600 dark:text-red-400' : 'bg-gray-500/20 text-gray-500']">{{ cve.kev ?? '—' }}</span></td>
                                </tr>
                                <tr v-if="(record.cves ?? []).length === 0"><td colspan="3" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No CVEs recorded.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                            <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Impacted Hardware</h3>
                            <div class="flex flex-wrap gap-1"><span v-for="h in record.impact_hardware ?? []" :key="h" class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ h }}</span><span v-if="(record.impact_hardware ?? []).length === 0" class="text-sm text-gray-500">—</span></div>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                            <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Impacted Software</h3>
                            <div class="flex flex-wrap gap-1"><span v-for="s in record.impact_software ?? []" :key="s" class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ s }}</span><span v-if="(record.impact_software ?? []).length === 0" class="text-sm text-gray-500">—</span></div>
                        </div>
                    </div>
                </div>

                <!-- IOCs -->
                <div v-else-if="tab === 'iocs'">
                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-700">
                            <h3 class="font-semibold text-gray-800 dark:text-gray-200">Indicators of Compromise</h3>
                            <label class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <input type="checkbox" v-model="defanged" class="rounded" /> Defanged
                            </label>
                        </div>
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                                <tr><th class="px-4 py-2">Type</th><th class="px-4 py-2">Value</th><th class="px-4 py-2">Verdict</th><th class="px-4 py-2">Added</th><th class="px-4 py-2">Last verified</th><th class="px-4 py-2">Actions</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="ioc in record.iocs ?? []" :key="ioc.id">
                                    <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ ioc.ioc_type }}</td>
                                    <td class="px-4 py-2">
                                        <div class="flex items-center gap-2">
                                            <code class="font-mono text-xs text-gray-800 dark:text-gray-200">{{ displayValue(ioc) }}</code>
                                            <button type="button" @click="copyIoc(ioc)" class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">{{ copied === ioc.id ? 'Copied!' : 'Copy' }}</button>
                                        </div>
                                        <div v-if="ioc.otx_data?.pulse_count !== undefined" class="text-xs text-gray-400">OTX pulses: {{ ioc.otx_data.pulse_count }}</div>
                                        <div v-if="ioc.abusech_data" class="text-xs text-gray-400">Abuse.ch: {{ JSON.stringify(ioc.abusech_data) }}</div>
                                    </td>
                                    <td class="px-4 py-2"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', verdictClass(ioc.verdict)]">{{ ioc.verdict }}</span></td>
                                    <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">Rev {{ ioc.added_in_revision }}</td>
                                    <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">{{ fmt(ioc.last_verified_at) }}</td>
                                    <td class="px-4 py-2"><button type="button" @click="reverifyIoc(ioc)" class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">Re-check</button></td>
                                </tr>
                                <tr v-if="(record.iocs ?? []).length === 0"><td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No IOCs recorded.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Adversary -->
                <div v-else-if="tab === 'adversary'" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Threat Actors</h3>
                        <div class="flex flex-wrap gap-1"><span v-for="a in record.threat_actors ?? []" :key="a" class="rounded-full bg-red-500/15 px-2 py-0.5 text-xs font-semibold text-red-600 dark:text-red-400">{{ a }}</span><span v-if="(record.threat_actors ?? []).length === 0" class="text-sm text-gray-500">—</span></div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">Targeted Sectors &amp; Geographies</h3>
                        <div class="flex flex-wrap gap-1"><span v-for="t in record.target_objects ?? []" :key="t" class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ t }}</span><span v-if="(record.target_objects ?? []).length === 0" class="text-sm text-gray-500">—</span></div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 font-semibold text-gray-800 dark:text-gray-200">MITRE ATT&amp;CK</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">ATT&amp;CK technique mapping arrives with the enrichment pipeline upgrade (AI extraction output).</p>
                    </div>
                </div>

                <!-- Timeline & Notes -->
                <div v-else-if="tab === 'timeline'" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-3 font-semibold text-gray-800 dark:text-gray-200">Enrichment &amp; Revision Timeline</h3>
                        <ol class="space-y-3">
                            <li v-for="rev in record.revisions ?? []" :key="rev.id" class="border-s-2 border-indigo-500/30 ps-4">
                                <div class="text-sm text-gray-700 dark:text-gray-300">
                                    <strong>{{ fmt(rev.created_at) }}</strong>
                                    — {{ rev.updatedBy?.name ?? 'AI pipeline' }}
                                    <span v-if="rev.query_run_id" class="text-xs text-gray-400">(query run #{{ rev.query_run_id }})</span>
                                </div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span v-for="f in rev.fields_updated ?? []" :key="f" class="rounded-full bg-indigo-500/15 px-2 py-0.5 text-xs text-indigo-600 dark:text-indigo-400">{{ f }}</span>
                                </div>
                                <button type="button" class="mt-1 text-xs text-indigo-600 hover:underline dark:text-indigo-400" @click="expandedRevision = expandedRevision === rev.id ? null : rev.id">
                                    {{ expandedRevision === rev.id ? 'Hide diff' : 'View diff' }}
                                </button>
                                <pre v-if="expandedRevision === rev.id" class="mt-1 max-h-48 overflow-auto rounded bg-gray-900 p-3 text-xs text-emerald-300">{{ JSON.stringify({ before: rev.previous_values, after: rev.new_values }, null, 2) }}</pre>
                            </li>
                            <li v-if="(record.revisions ?? []).length === 0" class="text-sm text-gray-500 dark:text-gray-400">No revisions recorded.</li>
                        </ol>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-3 font-semibold text-gray-800 dark:text-gray-200">Analyst Notes</h3>
                        <ul class="mb-4 space-y-2">
                            <li v-for="note in record.notes ?? []" :key="note.id" class="rounded-md border border-gray-200 p-3 text-sm dark:border-gray-700">
                                <div class="mb-1 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                    <span>{{ note.user?.name }}</span>
                                    <span>{{ fmt(note.created_at) }} · <strong class="text-indigo-600 dark:text-indigo-400">{{ note.applicability }}</strong></span>
                                </div>
                                <p class="whitespace-pre-line text-gray-700 dark:text-gray-300">{{ note.body }}</p>
                            </li>
                            <li v-if="(record.notes ?? []).length === 0" class="text-sm text-gray-500 dark:text-gray-400">No analyst notes yet.</li>
                        </ul>
                        <form @submit.prevent="addNote" class="space-y-2">
                            <textarea v-model="noteForm.body" rows="3" class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" placeholder="Triage note…" required />
                            <div class="flex items-center justify-between">
                                <select v-model="noteForm.applicability" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    <option v-for="a in ['Affected','Not Affected','Mitigated','Investigating']" :key="a" :value="a">{{ a }}</option>
                                </select>
                                <PrimaryButton type="submit" :class="{ 'opacity-25': noteForm.processing }" :disabled="noteForm.processing">Add Note</PrimaryButton>
                            </div>
                            <InputError :message="noteForm.errors.body" class="text-xs" />
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <ReportModal :show="showReport" :templates="templates" :cti-ids="[record.id]" :title="record.cti_id" @close="showReport = false" />
    </AuthenticatedLayout>
</template>
