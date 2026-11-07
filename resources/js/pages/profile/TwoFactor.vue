<script setup lang="ts">
import ConfirmedTwoFactorAuthenticationController from '@/actions/Laravel/Fortify/Http/Controllers/ConfirmedTwoFactorAuthenticationController';
import TwoFactorAuthenticationController from '@/actions/Laravel/Fortify/Http/Controllers/TwoFactorAuthenticationController';
import TwoFactorQrCodeController from '@/actions/Laravel/Fortify/Http/Controllers/TwoFactorQrCodeController';
import TwoFactorSecretKeyController from '@/actions/Laravel/Fortify/Http/Controllers/TwoFactorSecretKeyController';
import Modal from '@/components/Modal.vue';
import ProfileSettingsTabs from '@/components/ProfileSettingsTabs.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { usePropertyTime } from '@/lib/usePropertyTime';
import {
    createPasskey,
    passkeyErrorMessage,
    passkeysSupported,
    requestJson,
} from '@/lib/webauthn';
import profile from '@/routes/profile';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

defineOptions({ layout: AdminLayout });

type CodeChannel = {
    value: string;
    label: string;
    enabled: boolean;
    available: boolean;
    destination: string;
};
type PasskeyRow = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at: string | null;
    last_used_at: string | null;
};

const props = defineProps<{
    twoFactorEnabled: boolean;
    twoFactorConfirmed: boolean;
    channels: CodeChannel[];
    passkeys: PasskeyRow[];
    hasTwoFactor: boolean;
    status: string | null;
}>();

const propertyTime = usePropertyTime();

/** Flash messages from the channel and passkey routes (Fortify's own use fixed keys handled below). */
const statusMessage = computed(() =>
    props.status &&
    !props.status.startsWith('two-factor-authentication-') &&
    !['passkey-registered', 'passkey-deleted'].includes(props.status)
        ? props.status
        : props.status === 'passkey-deleted'
          ? 'Passkey removed.'
          : '',
);

// ── Passkeys ──
const canUsePasskeys = passkeysSupported();
const passkeyName = ref('');
const passkeyBusy = ref(false);
const passkeyError = ref('');

async function addPasskey(): Promise<void> {
    passkeyBusy.value = true;
    passkeyError.value = '';

    try {
        const { options } = await requestJson<{
            options: Record<string, unknown>;
        }>(profile.passkeys.options().url);
        const credential = await createPasskey(options);
        await requestJson(profile.passkeys.store().url, 'POST', {
            name: passkeyName.value.trim() || 'Passkey',
            credential,
        });
        passkeyName.value = '';
        router.reload({ only: ['passkeys', 'hasTwoFactor'] });
    } catch (error) {
        passkeyError.value = passkeyErrorMessage(error);
    } finally {
        passkeyBusy.value = false;
    }
}

function removePasskey(passkey: PasskeyRow): void {
    router.delete(profile.passkeys.destroy(passkey.id).url, {
        preserveScroll: true,
    });
}

// ── Codes by WhatsApp, SMS or e-mail ──
const channelSetup = ref<string | null>(null);
const channelCode = ref('');
const channelMessage = ref('');
const channelError = ref('');
const channelBusy = ref(false);

async function sendChannelCode(channel: CodeChannel): Promise<void> {
    channelSetup.value = channel.value;
    channelCode.value = '';
    channelMessage.value = '';
    channelError.value = '';
    channelBusy.value = true;

    try {
        channelMessage.value = (
            await requestJson<{ message: string }>(
                profile.twoFactor.channels.code(channel.value).url,
                'POST',
            )
        ).message;
    } catch (error) {
        channelError.value =
            error instanceof Error
                ? error.message
                : 'The code could not be sent.';
    } finally {
        channelBusy.value = false;
    }
}

function confirmChannel(channel: CodeChannel): void {
    channelBusy.value = true;
    router.post(
        profile.twoFactor.channels.enable(channel.value).url,
        { code: channelCode.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                channelSetup.value = null;
                channelCode.value = '';
            },
            onError: (errors) =>
                (channelError.value =
                    errors.code ??
                    Object.values(errors)[0] ??
                    'The code could not be checked.'),
            onFinish: () => (channelBusy.value = false),
        },
    );
}

function disableChannel(channel: CodeChannel): void {
    router.delete(profile.twoFactor.channels.disable(channel.value).url, {
        preserveScroll: true,
    });
}

const qrCodeSvg = ref('');
const secretKey = ref('');
const recoveryCodes = ref<string[]>([]);
const showRecoveryCodes = ref(false);
const loadError = ref('');
const confirmDisableOpen = ref(false);
const busy = ref(false);

const confirmForm = useForm({ code: '' });

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Request failed (${response.status})`);
    }

    return (await response.json()) as T;
}

/** Setup step: the QR code and manual key are only available while 2FA is enabled but not yet confirmed. */
async function loadSetupDetails(): Promise<void> {
    loadError.value = '';

    try {
        const [qr, secret] = await Promise.all([
            getJson<{ svg: string }>(TwoFactorQrCodeController.show().url),
            getJson<{ secretKey: string }>(
                TwoFactorSecretKeyController.show().url,
            ),
        ]);
        qrCodeSvg.value = qr.svg;
        secretKey.value = secret.secretKey;
    } catch {
        loadError.value =
            'Could not load the QR code. Refresh the page and try again.';
    }
}

async function loadRecoveryCodes(): Promise<void> {
    try {
        recoveryCodes.value = await getJson<string[]>(
            profile.twoFactor.recoveryCodes().url,
        );
        showRecoveryCodes.value = true;
    } catch {
        loadError.value = 'Could not load your recovery codes.';
    }
}

function enable(): void {
    busy.value = true;
    router.post(
        TwoFactorAuthenticationController.store().url,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}

function confirm(): void {
    confirmForm.post(ConfirmedTwoFactorAuthenticationController.store().url, {
        errorBag: 'confirmTwoFactorAuthentication',
        preserveScroll: true,
        onSuccess: () => {
            confirmForm.reset();
            loadRecoveryCodes();
        },
    });
}

function regenerateRecoveryCodes(): void {
    busy.value = true;
    router.post(
        profile.twoFactor.recoveryCodes.regenerate().url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => loadRecoveryCodes(),
            onFinish: () => (busy.value = false),
        },
    );
}

function disable(): void {
    busy.value = true;
    router.delete(TwoFactorAuthenticationController.destroy().url, {
        preserveScroll: true,
        onSuccess: () => {
            confirmDisableOpen.value = false;
            qrCodeSvg.value = '';
            secretKey.value = '';
            recoveryCodes.value = [];
            showRecoveryCodes.value = false;
        },
        onFinish: () => (busy.value = false),
    });
}

onMounted(() => {
    if (props.twoFactorEnabled && !props.twoFactorConfirmed) {
        loadSetupDetails();
    }
});

watch(
    () => [props.twoFactorEnabled, props.twoFactorConfirmed],
    ([enabled, confirmed]) => {
        if (enabled && !confirmed && !qrCodeSvg.value) {
            loadSetupDetails();
        }
    },
);
</script>

<template>
    <Head title="Two-Factor Authentication" />

    <h1
        class="font-display mb-6 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
    >
        Profile Settings
    </h1>

    <ProfileSettingsTabs active="two-factor" />

    <p class="mb-4 max-w-xl text-sm text-[#706f6c] dark:text-[#A1A09A]">
        Turn on one or more ways to prove it's you after your password. At login
        you pick any of them.
    </p>
    <p v-if="statusMessage" class="mb-4 max-w-xl text-sm text-green-600">
        {{ statusMessage }}
    </p>

    <div class="flex flex-col gap-6">
        <!-- Passkeys -->
        <section
            class="max-w-xl rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
        >
            <div class="mb-2 flex items-center gap-3">
                <h2
                    class="text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Passkeys
                </h2>
                <span
                    :class="
                        passkeys.length
                            ? 'rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-950 dark:text-green-400'
                            : 'rounded-full bg-[#f5f5f4] px-2 py-0.5 text-xs font-semibold text-[#706f6c] dark:bg-[#1D1D1B] dark:text-[#A1A09A]'
                    "
                    >{{ passkeys.length ? 'On' : 'Off' }}</span
                >
            </div>
            <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Confirm it's you with your device's fingerprint, face or screen
                lock, or a security key.
            </p>

            <ul
                v-if="passkeys.length"
                class="mb-4 divide-y divide-[#e3e3e0] rounded-md border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
            >
                <li
                    v-for="passkey in passkeys"
                    :key="passkey.id"
                    class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                >
                    <div>
                        <p
                            class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ passkey.name
                            }}<span
                                v-if="passkey.authenticator"
                                class="font-normal text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                · {{ passkey.authenticator }}</span
                            >
                        </p>
                        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                            Added {{ propertyTime.date(passkey.created_at) }} ·
                            {{
                                passkey.last_used_at
                                    ? `last used ${propertyTime.dateTime(passkey.last_used_at)}`
                                    : 'not used yet'
                            }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md border border-[#f53003] px-3 py-1.5 text-sm font-medium text-[#f53003] hover:bg-[#fff2f2] dark:border-[#FF4433] dark:text-[#FF4433] dark:hover:bg-[#1D1D1B]"
                        @click="removePasskey(passkey)"
                    >
                        Remove
                    </button>
                </li>
            </ul>

            <p
                v-if="!canUsePasskeys"
                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
            >
                This browser doesn't support passkeys.
            </p>
            <form
                v-else
                class="flex flex-wrap items-start gap-3"
                @submit.prevent="addPasskey"
            >
                <input
                    v-model="passkeyName"
                    type="text"
                    maxlength="255"
                    placeholder="Name, e.g. Work laptop"
                    class="block w-56 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
                <button
                    type="submit"
                    :disabled="passkeyBusy"
                    class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A]"
                >
                    {{
                        passkeyBusy
                            ? 'Waiting for your device…'
                            : 'Add a passkey'
                    }}
                </button>
            </form>
            <p v-if="passkeyError" class="mt-2 text-sm text-red-600">
                {{ passkeyError }}
            </p>
        </section>

        <!-- Codes by WhatsApp, SMS, e-mail -->
        <section
            class="max-w-xl rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
        >
            <h2
                class="mb-2 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                One-time codes
            </h2>
            <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Get a 6-digit code by WhatsApp, SMS or e-mail at login. We send
                a code now to check it reaches you.
            </p>

            <ul
                class="divide-y divide-[#e3e3e0] rounded-md border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
            >
                <li
                    v-for="channel in channels"
                    :key="channel.value"
                    class="px-3 py-3 text-sm"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div>
                            <p
                                class="flex items-center gap-2 font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ channel.label }}
                                <span
                                    :class="
                                        channel.enabled
                                            ? 'rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-950 dark:text-green-400'
                                            : 'rounded-full bg-[#f5f5f4] px-2 py-0.5 text-xs font-semibold text-[#706f6c] dark:bg-[#1D1D1B] dark:text-[#A1A09A]'
                                    "
                                    >{{ channel.enabled ? 'On' : 'Off' }}</span
                                >
                            </p>
                            <p
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{
                                    channel.available
                                        ? channel.destination
                                        : 'Add your mobile number under Profile first.'
                                }}
                            </p>
                        </div>
                        <button
                            v-if="channel.enabled"
                            type="button"
                            class="rounded-md border border-[#f53003] px-3 py-1.5 text-sm font-medium text-[#f53003] hover:bg-[#fff2f2] dark:border-[#FF4433] dark:text-[#FF4433] dark:hover:bg-[#1D1D1B]"
                            @click="disableChannel(channel)"
                        >
                            Turn off
                        </button>
                        <button
                            v-else
                            type="button"
                            :disabled="!channel.available || channelBusy"
                            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] disabled:opacity-50 dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                            @click="sendChannelCode(channel)"
                        >
                            {{
                                channelSetup === channel.value
                                    ? 'Send a new code'
                                    : 'Turn on'
                            }}
                        </button>
                    </div>

                    <form
                        v-if="
                            channelSetup === channel.value && !channel.enabled
                        "
                        class="mt-3 flex flex-wrap items-start gap-3"
                        @submit.prevent="confirmChannel(channel)"
                    >
                        <input
                            v-model="channelCode"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            placeholder="123456"
                            required
                            class="block w-36 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 font-mono text-sm tracking-widest text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <button
                            type="submit"
                            :disabled="channelBusy"
                            class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A]"
                        >
                            Confirm
                        </button>
                        <p
                            v-if="channelMessage"
                            class="basis-full text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{ channelMessage }}
                        </p>
                    </form>
                    <p
                        v-if="channelSetup === channel.value && channelError"
                        class="mt-2 text-sm text-red-600"
                    >
                        {{ channelError }}
                    </p>
                </li>
            </ul>
        </section>

        <!-- Authenticator app -->
        <section
            class="max-w-xl rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
        >
            <div class="mb-2 flex items-center gap-3">
                <h2
                    class="text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Authenticator app (TOTP)
                </h2>
                <span
                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                    :class="
                        twoFactorConfirmed
                            ? 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400'
                            : 'bg-[#f5f5f4] text-[#706f6c] dark:bg-[#1D1D1B] dark:text-[#A1A09A]'
                    "
                >
                    {{
                        twoFactorConfirmed
                            ? 'On'
                            : twoFactorEnabled
                              ? 'Setup in progress'
                              : 'Off'
                    }}
                </span>
            </div>
            <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                When it's on, logging in also asks for a 6-digit code from an
                authenticator app (Google Authenticator, Microsoft
                Authenticator, Authy, 1Password…).
            </p>

            <p v-if="loadError" class="mb-3 text-sm text-red-600">
                {{ loadError }}
            </p>
            <p
                v-if="status === 'two-factor-authentication-disabled'"
                class="mb-3 text-sm text-green-600"
            >
                Two-factor authentication turned off.
            </p>
            <p
                v-if="status === 'two-factor-authentication-confirmed'"
                class="mb-3 text-sm text-green-600"
            >
                Two-factor authentication is on. Save your recovery codes below
                somewhere safe.
            </p>

            <!-- Off -->
            <button
                v-if="!twoFactorEnabled"
                type="button"
                :disabled="busy"
                class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A]"
                @click="enable"
            >
                Set up an authenticator app
            </button>

            <!-- Enabled, waiting for the first code -->
            <div v-else-if="!twoFactorConfirmed" class="flex flex-col gap-4">
                <ol
                    class="list-inside list-decimal text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <li>
                        Scan this QR code with your authenticator app, or enter
                        the setup key.
                    </li>
                    <li>Type the 6-digit code the app shows to finish.</li>
                </ol>
                <div class="flex flex-wrap items-center gap-6">
                    <div
                        class="rounded-md border border-[#e3e3e0] bg-white p-3"
                        aria-label="Two-factor QR code"
                    >
                        <div v-if="qrCodeSvg" v-html="qrCodeSvg"></div>
                        <div
                            v-else
                            class="flex h-48 w-48 items-center justify-center text-xs text-[#706f6c]"
                        >
                            Loading…
                        </div>
                    </div>
                    <div
                        v-if="secretKey"
                        class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                            Setup key
                        </p>
                        <p class="font-mono break-all">{{ secretKey }}</p>
                    </div>
                </div>
                <form
                    class="flex flex-wrap items-start gap-3"
                    @submit.prevent="confirm"
                >
                    <div class="flex flex-col gap-1">
                        <input
                            v-model="confirmForm.code"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            placeholder="123456"
                            required
                            class="block w-36 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 font-mono text-sm tracking-widest text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <span
                            v-if="confirmForm.errors.code"
                            class="text-sm text-red-600"
                            >{{ confirmForm.errors.code }}</span
                        >
                    </div>
                    <button
                        type="submit"
                        :disabled="confirmForm.processing"
                        class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A]"
                    >
                        Confirm
                    </button>
                    <button
                        type="button"
                        :disabled="busy"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="disable"
                    >
                        Cancel setup
                    </button>
                </form>
            </div>

            <!-- On -->
            <div v-else class="flex flex-col gap-4">
                <div class="flex flex-wrap gap-3">
                    <button
                        type="button"
                        class="rounded-md border border-[#f53003] px-4 py-2 text-sm font-medium text-[#f53003] hover:bg-[#fff2f2] dark:border-[#FF4433] dark:text-[#FF4433] dark:hover:bg-[#1D1D1B]"
                        @click="confirmDisableOpen = true"
                    >
                        Turn off
                    </button>
                </div>
            </div>
        </section>

        <!-- Recovery codes: shared by every method -->
        <section
            v-if="hasTwoFactor"
            class="max-w-xl rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
        >
            <h2
                class="mb-2 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Recovery codes
            </h2>
            <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Each recovery code logs you in once if you can't use any of your
                methods. Keep them somewhere safe.
            </p>
            <div
                v-if="showRecoveryCodes && recoveryCodes.length"
                class="mb-4 rounded-md bg-[#f5f5f4] p-4 dark:bg-[#1D1D1B]"
            >
                <ul
                    class="grid grid-cols-2 gap-1 font-mono text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <li v-for="code in recoveryCodes" :key="code">
                        {{ code }}
                    </li>
                </ul>
            </div>
            <div class="flex flex-wrap gap-3">
                <button
                    v-if="!showRecoveryCodes"
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] disabled:opacity-50 dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="loadRecoveryCodes"
                >
                    Show recovery codes
                </button>
                <button
                    type="button"
                    :disabled="busy"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] disabled:opacity-50 dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="regenerateRecoveryCodes"
                >
                    Regenerate recovery codes
                </button>
            </div>
        </section>
    </div>

    <Modal
        :show="confirmDisableOpen"
        title="Turn off the authenticator app?"
        @close="confirmDisableOpen = false"
    >
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Its codes stop working, and so do your recovery codes (new ones are
            made if another method stays on).
        </p>
        <div class="mt-6 flex justify-end gap-3">
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="confirmDisableOpen = false"
            >
                Keep it on
            </button>
            <button
                type="button"
                :disabled="busy"
                class="rounded-md bg-[#f53003] px-4 py-2 text-sm font-medium text-white hover:bg-[#d92a02] disabled:opacity-50"
                @click="disable"
            >
                Turn off
            </button>
        </div>
    </Modal>
</template>
