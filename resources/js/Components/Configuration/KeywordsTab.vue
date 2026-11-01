<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ rules: Object, settings: Object });

const page = usePage();
const categories = ['Software', 'Hardware', 'Vendor', 'Cloud Service', 'Industry', 'Internal Asset'];

// ===== Master toggles (spec 5.4) =====
const focusEnabled = ref(!!props.settings?.focus_keywords_enabled);
const exceptionEnabled = ref(!!props.settings?.exception_keywords_enabled);
const matchMode = ref(props.settings?.keyword_match_mode ?? 'prioritize');

function saveSettings() {
    router.patch(route('config.keywords.settings'), {
        focus_keywords_enabled: focusEnabled.value,
        exception_keywords_enabled: exceptionEnabled.value,
        keyword_match_mode: matchMode.value,
    }, { preserveState: true });
}

// ===== Rules =====
const showForm = ref(false);
const editing = ref(null);

const form = useForm({
    keyword: '',
    match_type: 'exact',
    rule_type: 'focus',
    category: '',
    severity_boost: 0,
    is_active: true,
});

const testResult = computed(() => {
    const r = page.props.flash?.testResult;
    return null; // keywords tab has no test endpoint; placeholder for symmetry
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.keyword = '';
    form.match_type = 'exact';
    form.rule_type = 'focus';
    form.category = '';
    form.severity_boost = 0;
    form.is_active = true;
    showForm.value = true;
}

function openEdit(rule) {
    editing.value = rule;
    form.keyword = rule.keyword;
    form.match_type = rule.match_type;
    form.rule_type = rule.rule_type;
    form.category = rule.category ?? '';
    form.severity_boost = rule.severity_boost;
    form.is_active = rule.is_active;
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
        ? form.patch(route('config.keywords.update', editing.value.id), options)
        : form.post(route('config.keywords.store'), options);
}

function destroy(rule) {
    if (confirm(`Delete keyword rule "${rule.keyword}"?`)) {
        router.delete(route('config.keywords.destroy', rule.id));
    }
}
</script>

<template>
    <div>
        <div
            v-if="page.props.flash?.success"
            class="mb-4 rounded-lg border border-emerald-500/50 bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
        >
            {{ page.props.flash.success }}
        </div>

        <!-- Engine settings -->
        <div class="mb-6 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="mb-3 font-semibold text-gray-800 dark:text-gray-200">Engine Settings</h3>
            <div class="flex flex-wrap items-end gap-6">
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" v-model="focusEnabled" class="rounded" />
                    Focus keywords enabled
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" v-model="exceptionEnabled" class="rounded" />
                    Exception keywords enabled
                </label>
                <div class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
                    <span class="font-medium">Action on match:</span>
                    <label class="flex items-center gap-1">
                        <input type="radio" value="strict" v-model="matchMode" class="rounded-full" />
                        Strict filter (only matching articles)
                    </label>
                    <label class="flex items-center gap-1">
                        <input type="radio" value="prioritize" v-model="matchMode" class="rounded-full" />
                        Prioritize &amp; highlight
                    </label>
                </div>
                <PrimaryButton type="button" @click="saveSettings">Save Settings</PrimaryButton>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Exception keywords are evaluated <em>before</em> AI extraction to save token costs; focus matches are
                boosted/badged (prioritize mode) or gate ingestion (strict mode).
            </p>
        </div>

        <!-- Rules -->
        <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
            <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-700">
                <h3 class="font-semibold text-gray-800 dark:text-gray-200">Keyword Rules</h3>
                <PrimaryButton @click="openCreate">+ Add Rule</PrimaryButton>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3">Keyword</th>
                            <th class="px-4 py-3">Match</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Category</th>
                            <th class="px-4 py-3">Boost</th>
                            <th class="px-4 py-3">State</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr v-for="rule in rules.data ?? rules" :key="rule.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="px-4 py-3 font-mono text-gray-800 dark:text-gray-200">{{ rule.keyword }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ rule.match_type }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="rule.rule_type === 'focus' ? 'bg-indigo-500/20 text-indigo-600 dark:text-indigo-400' : 'bg-orange-500/20 text-orange-600 dark:text-orange-400'"
                                >
                                    {{ rule.rule_type }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ rule.category ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">+{{ rule.severity_boost }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="rule.is_active ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-gray-500/20 text-gray-500'"
                                >
                                    {{ rule.is_active ? 'Active' : 'Off' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex gap-2">
                                    <button type="button" @click="openEdit(rule)" class="text-gray-600 hover:underline dark:text-gray-300">Edit</button>
                                    <button type="button" @click="destroy(rule)" class="text-red-600 hover:underline dark:text-red-400">Delete</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="(rules.data ?? rules).length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                No keyword rules yet — add watchlist keywords your SOC cares about.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add/Edit modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-lg bg-white p-6 dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-gray-200">
                    {{ editing ? 'Edit Keyword Rule' : 'Add Keyword Rule' }}
                </h3>

                <form @submit.prevent="save" class="space-y-4">
                    <div>
                        <InputLabel value="Keyword / pattern" />
                        <TextInput v-model="form.keyword" type="text" class="mt-1 block w-full font-mono" required />
                        <InputError :message="form.errors.keyword" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel value="Match type" />
                            <select v-model="form.match_type" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option value="exact">Exact match</option>
                                <option value="wildcard">Wildcard</option>
                                <option value="regex">Regex</option>
                            </select>
                            <InputError :message="form.errors.match_type" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Rule type" />
                            <select v-model="form.rule_type" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option value="focus">Focus (watchlist)</option>
                                <option value="exception">Exception (noise filter)</option>
                            </select>
                            <InputError :message="form.errors.rule_type" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel value="Category (optional)" />
                            <select v-model="form.category" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option value="">— none —</option>
                                <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                            </select>
                            <InputError :message="form.errors.category" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Severity boost on match" />
                            <select v-model.number="form.severity_boost" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option :value="0">0 — none</option>
                                <option :value="1">+1</option>
                                <option :value="2">+2</option>
                                <option :value="3">+3</option>
                            </select>
                            <InputError :message="form.errors.severity_boost" class="mt-1" />
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" v-model="form.is_active" class="rounded" />
                        Rule active
                    </label>

                    <div class="flex justify-end gap-2">
                        <SecondaryButton type="button" @click="showForm = false">Cancel</SecondaryButton>
                        <PrimaryButton type="submit" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            {{ editing ? 'Save Changes' : 'Add Rule' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
