<script setup lang="ts">
import { reactive, watch } from 'vue';

export type PaymentAdvancedSearchFilters = {
    q?: string;
    voucher_number: string;
    receipt_number: string;
    payment_ref_id: string;
    pg_txn_id: string;
    guest_name: string;
    status: string;
    payment_mode: string;
    booking_source: string;
    date_from: string;
    date_to: string;
    created_from: string;
    created_to: string;
};

const props = defineProps<{
    show: boolean;
    filters: Record<string, string | number | null | undefined>;
}>();

const emit = defineEmits<{
    close: [];
    search: [filters: PaymentAdvancedSearchFilters];
}>();

function emptyFilters(): PaymentAdvancedSearchFilters {
    return {
        voucher_number: '',
        receipt_number: '',
        payment_ref_id: '',
        pg_txn_id: '',
        guest_name: '',
        status: '',
        payment_mode: '',
        booking_source: '',
        date_from: '',
        date_to: '',
        created_from: '',
        created_to: '',
    };
}

const form = reactive<PaymentAdvancedSearchFilters>(emptyFilters());

watch(
    () => props.show,
    (show) => {
        if (!show) {
            return;
        }

        const seeded = emptyFilters();
        (Object.keys(seeded) as (keyof PaymentAdvancedSearchFilters)[]).forEach(
            (key) => {
                const value = props.filters[key];
                seeded[key] =
                    value === undefined || value === null
                        ? (seeded[key] ?? '')
                        : String(value);
            },
        );

        Object.assign(form, seeded);
    },
    { immediate: true },
);

const statusOptions = [
    { value: '', label: 'All Statuses' },
    { value: 'Received', label: 'Received' },
    { value: 'Pending', label: 'Pending' },
    { value: 'Cancelled', label: 'Cancelled' },
];

const paymentModeOptions = [
    { value: '', label: 'All Modes' },
    { value: 'E-Collect', label: 'E-Collect' },
    { value: 'NEFT', label: 'NEFT' },
    { value: 'IMPS', label: 'IMPS' },
    { value: 'RTGS', label: 'RTGS' },
    { value: 'UPI', label: 'UPI' },
    { value: 'Cash', label: 'Cash' },
    { value: 'Credit Card', label: 'Credit Card' },
    { value: 'Debit Card', label: 'Debit Card' },
    { value: 'Razorpay', label: 'Razorpay' },
    { value: 'Cashfree', label: 'Cashfree' },
    { value: 'Test Gateway', label: 'Test Gateway' },
];

const bookingSourceOptions = [
    { value: '', label: 'All Sources' },
    { value: 'Direct', label: 'Direct' },
    { value: 'Booking Engine', label: 'Booking Engine' },
    { value: 'OTA', label: 'OTA' },
    { value: 'Corporate', label: 'Corporate' },
    { value: 'Travel Agent', label: 'Travel Agent' },
];

function reset(): void {
    Object.assign(form, emptyFilters());
}

function search(): void {
    emit('search', { ...form });
}

function close(): void {
    emit('close');
}

const fieldClass =
    'block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]';
const labelClass =
    'flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]';
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4"
        @click.self="close"
    >
        <div
            class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <div
                class="flex items-center justify-between bg-[#1b1b18] px-6 py-3"
            >
                <h2 class="font-display text-base font-semibold text-white">
                    Payment Advanced Search
                </h2>
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="text-sm font-medium text-white/80 hover:text-white"
                        @click="reset"
                    >
                        Reset
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-black bg-[#f53003] px-4 py-1.5 text-sm font-semibold text-white hover:bg-[#d92a02]"
                        @click="search"
                    >
                        Search
                    </button>
                    <button
                        type="button"
                        class="text-white/80 hover:text-white"
                        @click="close"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>
            </div>

            <div
                class="grid grid-cols-1 gap-4 overflow-y-auto px-6 py-5 sm:grid-cols-3"
            >
                <label :class="labelClass">
                    Voucher / Booking Ref
                    <input
                        v-model="form.voucher_number"
                        type="text"
                        :class="fieldClass"
                        placeholder="e.g. CON0026..."
                    />
                </label>
                <label :class="labelClass">
                    Receipt Number
                    <input
                        v-model="form.receipt_number"
                        type="text"
                        :class="fieldClass"
                        placeholder="e.g. RCPT/..."
                    />
                </label>
                <label :class="labelClass">
                    Guest Name has
                    <input
                        v-model="form.guest_name"
                        type="text"
                        :class="fieldClass"
                    />
                </label>

                <label :class="labelClass">
                    Payment Ref ID
                    <input
                        v-model="form.payment_ref_id"
                        type="text"
                        :class="fieldClass"
                    />
                </label>
                <label :class="labelClass">
                    PG Txn ID
                    <input
                        v-model="form.pg_txn_id"
                        type="text"
                        :class="fieldClass"
                    />
                </label>
                <label :class="labelClass">
                    Status
                    <select v-model="form.status" :class="fieldClass">
                        <option
                            v-for="option in statusOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <label :class="labelClass">
                    Payment Mode
                    <select v-model="form.payment_mode" :class="fieldClass">
                        <option
                            v-for="option in paymentModeOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <label :class="labelClass">
                    Booking Source
                    <select v-model="form.booking_source" :class="fieldClass">
                        <option
                            v-for="option in bookingSourceOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <div class="hidden sm:block"></div>

                <label :class="labelClass">
                    Transaction Date From
                    <input
                        v-model="form.date_from"
                        type="date"
                        :class="fieldClass"
                    />
                </label>
                <label :class="labelClass">
                    Transaction Date To
                    <input
                        v-model="form.date_to"
                        type="date"
                        :class="fieldClass"
                    />
                </label>
                <div class="hidden sm:block"></div>

                <label :class="labelClass">
                    Creation Date From
                    <input
                        v-model="form.created_from"
                        type="date"
                        :class="fieldClass"
                    />
                </label>
                <label :class="labelClass">
                    Creation Date To
                    <input
                        v-model="form.created_to"
                        type="date"
                        :class="fieldClass"
                    />
                </label>
            </div>
        </div>
    </div>
</template>
