<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const page = usePage();

const currentLogo = computed(() => page.props.appLogoUrl ?? null);

function onLogoChange(e) {
    const file = e.target.files?.[0];
    if (file) {
        router.post(route('config.branding.logo'), { logo: file }, { preserveState: true });
    }
}

function resetLogo() {
    if (!confirm('Reset to the default CTI Portal mark?')) return;
    router.post(route('config.branding.reset'), {}, { preserveState: true });
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

        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="mb-1 font-semibold text-gray-800 dark:text-gray-200">Application Branding</h3>
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                The logo shown at the top of the sidebar (and anywhere the app mark appears).
                PNG, JPG, SVG or WebP up to 2 MB — square with a transparent background looks best.
            </p>

            <div class="flex items-center gap-4">
                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-600">
                    <img v-if="currentLogo" :src="currentLogo" alt="Current logo" class="max-h-full max-w-full object-contain" />
                    <span v-else class="text-xs text-gray-400">Default</span>
                </div>
                <div class="space-y-2">
                    <InputLabel value="Upload new logo" />
                    <input
                        type="file"
                        accept="image/png,image/jpeg,image/svg+xml,image/webp"
                        @change="onLogoChange"
                        class="block w-full max-w-xs text-sm text-gray-500 file:me-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 dark:file:bg-indigo-500/20 dark:file:text-indigo-300"
                    />
                    <SecondaryButton v-if="currentLogo" type="button" @click="resetLogo">Reset to default</SecondaryButton>
                </div>
            </div>

            <p class="mt-4 text-xs text-gray-400">
                The default Laravel mark is used when no custom logo is set. This is separate from report
                template logos (Report Studio tab).
            </p>
        </div>
    </div>
</template>
