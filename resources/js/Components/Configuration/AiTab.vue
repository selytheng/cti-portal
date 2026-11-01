<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ integrations: Array });

const page = usePage();
const canManage = computed(() => page.props.can?.manageApiKeys ?? false);

const providers = [
    { id: 'gemini', label: 'Google Gemini' },
    { id: 'claude', label: 'Anthropic Claude' },
    { id: 'openai', label: 'OpenAI' },
    { id: 'openrouter', label: 'OpenRouter' },
    { id: 'custom', label: 'Custom (OpenAI-compatible)' },
];

const showForm = ref(false);
const editing = ref(null);

const form = useForm({
    provider: 'gemini',
    name: '',
    api_key: '',
    base_url: '',
    model_id: '',
    system_prompt: '',
    merge_prompt: '',
    temperature: 0.2,
    max_tokens: 512,
    timeout: 30,
    is_default: false,
    is_fallback: false,
    is_active: true,
});

const testResult = computed(() => {
    const r = page.props.flash?.testResult;
    return r && r.type === 'ai' ? r : null;
});

function providerLabel(id) {
    return providers.find((p) => p.id === id)?.label ?? id;
}

function openCreate() {
    editing.value = null;
    form.reset();
    form.provider = 'gemini';
    form.is_active = true;
    showForm.value = true;
}

function openEdit(i) {
    editing.value = i;
    form.provider = i.provider;
    form.name = i.name;
    form.api_key = '';
    form.base_url = i.base_url ?? '';
    form.model_id = i.model_id ?? '';
    form.system_prompt = i.system_prompt ?? '';
    form.merge_prompt = i.merge_prompt ?? '';
    form.temperature = i.temperature ?? 0.2;
    form.max_tokens = i.max_tokens ?? 512;
    form.timeout = i.timeout ?? 30;
    form.is_default = i.is_default;
    form.is_fallback = i.is_fallback;
    form.is_active = i.is_active;
    showForm.value = true;
}

function save() {
    const options = {
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    };
    editing.value
        ? form.patch(route('config.ai.update', editing.value.id), options)
        : form.post(route('config.ai.store'), options);
}

function destroy(i) {
    if (confirm(`Delete AI integration "${i.name}"?`)) {
        router.delete(route('config.ai.destroy', i.id));
    }
}

function test(i) {
    router.post(route('config.ai.test', i.id), {}, { preserveState: true });
}

function needsBaseUrl() {
    return ['openrouter', 'custom'].includes(form.provider);
}
</script>

<template>
    <div>
        <div
            v-if="!canManage"
            class="mb-4 rounded-lg border border-amber-500/50 bg-amber-500/10 p-4 text-sm text-amber-600 dark:text-amber-400"
        >
            API key management is restricted to superadmin accounts. You can view configurations but not modify them.
        </div>

        <!-- Test result -->
        <div
            v-if="testResult"
            class="mb-4 rounded-lg border p-4 text-sm"
            :class="testResult.error
                ? 'border-red-500/50 bg-red-500/10 text-red-600 dark:text-red-400'
                : 'border-emerald-500/50 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'"
        >
            <div class="mb-2 font-semibold">Test result — {{ testResult.name }}</div>
            <div v-if="testResult.error" class="font-mono text-xs">{{ testResult.error }}</div>
            <template v-else>
                <div v-if="testResult.parsed" class="mb-2">
                    <strong>Extracted JSON:</strong>
                    <pre class="mt-1 max-h-40 overflow-auto rounded bg-gray-900 p-3 text-xs text-emerald-300">{{ JSON.stringify(testResult.parsed, null, 2) }}</pre>
                </div>
                <details v-if="testResult.raw">
                    <summary class="cursor-pointer text-xs">Raw model output</summary>
                    <pre class="mt-1 max-h-40 overflow-auto rounded bg-gray-900 p-3 text-xs text-gray-300">{{ testResult.raw }}</pre>
                </details>
            </template>
        </div>

        <div
            v-if="page.props.flash?.success"
            class="mb-4 rounded-lg border border-emerald-500/50 bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
        >
            {{ page.props.flash.success }}
        </div>

        <div class="mb-4 flex items-center justify-between">
            <h3 class="font-semibold text-gray-800 dark:text-gray-200">AI Agent Integrations</h3>
            <PrimaryButton v-if="canManage" @click="openCreate">+ Add Integration</PrimaryButton>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div
                v-for="i in integrations"
                :key="i.id"
                class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800"
            >
                <div class="mb-2 flex items-center justify-between">
                    <div>
                        <div class="font-semibold text-gray-800 dark:text-gray-200">{{ i.name }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ providerLabel(i.provider) }} · {{ i.model_id }}</div>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <span v-if="i.is_default" class="rounded-full bg-indigo-500/20 px-2 py-0.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400">Default</span>
                        <span v-if="i.is_fallback" class="rounded-full bg-amber-500/20 px-2 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400">Fallback</span>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="i.is_active ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-gray-500/20 text-gray-500'"
                        >
                            {{ i.is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                </div>

                <div class="mb-3 flex items-center gap-2 text-xs">
                    <span
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold"
                        :class="i.has_api_key ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400' : 'bg-red-500/15 text-red-600 dark:text-red-400'"
                    >
                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                        {{ i.has_api_key ? 'API key stored' : 'No API key' }}
                    </span>
                    <span v-if="i.base_url" class="text-gray-400">{{ i.base_url }}</span>
                </div>

                <div v-if="canManage" class="flex gap-2">
                    <button type="button" @click="test(i)" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">Test</button>
                    <button type="button" @click="openEdit(i)" class="text-sm text-gray-600 hover:underline dark:text-gray-300">Edit</button>
                    <button type="button" @click="destroy(i)" class="text-sm text-red-600 hover:underline dark:text-red-400">Delete</button>
                </div>
            </div>

            <div v-if="integrations.length === 0" class="rounded-lg border-2 border-dashed border-gray-300 p-8 text-center text-gray-500 dark:border-gray-600 dark:text-gray-400">
                No AI integrations configured. Add one to enable AI extraction in Phase 3.
            </div>
        </div>

        <!-- Add/Edit modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 p-4">
            <div class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-lg bg-white p-6 dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-gray-200">
                    {{ editing ? 'Edit AI Integration' : 'Add AI Integration' }}
                </h3>

                <form @submit.prevent="save" class="space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel value="Provider" />
                            <select v-model="form.provider" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.label }}</option>
                            </select>
                            <InputError :message="form.errors.provider" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Name" />
                            <TextInput v-model="form.name" type="text" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <InputLabel :value="editing ? 'API key (leave blank to keep current)' : 'API key'" />
                        <TextInput
                            v-model="form.api_key"
                            type="password"
                            class="mt-1 block w-full font-mono"
                            :required="!editing"
                            :placeholder="editing ? '••••••••' : 'Paste API key'"
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.api_key" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel :value="`Base URL${needsBaseUrl() ? ' (required for ' + form.provider + ')' : ''}`" />
                        <TextInput
                            v-model="form.base_url"
                            type="url"
                            class="mt-1 block w-full"
                            :placeholder="form.provider === 'gemini' ? 'https://generativelanguage.googleapis.com' : form.provider === 'claude' ? 'https://api.anthropic.com' : form.provider === 'openai' ? 'https://api.openai.com/v1' : 'https://openrouter.ai/api/v1'"
                        />
                        <InputError :message="form.errors.base_url" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel value="Model ID" />
                            <TextInput v-model="form.model_id" type="text" class="mt-1 block w-full" placeholder="e.g. gemini-2.5-pro" required />
                            <InputError :message="form.errors.model_id" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Temperature" />
                            <TextInput v-model="form.temperature" type="number" step="0.1" min="0" max="2" class="mt-1 block w-full" />
                            <InputError :message="form.errors.temperature" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel value="Max tokens" />
                            <TextInput v-model="form.max_tokens" type="number" min="16" class="mt-1 block w-full" />
                            <InputError :message="form.errors.max_tokens" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Timeout (seconds)" />
                            <TextInput v-model="form.timeout" type="number" min="5" max="300" class="mt-1 block w-full" />
                            <InputError :message="form.errors.timeout" class="mt-1" />
                        </div>
                    </div>

                    <details class="rounded-md border border-gray-200 p-3 dark:border-gray-700">
                        <summary class="cursor-pointer text-sm font-medium text-gray-700 dark:text-gray-300">Prompt templates</summary>
                        <div class="mt-3 space-y-3">
                            <div>
                                <InputLabel value="Initial CTI extraction prompt (optional override)" />
                                <textarea v-model="form.system_prompt" rows="4" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                            </div>
                            <div>
                                <InputLabel value="Cross-story merge prompt (optional override)" />
                                <textarea v-model="form.merge_prompt" rows="4" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                            </div>
                        </div>
                    </details>

                    <div class="flex flex-wrap gap-4 text-sm text-gray-700 dark:text-gray-300">
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.is_default" class="rounded" /> Default provider</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.is_fallback" class="rounded" /> Fallback provider</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.is_active" class="rounded" /> Active</label>
                    </div>

                    <div class="flex justify-end gap-2">
                        <SecondaryButton type="button" @click="showForm = false">Cancel</SecondaryButton>
                        <PrimaryButton type="submit" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            {{ editing ? 'Save Changes' : 'Add Integration' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
