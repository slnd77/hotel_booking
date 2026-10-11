<script setup lang="ts">
import { usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

export type WebCheckinSummary = {
    enabled: boolean;
    url: string | null;
    status: 'pending' | 'completed' | null;
    sent_at: string | null;
    submitted_at: string | null;
    details: Record<string, string | null> | null;
    has_id_document: boolean;
    has_signature: boolean;
    closed_reason: string | null;
};

const props = defineProps<{
    propertyId: number;
    bookingId: number;
    checkin: WebCheckinSummary;
    guestEmail: string | null;
}>();

const propertyTime = usePropertyTime();
const url = ref(props.checkin.url);
const email = ref(props.guestEmail ?? '');
const message = ref('');
const error = ref('');
const sending = ref(false);
const detailsOpen = ref(false);

const fields: [string, string][] = [
    ['first_name', 'First name'],
    ['last_name', 'Last name'],
    ['email', 'E-mail'],
    ['phone', 'Phone'],
    ['nationality', 'Nationality'],
    ['address', 'Address'],
    ['postal_code', 'Postal code'],
    ['id_type', 'ID type'],
    ['id_number', 'ID number'],
    ['arrival_time', 'Arrival time'],
    ['special_requests', 'Requests'],
];

const routeArgs = () => ({
    property: props.propertyId,
    booking: props.bookingId,
});

async function copyLink(): Promise<void> {
    error.value = '';
    message.value = '';

    try {
        if (!url.value) {
            const response = await fetch(
                admin.bookings.webCheckin.link(routeArgs()).url,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );
            const body = await response.json();

            if (!response.ok) {
                throw new Error(body.message ?? 'The link could not be made.');
            }

            url.value = body.url;
        }

        await navigator.clipboard.writeText(url.value as string);
        message.value = 'Link copied.';
    } catch (exception) {
        error.value =
            exception instanceof Error
                ? exception.message
                : 'The link could not be copied.';
    }
}

function send(): void {
    error.value = '';
    message.value = '';
    router.post(
        admin.bookings.webCheckin.send(routeArgs()).url,
        { email: email.value || null },
        {
            preserveScroll: true,
            onStart: () => (sending.value = true),
            onFinish: () => (sending.value = false),
            onSuccess: () =>
                (message.value = `The link will be e-mailed to ${email.value || 'the guest'}.`),
            onError: (errors) =>
                (error.value =
                    errors.email ??
                    errors.web_checkin ??
                    'The link could not be sent.'),
        },
    );
}
</script>

<template>
    <div class="p-4">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]">
                Web Check-in
            </h2>
            <span
                v-if="checkin.status === 'completed'"
                class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
            >
                Completed {{ propertyTime.dateTime(checkin.submitted_at) }}
            </span>
            <span
                v-else-if="checkin.sent_at"
                class="rounded bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-300"
            >
                Link sent {{ propertyTime.dateTime(checkin.sent_at) }}
            </span>
        </div>

        <p v-if="!checkin.enabled" class="text-xs text-gray-500">
            Web check-in is off. Turn it on under Property → Web Check-in.
        </p>

        <template v-else>
            <p
                v-if="checkin.closed_reason && checkin.status !== 'completed'"
                class="mb-2 text-xs text-gray-500"
            >
                {{ checkin.closed_reason }}
            </p>

            <div class="flex flex-wrap items-end gap-2">
                <label
                    class="flex min-w-0 flex-1 flex-col gap-1 text-xs text-gray-500"
                >
                    Guest e-mail
                    <input
                        v-model="email"
                        type="email"
                        placeholder="guest@example.com"
                        class="rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-800 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
                    />
                </label>
                <button
                    type="button"
                    :disabled="sending"
                    class="rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-bold text-gray-800 disabled:opacity-40 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
                    @click="send"
                >
                    {{ checkin.sent_at ? 'SEND AGAIN' : 'SEND LINK' }}
                </button>
                <button
                    type="button"
                    class="rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-bold text-gray-800 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
                    @click="copyLink"
                >
                    COPY LINK
                </button>
            </div>
            <p
                v-if="url"
                class="mt-1 text-[11px] break-all text-gray-500 select-all"
            >
                {{ url }}
            </p>
            <p
                v-if="message"
                class="mt-1 text-xs text-emerald-700 dark:text-emerald-400"
            >
                {{ message }}
            </p>
            <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>

            <div v-if="checkin.status === 'completed'" class="mt-3">
                <button
                    type="button"
                    class="text-xs font-medium text-[#f53003] hover:underline dark:text-[#FF4433]"
                    @click="detailsOpen = !detailsOpen"
                >
                    {{ detailsOpen ? 'Hide' : 'View' }} what the guest submitted
                </button>
                <div v-if="detailsOpen" class="mt-2 grid gap-3 sm:grid-cols-2">
                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs"
                    >
                        <template v-for="[key, label] in fields" :key="key">
                            <template v-if="checkin.details?.[key]">
                                <dt class="text-gray-500">{{ label }}</dt>
                                <dd class="text-gray-800 dark:text-gray-200">
                                    {{ checkin.details[key] }}
                                </dd>
                            </template>
                        </template>
                    </dl>
                    <div class="flex flex-col gap-2 text-xs">
                        <a
                            v-if="checkin.has_id_document"
                            :href="
                                admin.bookings.webCheckin.document(routeArgs())
                                    .url
                            "
                            target="_blank"
                            rel="noopener"
                            class="font-medium text-[#f53003] hover:underline dark:text-[#FF4433]"
                            >Open ID document</a
                        >
                        <div v-if="checkin.has_signature">
                            <span class="text-gray-500">Signature</span>
                            <img
                                :src="
                                    admin.bookings.webCheckin.signature(
                                        routeArgs(),
                                    ).url
                                "
                                alt="Guest signature"
                                class="mt-1 max-h-24 rounded border border-gray-200 bg-white p-1 dark:border-[#3E3E3A]"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
