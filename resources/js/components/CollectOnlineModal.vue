<script setup lang="ts">
import admin from '@/routes/admin';
import { router } from '@inertiajs/vue3';
import { useCurrency } from '@/lib/useCurrency';
import { reactive, ref, watch } from 'vue';

const { code } = useCurrency();

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

const props = defineProps<{
    show: boolean;
    property: { id: number; ulid?: string; name: string };
    booking: {
        id: number;
        booking_ref_id: string;
        outstanding: number;
        room_bill_to?: string;
    };
    guest?: {
        name?: string;
        email?: string | null;
        phone?: string | null;
        mobile?: string | null;
    } | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = reactive({
    collect_for: 'Guest',
    amount: 0,
    email: '',
    mobile: '',
});

const isSubmitting = ref(false);
const successMessage = ref('');
const errorMessage = ref('');
const generatedPaymentLink = ref('');
const linkCopied = ref(false);

watch(
    () => props.show,
    (show) => {
        if (!show) {
            successMessage.value = '';
            errorMessage.value = '';
            generatedPaymentLink.value = '';
            linkCopied.value = false;
            return;
        }

        form.collect_for =
            props.booking.room_bill_to === 'to_company'
                ? 'Guest Company'
                : 'Guest';
        form.amount = Math.max(0, Number(props.booking.outstanding || 0));
        // Pre-fill the guest's e-mail when the booking has one ("—" is the booking page's no-guest placeholder).
        form.email =
            props.guest?.email && props.guest.email !== '—'
                ? props.guest.email
                : '';
        form.mobile = props.guest?.phone || props.guest?.mobile || '';
        successMessage.value = '';
        errorMessage.value = '';
        generatedPaymentLink.value = '';
        linkCopied.value = false;
    },
    { immediate: true },
);

/**
 * Creates a payment link on the server. The link only carries a random token —
 * the amount is stored with it and never appears in the URL.
 */
async function createPaymentLink(send: boolean): Promise<void> {
    if (form.amount <= 0) {
        errorMessage.value = 'Please enter a valid amount greater than 0.';
        return;
    }

    isSubmitting.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const response = await fetch(
            admin.bookings.paymentLinks.store({
                property: props.property.id,
                booking: props.booking.id,
            }).url,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
                body: JSON.stringify({
                    collect_for: form.collect_for,
                    amount: form.amount,
                    email: form.email || null,
                    mobile: form.mobile || null,
                    send,
                }),
            },
        );

        const data = await response.json().catch(() => ({}));

        if (data.payment_link) {
            generatedPaymentLink.value = data.payment_link;
        }

        if (!response.ok) {
            const firstError = data.errors
                ? (Object.values(data.errors)[0] as string[] | undefined)?.[0]
                : undefined;
            throw new Error(
                firstError ||
                    data.message ||
                    'Could not create the payment link. Please try again.',
            );
        }

        successMessage.value = data.message || 'Payment link created.';

        if (send) {
            setTimeout(() => router.reload(), 1200);
        } else {
            copyToClipboard(data.payment_link);
        }
    } catch (err: any) {
        errorMessage.value =
            err.message ||
            'Could not create the payment link. Please try again.';
    } finally {
        isSubmitting.value = false;
    }
}

function onGetPaymentLink(): void {
    createPaymentLink(false);
}

function copyToClipboard(text: string): void {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text);
    } else {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
        } catch (err) {
            console.error('Unable to copy', err);
        }
        document.body.removeChild(textArea);
    }
    linkCopied.value = true;
    setTimeout(() => {
        linkCopied.value = false;
    }, 3000);
}

function onSendPaymentLink(): void {
    createPaymentLink(true);
}
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4 backdrop-blur-xs"
        @click.self="emit('close')"
    >
        <div
            class="relative w-full max-w-[420px] overflow-hidden rounded border border-gray-300 bg-white text-xs shadow-2xl dark:border-[#3E3E3A] dark:bg-[#1c1c1b]"
        >
            <!-- Modal Header -->
            <div
                class="flex items-center justify-between bg-[#2c343b] px-4 py-3 text-white"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="flex h-5 w-5 items-center justify-center rounded-full bg-[#ea580c] text-[11px] font-bold text-white italic shadow-xs"
                    >
                        e
                    </span>
                    <span class="text-sm font-semibold tracking-wide">
                        Collect Online
                    </span>
                </div>

                <div class="flex items-center gap-3 text-gray-300">
                    <span
                        class="cursor-pointer text-xs font-bold hover:text-white"
                        title="Collect Online payment link for reservation"
                    >
                        ?
                    </span>
                    <button
                        type="button"
                        class="text-sm transition hover:text-white"
                        @click="emit('close')"
                    >
                        ✕
                    </button>
                </div>
            </div>

            <!-- Modal Body -->
            <div class="space-y-4 p-6 text-[#333] dark:text-[#EDEDEC]">
                <!-- Success / Error Alerts -->
                <div
                    v-if="successMessage"
                    class="rounded border border-emerald-200 bg-emerald-50 p-2.5 text-xs text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300"
                >
                    {{ successMessage }}
                </div>
                <div
                    v-if="errorMessage"
                    class="rounded border border-red-200 bg-red-50 p-2.5 text-xs text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300"
                >
                    {{ errorMessage }}
                </div>

                <!-- Form Fields -->
                <div class="space-y-3.5">
                    <!-- Collect for -->
                    <div class="grid grid-cols-[120px_1fr] items-center gap-2">
                        <label class="font-medium text-[#555] dark:text-[#aaa]"
                            >Collect Online from</label
                        >
                        <select
                            v-model="form.collect_for"
                            class="w-full border-b border-gray-300 bg-transparent py-1 text-xs focus:border-[#ea580c] focus:outline-none dark:border-neutral-700"
                        >
                            <option value="Guest">Guest</option>
                            <option value="Guest Company">Guest Company</option>
                        </select>
                    </div>

                    <!-- Amount -->
                    <div class="grid grid-cols-[120px_1fr] items-center gap-2">
                        <label class="font-medium text-[#555] dark:text-[#aaa]"
                            >Amount</label
                        >
                        <div
                            class="flex items-center gap-1.5 border-b border-gray-300 py-1 dark:border-neutral-700"
                        >
                            <span class="text-xs text-gray-400 italic">{{
                                code
                            }}</span>
                            <input
                                v-model.number="form.amount"
                                type="number"
                                step="0.01"
                                min="0"
                                class="w-full border-none bg-transparent p-0 text-xs font-semibold text-[#1b1b18] focus:ring-0 focus:outline-none dark:text-white"
                            />
                        </div>
                    </div>

                    <!-- Email -->
                    <div class="grid grid-cols-[120px_1fr] items-center gap-2">
                        <label class="font-medium text-[#555] dark:text-[#aaa]"
                            >Email</label
                        >
                        <input
                            v-model="form.email"
                            type="email"
                            placeholder="Enter email"
                            class="w-full border-b border-gray-300 bg-transparent py-1 text-xs focus:border-[#ea580c] focus:outline-none dark:border-neutral-700"
                        />
                    </div>

                    <!-- Mobile -->
                    <div class="grid grid-cols-[120px_1fr] items-center gap-2">
                        <label class="font-medium text-[#555] dark:text-[#aaa]"
                            >Mobile</label
                        >
                        <input
                            v-model="form.mobile"
                            type="text"
                            placeholder="Enter mobile"
                            class="w-full border-b border-gray-300 bg-transparent py-1 text-xs focus:border-[#ea580c] focus:outline-none dark:border-neutral-700"
                        />
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="pt-3">
                    <div class="flex items-center">
                        <button
                            type="button"
                            :disabled="isSubmitting"
                            class="cursor-pointer rounded bg-[#2b353e] px-4 py-2 text-[11px] font-bold tracking-wider text-white uppercase transition hover:bg-[#1e262d] disabled:opacity-50"
                            @click="onSendPaymentLink"
                        >
                            <span v-if="isSubmitting">SENDING...</span>
                            <span v-else>SEND PAYMENT LINK</span>
                        </button>

                        <button
                            type="button"
                            class="ml-3 cursor-pointer text-xs font-bold text-[#0284c7] uppercase hover:underline"
                            @click="emit('close')"
                        >
                            CANCEL
                        </button>
                    </div>

                    <!-- Get Payment Link -->
                    <div class="mt-3.5">
                        <button
                            type="button"
                            :disabled="isSubmitting"
                            class="cursor-pointer text-xs font-medium text-[#ea580c] hover:underline disabled:opacity-50"
                            @click="onGetPaymentLink"
                        >
                            Get Payment Link
                        </button>

                        <!-- Generated Link Preview Box -->
                        <div
                            v-if="generatedPaymentLink"
                            class="mt-2 rounded border border-gray-200 bg-gray-50 p-2.5 dark:border-neutral-800 dark:bg-neutral-900"
                        >
                            <div
                                class="mb-1 flex items-center justify-between text-[11px] text-gray-500"
                            >
                                <span>Payment Link:</span>
                                <span
                                    v-if="linkCopied"
                                    class="font-bold text-emerald-600"
                                    >✓ Copied to clipboard!</span
                                >
                            </div>
                            <div class="flex items-center gap-2">
                                <input
                                    type="text"
                                    readonly
                                    :value="generatedPaymentLink"
                                    class="w-full rounded border border-gray-300 bg-white px-2 py-1 text-[11px] select-all dark:border-neutral-700 dark:bg-neutral-800"
                                />
                                <button
                                    type="button"
                                    class="shrink-0 cursor-pointer rounded bg-[#ea580c] px-2.5 py-1 text-[11px] font-semibold text-white transition hover:bg-[#c2410c]"
                                    @click="
                                        copyToClipboard(generatedPaymentLink)
                                    "
                                >
                                    Copy
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
