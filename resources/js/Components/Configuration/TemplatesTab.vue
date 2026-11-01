<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ templates: Object, variables: Array });

const page = usePage();
const list = computed(() => props.templates.data ?? props.templates);

const selectedId = ref(null);
const textareaRef = ref(null);
const logoFile = ref(null);

const form = useForm({
    name: '',
    watermark_text: '',
    is_default: false,
    html_content: '',
    css_styles: '',
    logo_path: '',
});

// Live preview comes back via the shared flash payload.
const previewHtml = computed(() => {
    const r = page.props.flash?.testResult;
    return r && r.type === 'template_preview' && r.template_id === selectedId.value ? r.html : '';
});

// Uploaded logo lands in flash; apply it to the form.
watch(
    () => page.props.flash?.testResult,
    (r) => {
        if (r && r.type === 'logo') {
            form.logo_path = r.path;
        }
    }
);

const logoPreviewUrl = computed(() => {
    const r = page.props.flash?.testResult;
    if (r && r.type === 'logo') return r.url;
    return form.logo_path ? '/storage/' + form.logo_path : '';
});

function select(t) {
    selectedId.value = t.id;
    form.name = t.name;
    form.watermark_text = t.watermark_text ?? '';
    form.is_default = t.is_default;
    form.html_content = t.html_content;
    form.css_styles = t.css_styles ?? '';
    form.logo_path = t.logo_path ?? '';
    form.clearErrors();
}

function newTemplate() {
    selectedId.value = null;
    form.reset();
}

function save() {
    const options = { preserveState: true };
    selectedId.value
        ? form.patch(route('config.templates.update', selectedId.value), options)
        : form.post(route('config.templates.store'), options);
}

function destroy(t) {
    if (t.is_default) {
        alert('Set another template as default before deleting this one.');
        return;
    }
    if (confirm(`Delete template "${t.name}"?`)) {
        router.delete(route('config.templates.destroy', t.id));
        if (selectedId.value === t.id) {
            selectedId.value = null;
        }
    }
}

function setDefault(t) {
    router.post(route('config.templates.default', t.id), {}, { preserveState: true });
}

function doPreview() {
    if (!selectedId.value) {
        alert('Save the template first, then preview it.');
        return;
    }
    router.post(
        route('config.templates.preview', selectedId.value),
        { html_content: form.html_content, css_styles: form.css_styles },
        { preserveState: true }
    );
}

// Must live in script: literal braces inside template text break the
// Vue template compiler's interpolation matching.
function varLabel(v) {
    return '{{' + v + '}}';
}

function insertVar(v) {
    const snippet = `{{${v}}}`;
    const el = textareaRef.value;
    if (!el) {
        form.html_content += snippet;
        return;
    }
    const start = el.selectionStart ?? form.html_content.length;
    const end = el.selectionEnd ?? start;
    form.html_content = form.html_content.slice(0, start) + snippet + form.html_content.slice(end);
}

function onLogoChange(e) {
    const file = e.target.files?.[0];
    if (file) {
        router.post(route('config.templates.logo'), { logo: file }, { preserveState: true });
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

        <div class="grid gap-4 lg:grid-cols-[280px_1fr]">
            <!-- Template list -->
            <div class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-700">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Templates</h3>
                    <PrimaryButton @click="newTemplate">+ New</PrimaryButton>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    <button
                        v-for="t in list"
                        :key="t.id"
                        type="button"
                        @click="select(t)"
                        class="flex w-full items-center justify-between p-3 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700/50"
                        :class="selectedId === t.id ? 'bg-indigo-500/10' : ''"
                    >
                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ t.name }}</span>
                        <span v-if="t.is_default" class="rounded-full bg-indigo-500/20 px-2 py-0.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400">
                            Default
                        </span>
                    </button>
                    <div v-if="list.length === 0" class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">
                        No templates yet.
                    </div>
                </div>
            </div>

            <!-- Editor -->
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <form @submit.prevent="save" class="space-y-4">
                    <div class="grid grid-cols-[1fr_auto] items-end gap-3">
                        <div>
                            <InputLabel value="Template name" />
                            <TextInput v-model="form.name" type="text" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <label class="flex h-9 items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" v-model="form.is_default" class="rounded" />
                            Default template
                        </label>
                    </div>

                    <!-- Branding row -->
                    <div class="grid grid-cols-[1fr_auto_auto] items-end gap-3">
                        <div>
                            <InputLabel value="Classification watermark" />
                            <TextInput v-model="form.watermark_text" type="text" class="mt-1 block w-full" placeholder="e.g. INTERNAL USE ONLY" />
                        </div>
                        <div>
                            <InputLabel value="Logo upload" />
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/svg+xml,image/webp"
                                @change="onLogoChange"
                                class="mt-1 block w-full text-sm text-gray-500 file:me-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 dark:file:bg-indigo-500/20 dark:file:text-indigo-300"
                            />
                        </div>
                        <img v-if="logoPreviewUrl" :src="logoPreviewUrl" alt="Logo preview" class="h-10 rounded border border-gray-200 bg-white p-1 dark:border-gray-600" />
                    </div>

                    <!-- Variable chips -->
                    <div>
                        <InputLabel value="Variables — click to insert" />
                        <div class="mt-1 flex flex-wrap gap-1">
                            <button
                                v-for="v in variables"
                                :key="v"
                                type="button"
                                @click="insertVar(v)"
                                class="rounded-full bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700 hover:bg-indigo-100 hover:text-indigo-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-indigo-500/20 dark:hover:text-indigo-300"
                            >
                                {{ varLabel(v) }}
                            </button>
                        </div>
                    </div>

                    <!-- Split-screen editor + preview -->
                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <InputLabel value="HTML content" />
                            <SecondaryButton type="button" @click="doPreview" :disabled="!selectedId || form.processing">Preview ▶</SecondaryButton>
                        </div>
                        <div class="grid gap-2 lg:grid-cols-2">
                            <textarea
                                ref="textareaRef"
                                v-model="form.html_content"
                                rows="18"
                                spellcheck="false"
                                class="block w-full rounded-md border-gray-300 bg-gray-900 p-3 font-mono text-xs text-emerald-300 dark:border-gray-700"
                            />
                            <iframe
                                :srcdoc="previewHtml"
                                class="block h-[415px] w-full rounded-md border border-gray-300 bg-white dark:border-gray-600"
                                title="Template preview"
                            />
                        </div>
                        <InputError :message="form.errors.html_content" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel value="CSS override (replaces the &lt;style&gt; block)" />
                        <textarea
                            v-model="form.css_styles"
                            rows="4"
                            spellcheck="false"
                            class="mt-1 block w-full rounded-md border-gray-300 bg-gray-900 p-3 font-mono text-xs text-emerald-300 dark:border-gray-700"
                        />
                        <InputError :message="form.errors.css_styles" class="mt-1" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <SecondaryButton
                            v-if="selectedId"
                            type="button"
                            @click="destroy(list.find((t) => t.id === selectedId))"
                            class="!text-red-600 dark:!text-red-400"
                        >
                            Delete
                        </SecondaryButton>
                        <PrimaryButton type="submit" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            {{ selectedId ? 'Save Changes' : 'Create Template' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
