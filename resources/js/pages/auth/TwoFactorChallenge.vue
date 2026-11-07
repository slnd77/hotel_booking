<script setup lang="ts">
import TwoFactorAuthenticatedSessionController from '@/actions/Laravel/Fortify/Http/Controllers/TwoFactorAuthenticatedSessionController';
import GuestLayout from '@/layouts/GuestLayout.vue';
import {
    getPasskey,
    passkeyErrorMessage,
    passkeysSupported,
    requestJson,
} from '@/lib/webauthn';
import twoFactor from '@/routes/two-factor';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineOptions({ layout: GuestLayout });

type Method = {
    value: 'passkey' | 'totp' | 'whatsapp' | 'sms' | 'email';
    label: string;
    destination: string | null;
};

const props = defineProps<{
    /** The methods the user turned on, in the order they are offered. */
    methods: Method[];
}>();

/** Second login step: any of the user's two-factor methods, or a one-time recovery code. */
const selected = ref<Method['value'] | 'recovery'>(
    props.methods[0]?.value ?? 'recovery',
);
const current = computed(
    () =>
        props.methods.find((method) => method.value === selected.value) ?? null,
);
const sendsCode = computed(() =>
    ['whatsapp', 'sms', 'email'].includes(selected.value),
);

const form = useForm({ method: '', code: '', recovery_code: '' });
const codeSent = ref<Record<string, string>>({});
const sending = ref(false);
const passkeyBusy = ref(false);
const message = ref('');

function choose(value: Method['value'] | 'recovery'): void {
    selected.value = value;
    message.value = '';
    form.reset();
    form.clearErrors();
}

async function sendCode(): Promise<void> {
    sending.value = true;
    message.value = '';
    form.clearErrors();

    try {
        codeSent.value[selected.value] = (
            await requestJson<{ message: string }>(
                twoFactor.code.send().url,
                'POST',
                { method: selected.value },
            )
        ).message;
    } catch (error) {
        message.value =
            error instanceof Error
                ? error.message
                : 'The code could not be sent.';
    } finally {
        sending.value = false;
    }
}

function submit(): void {
    if (sendsCode.value) {
        form.transform((data) => ({
            method: selected.value,
            code: data.code,
        })).post(twoFactor.code.verify().url, { onFinish: () => form.reset() });

        return;
    }

    form.transform((data) =>
        selected.value === 'recovery'
            ? { recovery_code: data.recovery_code }
            : { code: data.code },
    ).post(TwoFactorAuthenticatedSessionController.store().url, {
        onFinish: () => form.reset(),
    });
}

async function usePasskey(): Promise<void> {
    passkeyBusy.value = true;
    message.value = '';

    try {
        const { options } = await requestJson<{
            options: Record<string, unknown>;
        }>(twoFactor.passkey.options().url);
        const credential = await getPasskey(options);
        await requestJson(twoFactor.passkey.verify().url, 'POST', {
            credential,
        });
        window.location.href = '/';
    } catch (error) {
        message.value = passkeyErrorMessage(error);
    } finally {
        passkeyBusy.value = false;
    }
}

const inputClass =
    'block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]';
const primaryButton =
    'mt-2 inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white';
</script>

<template>
    <Head title="Two-factor authentication" />

    <h1
        class="font-display mb-2 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
    >
        Two-factor authentication
    </h1>
    <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
        Confirm it's you with one of your methods.
    </p>

    <div
        v-if="methods.length > 1"
        class="mb-6 flex flex-wrap gap-2"
        role="tablist"
        aria-label="Two-factor method"
    >
        <button
            v-for="method in methods"
            :key="method.value"
            type="button"
            role="tab"
            :aria-selected="selected === method.value"
            class="rounded-full border px-3 py-1 text-xs font-medium"
            :class="
                selected === method.value
                    ? 'border-[#1b1b18] bg-[#1b1b18] text-white dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A]'
                    : 'border-[#e3e3e0] text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]'
            "
            @click="choose(method.value)"
        >
            {{ method.label }}
        </button>
    </div>

    <!-- Passkey -->
    <div v-if="selected === 'passkey'" class="flex flex-col gap-3">
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Use your device's fingerprint, face or screen lock, or your security
            key.
        </p>
        <p v-if="!passkeysSupported()" class="text-sm text-red-600">
            This browser doesn't support passkeys. Choose another method.
        </p>
        <button
            v-else
            type="button"
            :disabled="passkeyBusy"
            :class="primaryButton"
            @click="usePasskey"
        >
            {{ passkeyBusy ? 'Waiting for your device…' : 'Use a passkey' }}
        </button>
    </div>

    <!-- Codes: authenticator app, WhatsApp, SMS, e-mail, or a recovery code -->
    <form v-else class="flex flex-col gap-4" @submit.prevent="submit">
        <template v-if="sendsCode">
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                {{
                    codeSent[selected] ??
                    `We'll send a 6-digit code to ${current?.destination}.`
                }}
            </p>
            <button
                type="button"
                :disabled="sending"
                class="self-start text-sm font-medium text-[#f53003] underline underline-offset-4 disabled:opacity-50 dark:text-[#FF4433]"
                @click="sendCode"
            >
                {{
                    sending
                        ? 'Sending…'
                        : codeSent[selected]
                          ? 'Send a new code'
                          : 'Send code'
                }}
            </button>
        </template>
        <p
            v-else-if="selected === 'totp'"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            Enter the 6-digit code from your authenticator app.
        </p>
        <p v-else class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Enter one of your emergency recovery codes.
        </p>

        <div v-if="selected !== 'recovery'">
            <label
                for="code"
                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Code</label
            >
            <input
                id="code"
                v-model="form.code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                required
                autofocus
                :class="[inputClass, 'font-mono tracking-widest']"
            />
            <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">
                {{ form.errors.code }}
            </p>
        </div>
        <div v-else>
            <label
                for="recovery_code"
                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Recovery code</label
            >
            <input
                id="recovery_code"
                v-model="form.recovery_code"
                type="text"
                autocomplete="one-time-code"
                required
                autofocus
                :class="[inputClass, 'font-mono']"
            />
            <p
                v-if="form.errors.recovery_code"
                class="mt-1 text-sm text-red-600"
            >
                {{ form.errors.recovery_code }}
            </p>
        </div>

        <button
            type="submit"
            :disabled="form.processing"
            :class="primaryButton"
        >
            {{ form.processing ? 'Checking...' : 'Log in' }}
        </button>
    </form>

    <p v-if="message || form.errors.method" class="mt-3 text-sm text-red-600">
        {{ message || form.errors.method }}
    </p>

    <button
        type="button"
        class="mt-6 text-sm font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
        @click="
            choose(
                selected === 'recovery'
                    ? (methods[0]?.value ?? 'recovery')
                    : 'recovery',
            )
        "
    >
        {{
            selected === 'recovery'
                ? 'Use a two-factor method instead'
                : 'Use a recovery code instead'
        }}
    </button>
</template>
