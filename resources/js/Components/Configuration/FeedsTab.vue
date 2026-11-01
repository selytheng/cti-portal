<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ feeds: Object });

const page = usePage();
const categories = ['Vendor Advisory', 'CERT/Gov', 'Security Blog', 'Exploit Feed'];

const showForm = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    url: '',
    category: 'Security Blog',
    polling_interval_minutes: 30,
    is_active: true,
    custom_headers: '',
});

const testResult = computed(() => {
    const r = page.props.flash?.testResult;
    return r && r.type === 'feed' ? r : null;
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.category = 'Security Blog';
    showForm.value = true;
}

function openEdit(feed) {
    editing.value = feed;
    form.name = feed.name;
    form.url = feed.url;
    form.category = feed.category;
    form.polling_interval_minutes = feed.polling_interval_minutes;
    form.is_active = feed.is_active;
    form.custom_headers = feed.custom_headers ? JSON.stringify(feed.custom_headers, null, 2) : '';
    showForm.value = true;
}

function save() {
    const payload = { ...form.data() };
    if (typeof payload.custom_headers === 'string') {
        const s = payload.custom_headers.trim();
        if (s) {
            try {
                payload.custom_headers = JSON.parse(s);
            } catch {
                alert('Custom headers must be valid JSON, e.g. {"User-Agent": "MyAgent"}');
                return;
            }
        } else {
            payload.custom_headers = null;
        }
    } else if (!payload.custom_headers) {
        payload.custom_headers = null;
    }

    const options = {
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    };

    if (editing.value) {
        form.transform(() => payload).patch(route('config.feeds.update', editing.value.id), options);
    } else {
        form.transform(() => payload).post(route('config.feeds.store'), options);
    }
}

function destroy(feed) {
    if (confirm(`Delete feed "${feed.name}"?`)) {
        router.delete(route('config.feeds.destroy', feed.id));
    }
}

function test(feed) {
    router.post(route('config.feeds.test', feed.id), {}, { preserveState: true });
}

function fmt(v) {
    return v ? new Date(v).toLocaleString() : 'never';
}
</script>

<template>
    <div>
        <!-- Test result panel -->
        <div
            v-if="testResult"
            class="mb-4 rounded-lg border p-4 text-sm"
            :class="testResult.error
                ? 'border-red-500/50 bg-red-500/10 text-red-600 dark:text-red-400'
                : 'border-emerald-500/50 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'"
        >
            <div class="mb-2 font-semibold">
                Test result — {{ testResult.name }}
                <span v-if="testResult.format" class="ms-1 font-normal">({{ testResult.format }})</span>
            </div>
            <div v-if="testResult.error" class="font-mono text-xs">{{ testResult.error }}</div>
            <table v-else-if="testResult.items?.length" class="min-w-full text-xs">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-1 pe-4">Title</th>
                        <th class="py-1 pe-4">Published</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(item, i) in testResult.items" :key="i">
                        <td class="py-1 pe-4">{{ item.title }}</td>
                        <td class="py-1 pe-4 whitespace-nowrap">{{ item.published_at ? new Date(item.published_at).toLocaleString() : '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <div v-else>Feed parsed but returned no items.</div>
        </div>

        <div
            v-if="page.props.flash?.success"
            class="mb-4 rounded-lg border border-emerald-500/50 bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
        >
            {{ page.props.flash.success }}
        </div>

        <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
            <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-700">
                <h3 class="font-semibold text-gray-800 dark:text-gray-200">Intelligence Feeds</h3>
                <PrimaryButton @click="openCreate">+ Add Feed</PrimaryButton>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">URL</th>
                            <th class="px-4 py-3">Category</th>
                            <th class="px-4 py-3">Poll</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Last fetch</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr v-for="feed in feeds.data ?? feeds" :key="feed.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-200">{{ feed.name }}</td>
                            <td class="max-w-xs truncate px-4 py-3 text-gray-500 dark:text-gray-400" :title="feed.url">{{ feed.url }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ feed.category }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ feed.polling_interval_minutes }}m</td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="feed.is_active ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-gray-500/20 text-gray-500'"
                                >
                                    {{ feed.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                                {{ fmt(feed.last_fetched_at) }}
                                <span v-if="feed.last_status_code" class="ms-1">({{ feed.last_status_code }})</span>
                                <span v-if="feed.failure_count > 0" class="ms-1 text-red-500">{{ feed.failure_count }} fails</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex gap-2">
                                    <button type="button" @click="test(feed)" class="text-indigo-600 hover:underline dark:text-indigo-400">Test</button>
                                    <button type="button" @click="openEdit(feed)" class="text-gray-600 hover:underline dark:text-gray-300">Edit</button>
                                    <button type="button" @click="destroy(feed)" class="text-red-600 hover:underline dark:text-red-400">Delete</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="(feeds.data ?? feeds).length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No feeds configured yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add/Edit modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-lg bg-white p-6 dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-gray-200">
                    {{ editing ? 'Edit Feed' : 'Add Feed' }}
                </h3>

                <form @submit.prevent="save" class="space-y-4">
                    <div>
                        <InputLabel value="Name" />
                        <TextInput v-model="form.name" type="text" class="mt-1 block w-full" required />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Feed URL (RSS / Atom / JSON)" />
                        <TextInput v-model="form.url" type="url" class="mt-1 block w-full" required />
                        <InputError :message="form.errors.url" class="mt-1" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel value="Category" />
                            <select v-model="form.category" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                            </select>
                            <InputError :message="form.errors.category" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Polling interval (minutes)" />
                            <TextInput v-model.number="form.polling_interval_minutes" type="number" min="1" max="1440" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.polling_interval_minutes" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Custom HTTP headers (optional JSON)" />
                        <textarea
                            v-model="form.custom_headers"
                            rows="3"
                            class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                            placeholder='{"User-Agent": "MyCustomAgent/1.0"}'
                        />
                        <InputError :message="form.errors.custom_headers" class="mt-1" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" v-model="form.is_active" class="rounded" />
                        Feed active
                    </label>

                    <div class="flex justify-end gap-2">
                        <SecondaryButton type="button" @click="showForm = false">Cancel</SecondaryButton>
                        <PrimaryButton type="submit" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            {{ editing ? 'Save Changes' : 'Add Feed' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
