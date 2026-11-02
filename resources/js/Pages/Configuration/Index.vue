<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AiTab from '@/Components/Configuration/AiTab.vue';
import BrandingTab from '@/Components/Configuration/BrandingTab.vue';
import CveTab from '@/Components/Configuration/CveTab.vue';
import FeedsTab from '@/Components/Configuration/FeedsTab.vue';
import IocTab from '@/Components/Configuration/IocTab.vue';
import KeywordsTab from '@/Components/Configuration/KeywordsTab.vue';
import TemplatesTab from '@/Components/Configuration/TemplatesTab.vue';

const props = defineProps({
    feeds: Object,
    aiIntegrations: Array,
    verifiers: Array,
    cveVerifier: Object,
    keywordRules: Object,
    settings: Object,
    templates: Object,
    variables: Array,
});

const tab = ref('feeds');

const tabs = [
    { id: 'feeds', label: 'RSS Feeds' },
    { id: 'ai', label: 'AI Agents' },
    { id: 'ioc', label: 'IOC Verifiers' },
    { id: 'cve', label: 'CVE Verification' },
    { id: 'keywords', label: 'Keywords' },
    { id: 'templates', label: 'Report Studio' },
    { id: 'branding', label: 'Branding' },
];
</script>

<template>
    <Head title="Configuration Center" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Configuration Center</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
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

                <FeedsTab v-if="tab === 'feeds'" :feeds="feeds" />
                <AiTab v-else-if="tab === 'ai'" :integrations="aiIntegrations" />
                <IocTab v-else-if="tab === 'ioc'" :verifiers="verifiers" />
                <CveTab v-else-if="tab === 'cve'" :verifier="cveVerifier" />
                <KeywordsTab v-else-if="tab === 'keywords'" :rules="keywordRules" :settings="settings" />
                <TemplatesTab v-else-if="tab === 'templates'" :templates="templates" :variables="variables" />
                <BrandingTab v-else-if="tab === 'branding'" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
