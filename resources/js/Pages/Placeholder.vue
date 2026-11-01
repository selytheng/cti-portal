<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    title: String,
    phase: String,
});
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">{{ title }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg border-2 border-dashed border-gray-300 bg-white/50 p-12 text-center dark:border-gray-600 dark:bg-gray-800/50">
                    <svg class="mx-auto mb-4 h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 12c0-1.232-.046-2.453-.128-3.67a48.5 48.5 0 01-.664-3.852 1.125 1.125 0 00-.973-1.004 41.742 41.742 0 00-6.286-.773c-.93 0-1.797.066-2.64.193M7.5 19.5c1.14 0 2.255-.057 3.355-.17M7.5 19.5c-1.407 0-2.79-.096-4.147-.277a1.125 1.125 0 01-.96-1.004 41.64 41.64 0 01-.42-3.863M7.5 19.5V6.043a41.7 41.7 0 014.5-.895M7.5 6.043A41.87 41.87 0 0112 5.148m0 0c1.53 0 3.03.094 4.5.286M12 5.148v18.7m4.5-18.16c.836.08 1.66.187 2.474.316" />
                    </svg>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">{{ title }}</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        This module is specified for <strong>{{ phase }}</strong> of the build plan and will land here next.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
