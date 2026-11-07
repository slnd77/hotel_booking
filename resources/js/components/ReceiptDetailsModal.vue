<script setup lang="ts">
import { useCurrency } from '@/lib/useCurrency';
import { usePropertyTime } from '@/lib/usePropertyTime';
import { computed, onBeforeUnmount, onMounted } from 'vue';

/** Times arrive in UTC and are shown in the property's timezone. */
const propertyTime = usePropertyTime();

const { money } = useCurrency();

export type ReceiptDetails = {
    id?: number;
    receipt_number?: string | null;
    voucher_number?: string | null;
    booking_id?: number | null;
    property_name?: string | null;
    guest_name?: string | null;
    payment_mode?: string | null;
    paid_by?: string | null;
    status?: string | null;
    total_txn_amount?: number | string | null;
    tagged_amount?: number | string | null;
    untagged_amount?: number | string | null;
    payment_creation_date?: string | null;
    payment_transaction_date?: string | null;
    collected_by_staff?: string | null;
    booking_source?: string | null;
    payment_ref_id?: string | null;
    pg_txn_id?: string | null;
    bank?: string | null;
    card_name?: string | null;
    card_number?: string | null;
    cancellation_reason?: string | null;
    notes?: string | null;
};

const props = defineProps<{
    show: boolean;
    receipt: ReceiptDetails | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape' && props.show) {
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

function formatCurrency(val?: number | string | null): string {
    return money(val);
}

const statusBadgeClass = computed(() => {
    const s = (props.receipt?.status || '').toLowerCase();
    if (s === 'received') {
        return 'bg-emerald-50 text-emerald-700 border-emerald-300 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800';
    }
    if (s === 'cancelled') {
        return 'bg-red-50 text-red-700 border-red-300 dark:bg-red-950/40 dark:text-red-400 dark:border-red-800';
    }
    return 'bg-amber-50 text-amber-700 border-amber-300 dark:bg-amber-950/40 dark:text-amber-400 dark:border-amber-800';
});

const bankCardDetails = computed(() => {
    if (!props.receipt) return null;
    const parts = [
        props.receipt.bank,
        props.receipt.card_name,
        props.receipt.card_number,
    ].filter(Boolean);
    return parts.length ? parts.join(' • ') : null;
});

const referenceId = computed(() => {
    if (!props.receipt) return null;
    return props.receipt.pg_txn_id || props.receipt.payment_ref_id || null;
});

function printReceipt(): void {
    window.print();
}
</script>

<template>
    <div
        v-if="show && receipt"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs"
        @click.self="emit('close')"
    >
        <div
            class="relative flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg border border-[#e3e3e0] bg-white shadow-2xl dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <!-- Header -->
            <div
                class="flex items-center justify-between border-b border-[#e3e3e0] bg-[#fbfbf9] px-6 py-4 dark:border-[#3E3E3A] dark:bg-[#1c1c1b]"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-[#0284c7]/10 text-[#0284c7]"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                            />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3
                                class="font-display text-base font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                Receipt Details
                            </h3>
                            <span
                                v-if="receipt.status"
                                :class="statusBadgeClass"
                                class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold tracking-wide uppercase"
                            >
                                {{ receipt.status }}
                            </span>
                        </div>
                        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                            {{
                                receipt.property_name ||
                                'Payment Receipt Voucher'
                            }}
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    class="rounded-md p-1.5 text-[#706f6c] transition hover:bg-[#e3e3e0] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:bg-[#2b2b28] dark:hover:text-[#EDEDEC]"
                    @click="emit('close')"
                >
                    <svg
                        class="h-5 w-5"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                    >
                        <path
                            fill-rule="evenodd"
                            d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </button>
            </div>

            <!-- Content Area (Scrollable) -->
            <div class="space-y-5 overflow-y-auto p-6 text-sm">
                <!-- Top Summary Card -->
                <div
                    class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-[#e3e3e0] bg-[#fbfbf9] p-4 dark:border-[#2b2b28] dark:bg-[#1c1c1b]"
                >
                    <div>
                        <div
                            class="text-[11px] font-semibold tracking-wider text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Receipt Number
                        </div>
                        <div
                            class="font-mono text-base font-bold text-[#0284c7]"
                        >
                            {{ receipt.receipt_number || '—' }}
                        </div>
                        <div
                            class="mt-0.5 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Created:
                            {{
                                propertyTime.dateTime(
                                    receipt.payment_creation_date,
                                )
                            }}
                        </div>
                    </div>

                    <div class="text-right">
                        <div
                            class="text-[11px] font-semibold tracking-wider text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Total Paid
                        </div>
                        <div
                            class="text-xl font-bold text-[#1b1b18] dark:text-white"
                        >
                            {{ formatCurrency(receipt.total_txn_amount) }}
                        </div>
                        <div
                            class="mt-0.5 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Date:
                            {{
                                propertyTime.dateTime(
                                    receipt.payment_transaction_date ||
                                        receipt.payment_creation_date,
                                )
                            }}
                        </div>
                    </div>
                </div>

                <!-- Cancelled Notice if applicable -->
                <div
                    v-if="receipt.status?.toLowerCase() === 'cancelled'"
                    class="rounded-md border border-red-300 bg-red-50 p-3 text-xs text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300"
                >
                    <div class="font-bold">Payment Cancelled</div>
                    <div class="mt-0.5">
                        Reason:
                        {{
                            receipt.cancellation_reason &&
                            receipt.cancellation_reason !== '—'
                                ? receipt.cancellation_reason
                                : 'Cancelled by staff'
                        }}
                    </div>
                </div>

                <!-- Two-Column Attributes Grid -->
                <div
                    class="grid grid-cols-1 gap-x-6 gap-y-3 rounded-lg border border-[#e3e3e0] p-4 text-xs md:grid-cols-2 dark:border-[#2b2b28]"
                >
                    <div
                        class="flex justify-between border-b border-dashed border-gray-200 pb-2 dark:border-[#2b2b28]"
                    >
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Voucher / Booking Ref:</span
                        >
                        <span
                            class="font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >{{ receipt.voucher_number || '—' }}</span
                        >
                    </div>

                    <div
                        class="flex justify-between border-b border-dashed border-gray-200 pb-2 dark:border-[#2b2b28]"
                    >
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Payment Mode:</span
                        >
                        <span
                            class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >{{ receipt.payment_mode || '—' }}</span
                        >
                    </div>

                    <div
                        class="flex justify-between border-b border-dashed border-gray-200 pb-2 dark:border-[#2b2b28]"
                    >
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Guest Name:</span
                        >
                        <span
                            class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >{{ receipt.guest_name || '—' }}</span
                        >
                    </div>

                    <div
                        class="flex justify-between border-b border-dashed border-gray-200 pb-2 dark:border-[#2b2b28]"
                    >
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Paid By:</span
                        >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            receipt.paid_by || 'Guest'
                        }}</span>
                    </div>

                    <div
                        class="flex justify-between border-b border-dashed border-gray-200 pb-2 dark:border-[#2b2b28]"
                    >
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Booking Source:</span
                        >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            receipt.booking_source || 'Direct'
                        }}</span>
                    </div>

                    <div
                        class="flex justify-between border-b border-dashed border-gray-200 pb-2 dark:border-[#2b2b28]"
                    >
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Collected By Staff:</span
                        >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            receipt.collected_by_staff || 'Staff'
                        }}</span>
                    </div>

                    <div
                        v-if="referenceId"
                        class="flex justify-between border-b border-dashed border-gray-200 pb-2 dark:border-[#2b2b28]"
                    >
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Reference / Txn ID:</span
                        >
                        <span
                            class="font-mono text-[#1b1b18] dark:text-[#EDEDEC]"
                            >{{ referenceId }}</span
                        >
                    </div>

                    <div
                        v-if="bankCardDetails"
                        class="flex justify-between border-b border-dashed border-gray-200 pb-2 dark:border-[#2b2b28]"
                    >
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Bank / Card:</span
                        >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            bankCardDetails
                        }}</span>
                    </div>
                </div>

                <!-- Allocations Breakdown Table -->
                <div>
                    <h4
                        class="mb-2 text-xs font-bold tracking-wider text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Payment Allocation
                    </h4>
                    <div
                        class="overflow-hidden rounded-md border border-[#e3e3e0] dark:border-[#2b2b28]"
                    >
                        <table
                            class="min-w-full divide-y divide-[#e3e3e0] text-xs dark:divide-[#2b2b28]"
                        >
                            <thead
                                class="bg-[#fbfbf9] text-left font-semibold text-[#706f6c] dark:bg-[#1c1c1b] dark:text-[#A1A09A]"
                            >
                                <tr>
                                    <th class="px-3 py-2">Item / Category</th>
                                    <th class="px-3 py-2 text-center">
                                        Status
                                    </th>
                                    <th class="px-3 py-2 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-[#e3e3e0] dark:divide-[#2b2b28]"
                            >
                                <tr>
                                    <td
                                        class="px-3 py-2 text-[#1b1b18] dark:text-[#EDEDEC]"
                                    >
                                        Room Charges (Reservation #{{
                                            receipt.voucher_number ||
                                            receipt.booking_id ||
                                            ''
                                        }})
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <span
                                            class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-400"
                                        >
                                            Tagged
                                        </span>
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                    >
                                        {{
                                            formatCurrency(
                                                receipt.tagged_amount ??
                                                    receipt.total_txn_amount,
                                            )
                                        }}
                                    </td>
                                </tr>
                                <tr
                                    v-if="
                                        Number(receipt.untagged_amount || 0) > 0
                                    "
                                >
                                    <td
                                        class="px-3 py-2 text-[#1b1b18] dark:text-[#EDEDEC]"
                                    >
                                        Untagged Amount
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <span
                                            class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 dark:bg-amber-950/40 dark:text-amber-400"
                                        >
                                            Untagged
                                        </span>
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right font-medium text-amber-700 dark:text-amber-400"
                                    >
                                        {{
                                            formatCurrency(
                                                receipt.untagged_amount,
                                            )
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot
                                class="border-t border-[#e3e3e0] bg-[#fbfbf9] font-bold dark:border-[#2b2b28] dark:bg-[#1c1c1b]"
                            >
                                <tr>
                                    <td
                                        colspan="2"
                                        class="px-3 py-2 text-right text-[#1b1b18] dark:text-[#EDEDEC]"
                                    >
                                        Total Transaction Amount:
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right text-sm text-[#0284c7]"
                                    >
                                        {{
                                            formatCurrency(
                                                receipt.total_txn_amount,
                                            )
                                        }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Notes Section if present -->
                <div
                    v-if="receipt.notes"
                    class="rounded-md border border-[#e3e3e0] bg-[#fbfbf9] p-3 text-xs dark:border-[#2b2b28] dark:bg-[#1c1c1b]"
                >
                    <div
                        class="mb-1 text-[10px] font-semibold tracking-wider text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Notes / Remarks
                    </div>
                    <div
                        class="whitespace-pre-line text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        {{ receipt.notes }}
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div
                class="flex items-center justify-between border-t border-[#e3e3e0] bg-[#fbfbf9] px-6 py-3 dark:border-[#3E3E3A] dark:bg-[#1c1c1b]"
            >
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded border border-[#0284c7] px-3 py-1.5 text-xs font-semibold text-[#0284c7] transition hover:bg-[#0284c7]/10"
                    @click="printReceipt"
                >
                    <svg
                        class="h-4 w-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"
                        />
                    </svg>
                    Print Receipt
                </button>

                <button
                    type="button"
                    class="rounded bg-gray-200 px-4 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-300 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                    @click="emit('close')"
                >
                    Close
                </button>
            </div>
        </div>

        <!-- Printable view that prints cleanly -->
        <div
            class="fixed inset-0 z-50 hidden bg-white p-8 text-black print:block"
        >
            <div class="mx-auto max-w-xl border p-6">
                <div class="mb-1 text-center text-xl font-bold uppercase">
                    {{ receipt.property_name || 'Hotel Receipt' }}
                </div>
                <div
                    class="mb-4 border-b pb-2 text-center text-xs font-semibold tracking-wider text-gray-600"
                >
                    PAYMENT RECEIPT
                </div>
                <div class="space-y-2 text-xs">
                    <div class="flex justify-between border-b pb-1">
                        <span>Receipt No:</span>
                        <span class="font-bold">{{
                            receipt.receipt_number || '—'
                        }}</span>
                    </div>
                    <div class="flex justify-between border-b pb-1">
                        <span>Date:</span>
                        <span>{{
                            propertyTime.dateTime(
                                receipt.payment_transaction_date ||
                                    receipt.payment_creation_date,
                            )
                        }}</span>
                    </div>
                    <div class="flex justify-between border-b pb-1">
                        <span>Booking Ref / Voucher:</span>
                        <span>{{ receipt.voucher_number || '—' }}</span>
                    </div>
                    <div class="flex justify-between border-b pb-1">
                        <span>Guest Name:</span>
                        <span class="font-bold">{{
                            receipt.guest_name || '—'
                        }}</span>
                    </div>
                    <div class="flex justify-between border-b pb-1">
                        <span>Payment Mode:</span>
                        <span>{{ receipt.payment_mode || '—' }}</span>
                    </div>
                    <div class="flex justify-between border-b pb-1">
                        <span>Status:</span>
                        <span class="font-bold">{{
                            receipt.status || 'Received'
                        }}</span>
                    </div>
                    <div class="flex justify-between border-b pb-1">
                        <span>Collected By:</span>
                        <span>{{ receipt.collected_by_staff || 'Staff' }}</span>
                    </div>
                    <div
                        class="flex justify-between border-t pt-2 text-sm font-bold"
                    >
                        <span>Total Paid:</span>
                        <span>{{
                            formatCurrency(receipt.total_txn_amount)
                        }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
