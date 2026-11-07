<script setup lang="ts">
import {
    payWithCashfree,
    payWithRazorpay,
    type GatewayOrder,
    type GatewayPaymentResult,
} from '@/lib/paymentGatewayCheckout';
import PaymentLinkPageController from '@/actions/App/Http/Controllers/PaymentLinkPageController';
import { usePropertyTime } from '@/lib/usePropertyTime';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** Link times arrive in UTC and are shown in the property's timezone (the `timezone` prop). */
const propertyTime = usePropertyTime();

const props = defineProps<{
    property: { name: string };
    link: {
        token: string;
        amount: number;
        currency: string;
        status: 'pending' | 'paid' | 'expired' | 'cancelled';
        status_label: string;
        expires_at: string | null;
        paid_at: string | null;
        email: string | null;
        mobile: string | null;
        /** Set when a pending link can't be paid, e.g. the booking no longer has an outstanding amount. */
        unpayable_reason: string | null;
    };
    booking: {
        booking_ref_id: string;
        check_in: string;
        check_out: string;
        nights: number;
        guest_name: string | null;
        room_lines: string[];
    };
    gateways: { id: string; name: string }[];
}>();

const selectedGateway = ref(props.gateways[0]?.id ?? '');
const processing = ref(false);
const errorMessage = ref('');
const receiptNumber = ref<string | null>(null);

const amountLabel = computed(
    () =>
        `${props.link.currency} ${props.link.amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
);
const isPaid = computed(
    () => props.link.status === 'paid' || receiptNumber.value !== null,
);

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function postJson<T>(
    url: string,
    body: Record<string, unknown>,
): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': getXsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok || data.success === false) {
        const firstError = data.errors
            ? (Object.values(data.errors)[0] as string[] | undefined)?.[0]
            : undefined;
        throw new Error(
            firstError ||
                data.message ||
                'Something went wrong. Please try again.',
        );
    }

    return data as T;
}

async function pay(): Promise<void> {
    if (!selectedGateway.value || processing.value) {
        return;
    }

    processing.value = true;
    errorMessage.value = '';

    try {
        const gateway = selectedGateway.value;
        const order = await postJson<GatewayOrder>(
            PaymentLinkPageController.initiate(props.link.token).url,
            { payment_gateway: gateway },
        );

        let payment: GatewayPaymentResult | null;

        if (gateway === 'razorpay') {
            payment = await payWithRazorpay(order, {
                name: props.property.name,
                description: `Payment for booking ${props.booking.booking_ref_id}`,
                prefill: {
                    name: props.booking.guest_name,
                    email: props.link.email,
                    contact: props.link.mobile,
                },
            });
        } else if (gateway === 'cashfree') {
            payment = await payWithCashfree(order);
        } else {
            // Test gateway: no payment window, the payment is simulated.
            payment = {
                transaction_id: `pay_test_${Date.now()}`,
                order_id: order.order_id,
            };
        }

        if (!payment) {
            return;
        }

        const result = await postJson<{ receipt_number: string | null }>(
            PaymentLinkPageController.confirm(props.link.token).url,
            {
                payment_gateway: gateway,
                ...payment,
            },
        );

        receiptNumber.value = result.receipt_number ?? '';
        router.reload({ only: ['link'] });
    } catch (error) {
        errorMessage.value =
            error instanceof Error
                ? error.message
                : 'Something went wrong. Please try again.';
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Head :title="`Pay ${amountLabel} — ${property.name}`" />

    <div
        class="flex min-h-screen items-start justify-center bg-[#f5f5f4] px-4 py-10 text-[#1b1b18]"
    >
        <div
            class="w-full max-w-md overflow-hidden rounded-lg border border-[#e3e3e0] bg-white shadow-sm"
        >
            <div class="bg-[#2c343b] px-6 py-4 text-white">
                <p class="text-xs tracking-wide uppercase opacity-70">
                    Payment request from
                </p>
                <h1 class="text-lg font-semibold">{{ property.name }}</h1>
            </div>

            <div class="flex flex-col gap-4 px-6 py-5">
                <div class="text-center">
                    <p class="text-sm text-[#706f6c]">Amount to pay</p>
                    <p class="text-3xl font-bold">{{ amountLabel }}</p>
                </div>

                <dl
                    class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 rounded-md bg-[#f5f5f4] p-4 text-sm"
                >
                    <dt class="text-[#706f6c]">Booking</dt>
                    <dd class="font-medium">{{ booking.booking_ref_id }}</dd>
                    <template v-if="booking.guest_name">
                        <dt class="text-[#706f6c]">Guest</dt>
                        <dd class="font-medium">{{ booking.guest_name }}</dd>
                    </template>
                    <dt class="text-[#706f6c]">Stay</dt>
                    <dd class="font-medium">
                        {{ booking.check_in }} – {{ booking.check_out }} ({{
                            booking.nights
                        }}
                        night{{ booking.nights === 1 ? '' : 's' }})
                    </dd>
                    <dt class="text-[#706f6c]">Rooms</dt>
                    <dd class="font-medium">
                        <span
                            v-for="line in booking.room_lines"
                            :key="line"
                            class="block"
                            >{{ line }}</span
                        >
                    </dd>
                </dl>

                <div
                    v-if="isPaid"
                    class="rounded-md border border-emerald-200 bg-emerald-50 p-4 text-center text-sm text-emerald-800"
                >
                    <p class="font-semibold">Payment received — thank you!</p>
                    <p v-if="receiptNumber">Receipt {{ receiptNumber }}</p>
                    <p v-else-if="link.paid_at">
                        Paid on {{ propertyTime.dateTime(link.paid_at) }}
                    </p>
                </div>

                <div
                    v-else-if="
                        link.status !== 'pending' || link.unpayable_reason
                    "
                    class="rounded-md border border-amber-200 bg-amber-50 p-4 text-center text-sm text-amber-800"
                >
                    <template v-if="link.unpayable_reason">{{
                        link.unpayable_reason
                    }}</template>
                    <template v-else
                        >This payment link has
                        {{
                            link.status === 'expired'
                                ? 'expired'
                                : 'been cancelled'
                        }}.</template
                    >
                    Please contact {{ property.name }} if you think this is a
                    mistake.
                </div>

                <template v-else>
                    <fieldset
                        v-if="gateways.length"
                        class="flex flex-col gap-2"
                    >
                        <legend class="mb-1 text-sm font-semibold">
                            Pay with
                        </legend>
                        <label
                            v-for="gateway in gateways"
                            :key="gateway.id"
                            class="flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2.5 text-sm"
                            :class="
                                selectedGateway === gateway.id
                                    ? 'border-[#4a7c59] bg-[#f2f7f4]'
                                    : 'border-[#e3e3e0]'
                            "
                        >
                            <input
                                v-model="selectedGateway"
                                type="radio"
                                name="gateway"
                                :value="gateway.id"
                                class="text-[#4a7c59] focus:ring-[#4a7c59]"
                            />
                            {{ gateway.name }}
                        </label>
                    </fieldset>
                    <p v-else class="text-sm text-[#706f6c]">
                        Online payment isn't available right now. Please contact
                        {{ property.name }}.
                    </p>

                    <p
                        v-if="errorMessage"
                        class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
                    >
                        {{ errorMessage }}
                    </p>

                    <button
                        v-if="gateways.length"
                        type="button"
                        :disabled="processing || !selectedGateway"
                        class="rounded-md bg-[#4a7c59] px-4 py-3 text-sm font-semibold text-white hover:bg-[#3d6a4b] disabled:opacity-50"
                        @click="pay"
                    >
                        {{ processing ? 'Processing…' : `Pay ${amountLabel}` }}
                    </button>

                    <p
                        v-if="link.expires_at"
                        class="text-center text-xs text-[#706f6c]"
                    >
                        This link is valid until
                        {{ propertyTime.dateTime(link.expires_at) }} ({{
                            propertyTime.timeZone.value
                        }}).
                    </p>
                </template>
            </div>
        </div>
    </div>
</template>
