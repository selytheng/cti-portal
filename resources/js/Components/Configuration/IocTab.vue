<script setup>
import { computed, reactive } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ verifiers: Array });

const page = usePage();
const canManage = computed(() => page.props.can?.manageApiKeys ?? false);

const titles = { otx: 'AlienVault OTX', abusech: 'Abuse.ch' };
const subLabels = {
    otx: [['pulse_lookup', 'Pulse lookup'], ['passive_dns', 'Passive DNS']],
    abusech: [['urlhaus', 'URLhaus'], ['malwarebazaar', 'MalwareBazaar'], ['threatfox', 'ThreatFox'], ['feodo_tracker', 'Feodo Tracker']],
};

// Local editable state per verifier card.
const forms = reactive({});
props.verifiers.forEach((v) => {
    forms[v.id] = {
        api_key: '',
        base_url: v.base_url ?? '',
        sub_services: { ...(v.sub_services ?? {}) },
        cache_ttl_minutes: v.cache_ttl_minutes ?? 60,
        auto_enrich: !!v.auto_enrich,
        is_active: !!v.is_active,
    };
});

const testResult = computed(() => {
    const r = page.props.flash?.testResult;
    return r && r.type === 'ioc' ? r : null;
});

function save(v) {
    const f = forms[v.id];
    router.patch(route('config.ioc.update', v.id), {
        api_key: f.api_key || undefined,
        base_url: f.base_url || null,
        sub_services: f.sub_services,
        cache_ttl_minutes: Number(f.cache_ttl_minutes),
        auto_enrich: !!f.auto_enrich,
        is_active: !!f.is_active,
    }, { preserveState: true });
}

function test(v) {
    router.post(route('config.ioc.test', v.id), {}, { preserveState: true });
}
</script>

<template>
    <div>
        <div
            v-if="!canManage"
            class="mb-4 rounded-lg border border-amber-500/50 bg-amber-500/10 p-4 text-sm text-amber-600 dark:text-amber-400"
        >
            API key management is restricted to superadmin accounts.
        </div>

        <!-- Test result -->
        <div
            v-if="testResult"
            class="mb-4 rounded-lg border p-4 text-sm"
            :class="testResult.ok
                ? 'border-emerald-500/50 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                : 'border-red-500/50 bg-red-500/10 text-red-600 dark:text-red-400'"
        >
            <strong>Test result — {{ titles[testResult.service] }}:</strong> {{ testResult.detail ?? testResult.error }}
        </div>

        <div
            v-if="page.props.flash?.success"
            class="mb-4 rounded-lg border border-emerald-500/50 bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
        >
            {{ page.props.flash.success }}
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div
                v-for="v in verifiers"
                :key="v.id"
                class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800"
            >
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">{{ titles[v.service] ?? v.service }}</h3>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="forms[v.id]?.is_active ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-gray-500/20 text-gray-500'"
                    >
                        {{ forms[v.id]?.is_active ? 'Active' : 'Inactive' }}
                    </span>
                </div>

                <div class="space-y-3">
                    <div>
                        <InputLabel :value="forms[v.id]?.api_key === '' && v.has_api_key ? 'API key (stored — leave blank to keep)' : 'API key'" />
                        <TextInput
                            v-model="forms[v.id].api_key"
                            type="password"
                            class="mt-1 block w-full font-mono"
                            :disabled="!canManage"
                            :placeholder="v.has_api_key ? '••••••••' : 'Paste API key'"
                            autocomplete="new-password"
                        />
                    </div>

                    <div>
                        <InputLabel value="Base URL (optional override)" />
                        <TextInput v-model="forms[v.id].base_url" type="url" class="mt-1 block w-full" :disabled="!canManage" />
                    </div>

                    <div>
                        <InputLabel value="Sub-services" />
                        <div class="mt-1 flex flex-wrap gap-3 text-sm text-gray-700 dark:text-gray-300">
                            <label
                                v-for="[key, label] in subLabels[v.service] ?? []"
                                :key="key"
                                class="flex items-center gap-2"
                            >
                                <input type="checkbox" v-model="forms[v.id].sub_services[key]" class="rounded" :disabled="!canManage" />
                                {{ label }}
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel value="Cache TTL (minutes)" />
                            <TextInput v-model.number="forms[v.id].cache_ttl_minutes" type="number" min="1" max="10080" class="mt-1 block w-full" :disabled="!canManage" />
                        </div>
                        <div class="flex items-end pb-1">
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" v-model="forms[v.id].auto_enrich" class="rounded" :disabled="!canManage" />
                                Auto-enrich new IOCs
                            </label>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" v-model="forms[v.id].is_active" class="rounded" :disabled="!canManage" />
                        Verifier active
                    </div>

                    <div class="flex justify-end gap-2" v-if="canManage">
                        <SecondaryButton type="button" @click="test(v)">Test API</SecondaryButton>
                        <PrimaryButton type="button" @click="save(v)">Save</PrimaryButton>
                    </div>
                </div>
            </div>
        </div>

        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            Verifiers stay inactive until an API key is saved. In Phase 3, extracted IOCs are checked against these
            services (verdicts: Malicious / Suspicious / Clean / Unknown) per the auto-enrichment rule.
        </p>
    </div>
</template>
