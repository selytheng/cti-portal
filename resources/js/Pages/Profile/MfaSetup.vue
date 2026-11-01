<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import QRCode from 'qrcode';
import { onMounted, ref } from 'vue';

const props = defineProps({
    secret: String,
    otpauthUrl: String,
    enabled: Boolean,
});

const qr = ref('');

onMounted(async () => {
    if (props.otpauthUrl) {
        qr.value = await QRCode.toDataURL(props.otpauthUrl, { width: 220, margin: 1 });
    }
});

const verifyForm = useForm({ code: '' });

function verify() {
    verifyForm.post(route('mfa.verify'));
}

const disableForm = useForm({ current_password: '' });

function disable() {
    disableForm.post(route('mfa.disable'), {
        preserveScroll: true,
        onSuccess: () => disableForm.reset(),
    });
}
</script>

<template>
    <Head title="Multi-Factor Authentication" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Multi-Factor Authentication (TOTP)</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div
                    v-if="$page.props.flash?.success"
                    class="mb-4 rounded-lg border border-emerald-500/50 bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
                >
                    {{ $page.props.flash.success }}
                </div>

                <div class="bg-white p-6 shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <!-- Already enabled -->
                    <template v-if="enabled">
                        <div class="mb-4 flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            <span class="font-semibold">MFA is active on your account.</span>
                        </div>
                        <p class="mb-6 text-sm text-gray-600 dark:text-gray-300">
                            Your account requires a 6-digit authenticator code at every login.
                            To move MFA to a new device, disable it here and re-enroll immediately —
                            privileged roles cannot use the portal without MFA.
                        </p>

                        <form @submit.prevent="disable" class="space-y-4 border-t border-gray-200 pt-6 dark:border-gray-700">
                            <div>
                                <InputLabel for="current_password" value="Confirm your current password to disable MFA" />
                                <TextInput
                                    id="current_password"
                                    v-model="disableForm.current_password"
                                    type="password"
                                    class="mt-1 block w-full"
                                    autocomplete="current-password"
                                />
                                <InputError :message="disableForm.errors.current_password" class="mt-2" />
                            </div>
                            <SecondaryButton :class="{ 'opacity-25': disableForm.processing }" :disabled="disableForm.processing">
                                Disable MFA
                            </SecondaryButton>
                        </form>
                    </template>

                    <!-- Enrollment -->
                    <template v-else>
                        <p class="mb-6 text-sm text-gray-600 dark:text-gray-300">
                            Scan the QR code with your authenticator app (Google Authenticator, Aegis, 1Password…),
                            or add the key manually. Then enter the 6-digit code to activate MFA.
                        </p>

                        <div class="mb-6 flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                            <img v-if="qr" :src="qr" alt="TOTP QR code" class="rounded bg-white p-2" />
                            <div class="flex-1">
                                <InputLabel value="Manual entry key" />
                                <div class="mt-1 flex items-center gap-2">
                                    <code class="break-all rounded bg-gray-100 px-3 py-2 font-mono text-xs dark:bg-gray-900 dark:text-emerald-300">
                                        {{ secret }}
                                    </code>
                                    <button
                                        type="button"
                                        class="text-xs text-indigo-600 hover:underline dark:text-indigo-400"
                                        @click="navigator.clipboard.writeText(secret)"
                                    >
                                        Copy
                                    </button>
                                </div>
                            </div>
                        </div>

                        <form @submit.prevent="verify" class="space-y-4 border-t border-gray-200 pt-6 dark:border-gray-700">
                            <div>
                                <InputLabel for="code" value="6-digit authenticator code" />
                                <TextInput
                                    id="code"
                                    v-model="verifyForm.code"
                                    type="text"
                                    inputmode="numeric"
                                    maxlength="6"
                                    class="mt-1 block w-40 text-center font-mono text-lg tracking-widest"
                                    required
                                    autofocus
                                />
                                <InputError :message="verifyForm.errors.code" class="mt-2" />
                            </div>
                            <PrimaryButton :class="{ 'opacity-25': verifyForm.processing }" :disabled="verifyForm.processing">
                                Activate MFA
                            </PrimaryButton>
                        </form>
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
