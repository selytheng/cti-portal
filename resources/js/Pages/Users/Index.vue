<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { ref } from 'vue';

const props = defineProps({
    users: Object,
    filters: Object,
    generatedPassword: String,
});

// `$page` only exists in templates — script scope must use usePage().
const page = usePage();

const isSuperadmin = () =>
    (page.props.auth.user?.roles ?? []).some((r) => r.name === 'superadmin');

const search = ref(props.filters?.search ?? '');

function doSearch() {
    router.get(route('users.index'), { search: search.value }, { preserveState: true, replace: true });
}

function destroy(user) {
    if (confirm(`Delete user ${user.email}? This cannot be undone.`)) {
        router.delete(route('users.destroy', user.id));
    }
}

function fmt(value) {
    return value ? new Date(value).toLocaleString() : '—';
}
</script>

<template>
    <Head title="User Management" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">User Management</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- Flash: generated credential shown once -->
                <div
                    v-if="generatedPassword"
                    class="mb-4 rounded-lg border border-amber-500/50 bg-amber-500/10 p-4 text-sm text-amber-600 dark:text-amber-400"
                >
                    <strong>Temporary password (shown once):</strong>
                    <code class="mx-1 rounded bg-amber-500/20 px-2 py-1 font-mono">{{ generatedPassword }}</code>
                    The user must change it at first login.
                </div>

                <div
                    v-if="$page.props.flash?.success"
                    class="mb-4 rounded-lg border border-emerald-500/50 bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
                >
                    {{ $page.props.flash.success }}
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 p-4 dark:border-gray-700">
                        <form @submit.prevent="doSearch" class="flex items-center gap-2">
                            <TextInput
                                v-model="search"
                                type="search"
                                placeholder="Search name or email…"
                                class="w-64"
                            />
                            <PrimaryButton type="submit">Search</PrimaryButton>
                        </form>

                        <Link :href="route('users.create')">
                            <PrimaryButton>+ New User</PrimaryButton>
                        </Link>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Email</th>
                                    <th class="px-4 py-3">Roles</th>
                                    <th class="px-4 py-3">Password state</th>
                                    <th class="px-4 py-3">Created</th>
                                    <th class="px-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr
                                    v-for="user in users.data"
                                    :key="user.id"
                                    class="hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                >
                                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-200">{{ user.name }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ user.email }}</td>
                                    <td class="px-4 py-3">
                                        <span
                                            v-for="role in user.roles"
                                            :key="role.id"
                                            class="me-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="{
                                                'bg-red-500/20 text-red-600 dark:text-red-400': role.name === 'superadmin',
                                                'bg-orange-500/20 text-orange-600 dark:text-orange-400': role.name === 'admin',
                                                'bg-blue-500/20 text-blue-600 dark:text-blue-400': role.name === 'monitoring',
                                            }"
                                        >
                                            {{ role.name }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            v-if="user.must_change_password"
                                            class="inline-flex rounded-full bg-amber-500/20 px-2 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400"
                                        >
                                            Must change
                                        </span>
                                        <span v-else class="text-gray-500 dark:text-gray-400">Set</span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ fmt(user.created_at) }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <Link
                                                :href="route('users.edit', user.id)"
                                                class="text-indigo-600 hover:underline dark:text-indigo-400"
                                            >
                                                Edit
                                            </Link>
                                            <button
                                                v-if="isSuperadmin()"
                                                type="button"
                                                @click="destroy(user)"
                                                class="text-red-600 hover:underline dark:text-red-400"
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="users.data.length === 0">
                                    <td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                        No users found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="users.links.length > 3" class="flex flex-wrap gap-1 border-t border-gray-200 p-4 dark:border-gray-700">
                        <component
                            v-for="(link, i) in users.links"
                            :key="i"
                            :is="link.url ? 'Link' : 'span'"
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
    </AuthenticatedLayout>
</template>
