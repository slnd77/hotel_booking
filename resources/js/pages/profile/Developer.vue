<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import ProfileSettingsTabs from '@/components/ProfileSettingsTabs.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { usePropertyTime } from '@/lib/usePropertyTime';
import profile from '@/routes/profile';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AdminLayout });

type Scope = { value: string; label: string; description: string };
type ApiKey = {
    id: number;
    name: string;
    scopes: string[];
    created_at: string | null;
    last_used_at: string | null;
    expires_at: string | null;
    expired: boolean;
};
type Device = {
    id: number;
    name: string;
    created_at: string | null;
    last_used_at: string | null;
};

const props = defineProps<{
    keys: ApiKey[];
    devices: Device[];
    scopes: Scope[];
    expiryDays: number[];
    newKey: { name: string; token: string } | null;
    apiBaseUrl: string;
    specUrl: string;
    endpoints: { method: string; uri: string; scope: string | null }[];
}>();

const propertyTime = usePropertyTime();

const form = useForm<{
    name: string;
    scopes: string[];
    expires_in_days: number | null;
}>({ name: '', scopes: [], expires_in_days: 90 });
const copied = ref(false);
const revoking = ref<ApiKey | null>(null);

function createKey(): void {
    form.post(profile.developer.keys.store().url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

async function copyKey(): Promise<void> {
    if (props.newKey) {
        await navigator.clipboard.writeText(props.newKey.token);
        copied.value = true;
    }
}

function revoke(): void {
    if (revoking.value) {
        router.delete(profile.developer.keys.destroy(revoking.value.id).url, {
            preserveScroll: true,
            onFinish: () => (revoking.value = null),
        });
    }
}

function signOutDevice(device: Device): void {
    router.delete(profile.developer.devices.destroy(device.id).url, {
        preserveScroll: true,
    });
}

function scopeLabel(value: string): string {
    return props.scopes.find((scope) => scope.value === value)?.label ?? value;
}

const card =
    'max-w-3xl rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]';
const inputClass =
    'block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]';
const primaryButton =
    'rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A]';
const dangerButton =
    'rounded-md border border-[#f53003] px-3 py-1.5 text-sm font-medium text-[#f53003] hover:bg-[#fff2f2] dark:border-[#FF4433] dark:text-[#FF4433] dark:hover:bg-[#1D1D1B]';
const muted = 'text-sm text-[#706f6c] dark:text-[#A1A09A]';
</script>

<template>
    <Head title="Developer" />

    <h1
        class="font-display mb-6 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
    >
        Profile Settings
    </h1>

    <ProfileSettingsTabs active="developer" />

    <div class="flex flex-col gap-6">
        <!-- The new key, shown once -->
        <section
            v-if="newKey"
            class="max-w-3xl rounded-lg border border-amber-300 bg-amber-50 p-5 dark:border-amber-700 dark:bg-amber-950"
        >
            <h2
                class="mb-1 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Your new API key: {{ newKey.name }}
            </h2>
            <p class="mb-3 text-sm text-amber-900 dark:text-amber-200">
                Copy it now and keep it secret. It won't be shown again.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <code
                    class="flex-1 rounded-md bg-white px-3 py-2 font-mono text-sm break-all text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >{{ newKey.token }}</code
                >
                <button type="button" :class="primaryButton" @click="copyKey">
                    {{ copied ? 'Copied' : 'Copy' }}
                </button>
            </div>
        </section>

        <!-- Create -->
        <section :class="card">
            <h2
                class="mb-1 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                API keys
            </h2>
            <p :class="[muted, 'mb-4']">
                Let another app use the hotel's data through the API as you. A
                key can only do what you pick here, and never more than your
                role allows at the time it's used.
            </p>

            <form class="flex flex-col gap-4" @submit.prevent="createKey">
                <label
                    class="flex flex-col gap-1 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Name
                    <input
                        v-model="form.name"
                        type="text"
                        required
                        maxlength="100"
                        placeholder="e.g. Channel manager sync"
                        :class="inputClass"
                    />
                    <span
                        v-if="form.errors.name"
                        class="text-sm font-normal text-red-600"
                        >{{ form.errors.name }}</span
                    >
                </label>

                <fieldset>
                    <legend
                        class="mb-1 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Scopes
                    </legend>
                    <p v-if="!scopes.length" :class="muted">
                        Your role doesn't allow any API scopes.
                    </p>
                    <label
                        v-for="scope in scopes"
                        :key="scope.value"
                        class="flex items-start gap-2 py-1 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="form.scopes"
                            type="checkbox"
                            :value="scope.value"
                            class="mt-1"
                        />
                        <span>
                            <span class="font-medium">{{ scope.label }}</span>
                            <code
                                class="ml-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >{{ scope.value }}</code
                            >
                            <span
                                class="block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >{{ scope.description }}</span
                            >
                        </span>
                    </label>
                    <span
                        v-if="form.errors.scopes"
                        class="text-sm text-red-600"
                        >{{ form.errors.scopes }}</span
                    >
                    <span v-for="(message, field) in form.errors" :key="field">
                        <span
                            v-if="String(field).startsWith('scopes.')"
                            class="block text-sm text-red-600"
                            >{{ message }}</span
                        >
                    </span>
                </fieldset>

                <label
                    class="flex flex-col gap-1 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Expires
                    <select
                        v-model="form.expires_in_days"
                        :class="[inputClass, 'w-48']"
                    >
                        <option
                            v-for="days in expiryDays"
                            :key="days"
                            :value="days"
                        >
                            In {{ days }} days
                        </option>
                        <option :value="null">Never</option>
                    </select>
                </label>

                <div>
                    <button
                        type="submit"
                        :disabled="form.processing || !form.scopes.length"
                        :class="primaryButton"
                    >
                        Generate key
                    </button>
                </div>
            </form>

            <table v-if="keys.length" class="mt-6 w-full text-left text-sm">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-2 pr-3 font-medium">Name</th>
                        <th class="py-2 pr-3 font-medium">Scopes</th>
                        <th class="py-2 pr-3 font-medium">Last used</th>
                        <th class="py-2 pr-3 font-medium">Expires</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]">
                    <tr
                        v-for="key in keys"
                        :key="key.id"
                        class="text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <td class="py-2 pr-3 font-medium">{{ key.name }}</td>
                        <td class="py-2 pr-3">
                            {{ key.scopes.map(scopeLabel).join(', ') }}
                        </td>
                        <td class="py-2 pr-3 whitespace-nowrap">
                            {{
                                key.last_used_at
                                    ? propertyTime.dateTime(key.last_used_at)
                                    : 'Never'
                            }}
                        </td>
                        <td
                            class="py-2 pr-3 whitespace-nowrap"
                            :class="key.expired ? 'text-red-600' : ''"
                        >
                            {{
                                key.expires_at
                                    ? propertyTime.date(key.expires_at)
                                    : 'Never'
                            }}{{ key.expired ? ' (expired)' : '' }}
                        </td>
                        <td class="py-2 text-right">
                            <button
                                type="button"
                                :class="dangerButton"
                                @click="revoking = key"
                            >
                                Revoke
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Mobile devices -->
        <section :class="card">
            <h2
                class="mb-1 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Signed-in devices
            </h2>
            <p :class="[muted, 'mb-4']">
                Phones and tablets signed in to the mobile app as you.
            </p>
            <p v-if="!devices.length" :class="muted">None.</p>
            <ul
                v-else
                class="divide-y divide-[#e3e3e0] rounded-md border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
            >
                <li
                    v-for="device in devices"
                    :key="device.id"
                    class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                >
                    <div>
                        <p
                            class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ device.name }}
                        </p>
                        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                            Signed in
                            {{ propertyTime.dateTime(device.created_at) }} ·
                            {{
                                device.last_used_at
                                    ? `last used ${propertyTime.dateTime(device.last_used_at)}`
                                    : 'not used yet'
                            }}
                        </p>
                    </div>
                    <button
                        type="button"
                        :class="dangerButton"
                        @click="signOutDevice(device)"
                    >
                        Sign out
                    </button>
                </li>
            </ul>
        </section>

        <!-- Reference -->
        <section :class="card">
            <h2
                class="mb-1 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                API reference
            </h2>
            <p :class="[muted, 'mb-3']">
                Base URL <code class="font-mono">{{ apiBaseUrl }}</code
                >. Send the key as
                <code class="font-mono">Authorization: Bearer &lt;key&gt;</code>
                with <code class="font-mono">Accept: application/json</code>.
                The full reference, with request and response bodies, is the
                <a
                    :href="specUrl"
                    target="_blank"
                    rel="noopener"
                    class="underline underline-offset-4"
                    >OpenAPI spec</a
                >.
            </p>
            <table class="w-full text-left text-sm">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-2 pr-3 font-medium">Method</th>
                        <th class="py-2 pr-3 font-medium">Endpoint</th>
                        <th class="py-2 font-medium">Scope</th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-[#e3e3e0] font-mono text-xs dark:divide-[#3E3E3A]"
                >
                    <tr
                        v-for="endpoint in endpoints"
                        :key="`${endpoint.method} ${endpoint.uri}`"
                        class="text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <td class="py-1.5 pr-3">{{ endpoint.method }}</td>
                        <td class="py-1.5 pr-3">{{ endpoint.uri }}</td>
                        <td class="py-1.5">{{ endpoint.scope ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>

    <Modal
        :show="revoking !== null"
        title="Revoke this API key?"
        @close="revoking = null"
    >
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Apps using "{{ revoking?.name }}" stop working at once. This can't
            be undone.
        </p>
        <div class="mt-6 flex justify-end gap-3">
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium dark:border-[#3E3E3A]"
                @click="revoking = null"
            >
                Keep it
            </button>
            <button
                type="button"
                class="rounded-md bg-[#f53003] px-4 py-2 text-sm font-medium text-white hover:bg-[#d92a02]"
                @click="revoke"
            >
                Revoke
            </button>
        </div>
    </Modal>
</template>
