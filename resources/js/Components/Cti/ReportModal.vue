<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: Boolean,
    templates: Array,
    ctiIds: Array,
    title: String,
});

const emit = defineEmits(['close']);

const page = usePage();
const templateId = ref(props.templates.find((t) => t.is_default)?.id ?? props.templates[0]?.id ?? '');

const previewHtml = computed(() => {
    const r = page.props.flash?.testResult;
    return r && r.type === 'report_preview' ? r.html : '';
});

function preview() {
    router.post(
        route('cti.report-preview'),
        { cti_ids: props.ctiIds, template_id: templateId.value || null },
        { preserveState: true }
    );
}

function downloadHtml() {
    const blob = new Blob([previewHtml.value || ''], { type: 'text/html' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'cti-report.html';
    a.click();
    URL.revokeObjectURL(a.href);
}

async function downloadPdf() {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
    const resp = await fetch(route('cti.report-pdf'), {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': xsrf, 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ cti_ids: props.ctiIds, template_id: templateId.value || null }),
    });
    const blob = await resp.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'cti-report.pdf';
    a.click();
    URL.revokeObjectURL(a.href);
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="max-h-[92vh] w-full max-w-4xl overflow-y-auto rounded-lg bg-white p-6 dark:bg-gray-800">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">Generate Report — {{ title }}</h3>
                <SecondaryButton type="button" @click="emit('close')">Close</SecondaryButton>
            </div>

            <div class="mb-4">
                <InputLabel value="Report template" />
                <select v-model="templateId" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}{{ t.is_default ? ' ★' : '' }}</option>
                </select>
            </div>

            <div class="mb-4 flex flex-wrap gap-2">
                <SecondaryButton type="button" @click="preview">Preview</SecondaryButton>
                <SecondaryButton type="button" :disabled="!previewHtml" @click="downloadHtml">Download HTML</SecondaryButton>
                <PrimaryButton type="button" @click="downloadPdf">⬇ Download PDF</PrimaryButton>
            </div>

            <iframe
                v-if="previewHtml"
                :srcdoc="previewHtml"
                class="h-[60vh] w-full rounded border border-gray-300 bg-white dark:border-gray-600"
                title="Report preview"
            />
            <div
                v-else
                class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400"
            >
                Click <strong>Preview</strong> to render the report with live CTI data, then download as standalone
                HTML or print-ready PDF.
            </div>
        </div>
    </div>
</template>
