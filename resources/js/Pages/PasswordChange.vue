<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

defineProps({
    warning: String,
});

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.patch(route('password.change.store'), {
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Change Password" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Change Your Password</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div
                    v-if="warning"
                    class="mb-4 rounded-lg border border-amber-500/50 bg-amber-500/10 p-4 text-sm text-amber-600 dark:text-amber-400"
                >
                    {{ warning }}
                </div>

                <div class="bg-white p-6 shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <p class="mb-6 text-sm text-gray-600 dark:text-gray-300">
                        Your account is using a temporary or reset password. Choose a new one to continue —
                        privileged roles will then be required to enroll MFA.
                    </p>

                    <form @submit.prevent="submit" class="space-y-6">
                        <div>
                            <InputLabel for="current_password" value="Current password" />
                            <TextInput
                                id="current_password"
                                v-model="form.current_password"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="current-password"
                                required
                            />
                            <InputError :message="form.errors.current_password" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="password" value="New password (min 10 characters)" />
                            <TextInput
                                id="password"
                                v-model="form.password"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="new-password"
                                required
                            />
                            <InputError :message="form.errors.password" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="password_confirmation" value="Confirm new password" />
                            <TextInput
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="new-password"
                                required
                            />
                            <InputError :message="form.errors.password_confirmation" class="mt-2" />
                        </div>

                        <div class="flex items-center justify-end">
                            <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                                Set New Password
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
