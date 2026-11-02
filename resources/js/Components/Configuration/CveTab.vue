<script setup>
import { computed, reactive } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ verifier: Object });

const page = usePage();
const canManage = computed(() => page.props.can?.manageApiKeys ?? false);

// Template-safe accessor for the verifier row (falls back to defaults if
// the row is ever missing) — keeps the template bindings stable.
const v = computed(() => props.verifier ?? {
    id: null, service: 'nvd', base_url: '', request_interval_seconds: 2,
    auto_verify: true, is_active: false, has_api_key: false,
});

const form = reactive({
    api_key: '',
    base_url: props.verifier?.base_url ?? '',
    request_interval_seconds: props.verifier?.request_interval_seconds ?? 2,
    auto_verify: !!(props.verifier?.auto_verify ?? true),
    is_active: !!(props.verifier?.is_active ?? false),
});

const testResult = computed(() => {
    const r = page.props.flash?.testResult;
    return r && r.type === 'cve' ? r : null;
});

function save() {
    router.patch(route('config.cve.update', props.verifier.id), {
        api_key: form.api_key || undefined,
        base_url: form.base_url || null,
        request_interval_seconds: Number(form.request_interval_seconds),
        auto_verify: !!form.auto_verify,
        is_active: !!form.is_active,
    }, { preserveState: true });
}

function test() {
    router.post(route('config.cve.test', props.verifier.id), {}, { preserveState: true });
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
            <strong>Test result — {{ testResult.service.toUpperCase() }}:</strong> {{ testResult.detail }}
        </div>

        <div
            v-if="page.props.flash?.success"
            class="mb-4 rounded-lg border border-emerald-500/50 bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
        >
            {{ page.props.flash.success }}
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="mb-3 flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">NIST NVD</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">CVE trust-source verification — official CVSS, CWE, exploitability data</p>
                </div>
                <span
                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                    :class="form.is_active ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-gray-500/20 text-gray-500'"
                >
                    {{ form.is_active ? 'Active' : 'Inactive' }}
                </span>
            </div>

            <div class="space-y-3">
                <div>
                    <InputLabel :value="v.has_api_key ? 'API key (stored — leave blank to keep)' : 'API key'" />
                    <TextInput
                        v-model="form.api_key"
                        type="password"
                        class="mt-1 block w-full font-mono"
                        :disabled="!canManage"
                        :placeholder="v.has_api_key ? '••••••••' : 'Paste NVD API key'"
                        autocomplete="new-password"
                    />
                    <p class="mt-1 text-xs text-gray-400">
                        Free key:
                        <a href="https://nvd.nist.gov/developers/request-an-api-key" target="_blank" rel="noopener" class="text-indigo-600 hover:underline dark:text-indigo-400">nvd.nist.gov/developers/request-an-api-key</a>
                        — raises the rate limit from 5 to 50 requests/30s.
                    </p>
                </div>

                <div>
                    <InputLabel value="Base URL (optional override)" />
                    <TextInput v-model="form.base_url" type="url" class="mt-1 block w-full" :disabled="!canManage" placeholder="https://services.nvd.nist.gov" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel value="Request interval (seconds)" />
                        <TextInput v-model="form.request_interval_seconds" type="number" min="1" max="60" class="mt-1 block w-full" :disabled="!canManage" />
                        <p class="mt-1 text-xs text-gray-400">Without a key: min 6s enforced automatically.</p>
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" v-model="form.auto_verify" class="rounded" :disabled="!canManage" />
                            Auto-verify new CVEs
                        </label>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" v-model="form.is_active" class="rounded" :disabled="!canManage" />
                    Verifier active
                </label>

                <div class="flex justify-end gap-2" v-if="canManage">
                    <SecondaryButton type="button" @click="test">Test API</SecondaryButton>
                    <PrimaryButton type="button" @click="save">Save</PrimaryButton>
                </div>
            </div>

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                Verification enriches every CVE in the repository with the official CVSS score/vector, CWE weakness
                IDs, the CISA KEV "actively exploited" flag and the FIRST EPSS exploitability score.
            </p>
        </div>
    </div>
</template>
