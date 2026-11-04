<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { ref } from 'vue';

const props = defineProps({
    activities: Object,
    filters: Object,
});

const event = ref(props.filters?.event ?? '');
const causer = ref(props.filters?.causer ?? '');
const perPage = ref(props.filters?.per_page ?? 20);

function doFilter() {
    router.get(
        route('audit.index'),
        { event: event.value, causer: causer.value, per_page: perPage.value },
        { preserveState: true, replace: true }
    );
}

function fmt(value) {
    return value ? new Date(value).toLocaleString() : '—';
}

function shortClass(cls) {
    return (cls ?? '').split('\\').pop() || '—';
}

function prettyProps(props_) {
    return JSON.stringify(props_ ?? {}, null, 2);
}

const expanded = ref(null);
function toggle(id) {
    expanded.value = expanded.value === id ? null : id;
}
</script>

<template>
    <Head title="Audit Logs" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Audit Logs</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div
                    v-if="$page.props.flash?.success"
                    class="mb-4 rounded-lg border border-emerald-500/50 bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
                >
                    {{ $page.props.flash.success }}
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <form @submit.prevent="doFilter" class="flex flex-wrap items-end gap-3 border-b border-gray-200 p-4 dark:border-gray-700">
                        <div>
                            <label class="mb-1 block text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Event</label>
                            <TextInput v-model="event" type="search" placeholder="e.g. updated, login" class="w-48" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Causer email</label>
                            <TextInput v-model="causer" type="search" placeholder="e.g. manage@superadmin.admin" class="w-64" />
                        </div>
                        <PrimaryButton type="submit">Filter</PrimaryButton>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-3">When</th>
                                    <th class="px-4 py-3">Event</th>
                                    <th class="px-4 py-3">Description</th>
                                    <th class="px-4 py-3">Causer</th>
                                    <th class="px-4 py-3">Subject</th>
                                    <th class="px-4 py-3">Changes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr
                                    v-for="activity in activities.data"
                                    :key="activity.id"
                                    class="align-top hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                >
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ fmt(activity.created_at) }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full bg-indigo-500/20 px-2 py-0.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400">
                                            {{ activity.event || activity.log_name }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-800 dark:text-gray-200">{{ activity.description }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ activity.causer?.email ?? 'system' }}</td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ shortClass(activity.subject_type) }} #{{ activity.subject_id ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <button
                                            v-if="activity.properties && Object.keys(activity.properties).length"
                                            type="button"
                                            @click="toggle(activity.id)"
                                            class="text-indigo-600 hover:underline dark:text-indigo-400"
                                        >
                                            {{ expanded === activity.id ? 'Hide diff' : 'View diff' }}
                                        </button>
                                        <span v-else class="text-gray-400">—</span>
                                        <pre
                                            v-if="expanded === activity.id"
                                            class="mt-2 max-w-xl overflow-auto rounded bg-gray-900 p-3 text-xs text-emerald-300"
                                        >{{ prettyProps(activity.properties) }}</pre>
                                    </td>
                                </tr>
                                <tr v-if="activities.data.length === 0">
                                    <td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                        No audit entries found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <span>Rows per page:</span>
                            <select
                                v-model="perPage"
                                @change="doFilter"
                                class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                            >
                                <option :value="20">20</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="1000">1000</option>
                            </select>
                            <span v-if="activities.total !== undefined" class="ml-2 text-xs text-gray-500">
                                (Total: {{ activities.total }})
                            </span>
                        </div>

                        <div v-if="(activities.links ?? []).length > 3" class="flex flex-wrap gap-1">
                            <component
                                v-for="(link, i) in activities.links"
                                :key="i"
                                :is="link.url ? Link : 'span'"
                                :href="link.url ?? undefined"
                                class="rounded px-3 py-1 text-sm"
                                :class="link.active
                                    ? 'bg-indigo-600 text-white'
                                    : (link.url ? 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' : 'text-gray-400')"
                                v-html="link.label"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
