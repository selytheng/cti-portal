<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ record: Object });

const lines = (arr) => (arr ?? []).join('\n');
const toLines = (v) => String(v ?? '').split('\n').map((s) => s.trim()).filter(Boolean);
const mitreLines = (arr) => (arr ?? []).map((m) => typeof m === 'string' ? m : `${m.id || ''} | ${m.name || ''} | ${m.tactic || ''}`.replace(/\|\s*\|\s*$/, '').trim()).join('\n');
const toMitre = (v) => String(v ?? '').split('\n').map((s) => s.trim()).filter(Boolean).map((line) => {
    const parts = line.split('|').map((p) => p.trim());
    return {
        id: parts[0] ?? '',
        name: parts[1] ?? '',
        tactic: parts[2] ?? '',
    };
});

const form = useForm({
    subject: props.record.subject,
    type: props.record.type,
    severity: props.record.severity,
    tlp: props.record.tlp,
    workflow_status: props.record.workflow_status,
    confidence_score: props.record.confidence_score ?? 50,
    exploit_status: props.record.exploit_status ?? '',
    summary: props.record.summary ?? '',
    technical_analysis: props.record.technical_analysis ?? '',
    mitigation: props.record.mitigation ?? '',
    cves: lines((props.record.cves ?? []).map((c) => c.cve)),
    impact_hardware: lines(props.record.impact_hardware),
    impact_software: lines(props.record.impact_software),
    threat_actors: lines(props.record.threat_actors),
    target_objects: lines(props.record.target_objects),
    mitre_attack: mitreLines(props.record.mitre_attack),
});

// New IOC rows pending add (spec 7 editor).
const pendingIocs = ref([]);
const newIoc = ref({ type: 'IPv4', value: '' });

function addIoc() {
    if (!newIoc.value.value.trim()) return;
    pendingIocs.value.push({ type: newIoc.value.type, value: newIoc.value.value.trim() });
    newIoc.value.value = '';
}

function removePending(i) {
    pendingIocs.value.splice(i, 1);
}

function save() {
    form.transform((data) => ({
        ...data,
        cves: toLines(data.cves),
        impact_hardware: toLines(data.impact_hardware),
        impact_software: toLines(data.impact_software),
        threat_actors: toLines(data.threat_actors),
        target_objects: toLines(data.target_objects),
        mitre_attack: toMitre(data.mitre_attack),
        exploit_status: data.exploit_status || null,
        iocs_add: pendingIocs.value,
    })).patch(route('cti.update', props.record.id));
}

function cancel() {
    router.get(route('cti.show', props.record.id));
}
</script>

<template>
    <Head :title="'Edit ' + record.cti_id" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Edit CTI — {{ record.cti_id }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <form @submit.prevent="save" class="space-y-5 rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div>
                        <InputLabel value="Subject" />
                        <TextInput v-model="form.subject" type="text" class="mt-1 block w-full" required />
                        <InputError :message="form.errors.subject" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div>
                            <InputLabel value="Type" />
                            <select v-model="form.type" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option value="Vul">Vul</option><option value="Mal">Mal</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Severity" />
                            <select v-model="form.severity" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option v-for="s in ['Critical','High','Medium','Low','Informational']" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="TLP" />
                            <select v-model="form.tlp" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option v-for="t in ['TLP:RED','TLP:AMBER+STRICT','TLP:AMBER','TLP:GREEN','TLP:CLEAR']" :key="t" :value="t">{{ t }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Workflow status" />
                            <select v-model="form.workflow_status" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option v-for="s in ['New','Developing','Under Review','Verified','Published','False Positive','Archived','Not Applicable']" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel value="Exploit status" />
                            <select v-model="form.exploit_status" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option value="">—</option>
                                <option v-for="s in ['Unproven','PoC Available','Actively Exploited in the Wild','Weaponized']" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Confidence (0–100)" />
                            <TextInput v-model.number="form.confidence_score" type="number" min="0" max="100" class="mt-1 block w-full" />
                        </div>
                    </div>

                    <div>
                        <InputLabel value="Executive summary" />
                        <textarea v-model="form.summary" rows="4" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                    </div>
                    <div>
                        <InputLabel value="Technical analysis" />
                        <textarea v-model="form.technical_analysis" rows="6" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                    </div>
                    <div>
                        <InputLabel value="Mitigation" />
                        <textarea v-model="form.mitigation" rows="4" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="CVEs (one per line)" />
                            <textarea v-model="form.cves" rows="3" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                            <InputError :message="form.errors.cves" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Add new IOCs" />
                            <div class="mt-1 flex gap-2">
                                <select v-model="newIoc.type" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    <option v-for="t in ['IPv4','IPv6','Domain','URL','MD5','SHA1','SHA256','Email','Mutex','User-Agent','Registry Key']" :key="t" :value="t">{{ t }}</option>
                                </select>
                                <TextInput v-model="newIoc.value" type="text" class="grow font-mono text-xs" placeholder="ioc value" @keyup.enter.prevent="addIoc" />
                                <SecondaryButton type="button" @click="addIoc">Add</SecondaryButton>
                            </div>
                            <ul class="mt-2 space-y-1">
                                <li v-for="(p, i) in pendingIocs" :key="i" class="flex items-center justify-between rounded bg-gray-100 px-2 py-1 text-xs dark:bg-gray-900">
                                    <span class="font-mono">{{ p.type }}: {{ p.value }}</span>
                                    <button type="button" @click="removePending(i)" class="text-red-500 hover:underline">remove</button>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Impacted hardware (one per line)" />
                            <textarea v-model="form.impact_hardware" rows="3" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                        </div>
                        <div>
                            <InputLabel value="Impacted software (one per line)" />
                            <textarea v-model="form.impact_software" rows="3" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                        </div>
                        <div>
                            <InputLabel value="Threat actors (one per line)" />
                            <textarea v-model="form.threat_actors" rows="3" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                        </div>
                        <div>
                            <InputLabel value="Targets (one per line)" />
                            <textarea v-model="form.target_objects" rows="3" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                        </div>
                    </div>

                    <div>
                        <InputLabel value="MITRE ATT&CK (one per line, format: ID | Technique Name | Tactic, e.g. T1190 | Exploit Public-Facing Application | Initial Access)" />
                        <textarea v-model="form.mitre_attack" rows="3" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <SecondaryButton type="button" @click="cancel">Cancel</SecondaryButton>
                        <PrimaryButton type="submit" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">Save Changes</PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
