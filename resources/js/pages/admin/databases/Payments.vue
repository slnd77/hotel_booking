<script setup lang="ts">
import AddPaymentModal from '@/components/AddPaymentModal.vue';
import PaymentAdvancedSearchModal, {
    type PaymentAdvancedSearchFilters,
} from '@/components/PaymentAdvancedSearchModal.vue';
import ReceiptDetailsModal from '@/components/ReceiptDetailsModal.vue';
import TableColumnSettingsModal, {
    type TableColumn,
} from '@/components/TableColumnSettingsModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useColumnVisibility } from '@/lib/useColumnVisibility';
import admin from '@/routes/admin';
import { Head, Link, router } from '@inertiajs/vue3';
import { useCurrency } from '@/lib/useCurrency';
import { usePropertyTime } from '@/lib/usePropertyTime';
import { computed, ref } from 'vue';

/** Times arrive in UTC and are shown in the property's timezone. */
const propertyTime = usePropertyTime();

const { amount } = useCurrency();

defineOptions({ layout: AdminLayout });

export type PaymentRow = {
    id: number;
    property_id?: number | null;
    property_name: string | null;
    property_abbreviation: string | null;
    type: string;
    payment_creation_date: string | null;
    payment_transaction_date: string | null;
    voucher_number: string | null;
    booking_id: number | null;
    receipt_number: string | null;
    status: string;
    payment_ref_id: string | null;
    pg_txn_id: string | null;
    ota_booking_ref_id: string | null;
    neft_ref_id: string | null;
    imps_ref_id: string | null;
    rtgs_ref_id: string | null;
    guest_name: string | null;
    payment_mode: string | null;
    card_number: string | null;
    total_txn_amount: number;
    tagged_amount?: number;
    untagged_amount?: number;
    pg_commission_amount?: number | null;
    collection_source: string | null;
    card_name: string | null;
    bank: string | null;
    booking_source: string | null;
    delete_status: string | null;
    cancellation_reason: string | null;
    collected_by_staff: string | null;
    notes: string | null;
    pg_pay_fields_edited: string | null;
    coupon: string | null;
    payee_name: string | null;
    document: string | null;
};

const columns: TableColumn[] = [
    { key: 'payment_creation_date', label: 'Payment Creation Date' },
    { key: 'payment_transaction_date', label: 'Payment Transaction Date' },
    { key: 'voucher_number', label: 'Voucher/Order No.' },
    { key: 'receipt_number', label: 'Receipt Number' },
    { key: 'property_name', label: 'Payment Received Property' },
    { key: 'status', label: 'Status' },
    { key: 'payment_ref_id', label: 'Payment Ref ID' },
    { key: 'pg_txn_id', label: 'PG Txn Id' },
    { key: 'ota_booking_ref_id', label: 'OTA Booking Ref ID' },
    { key: 'neft_ref_id', label: 'NEFT Ref ID' },
    { key: 'imps_ref_id', label: 'IMPS Ref ID' },
    { key: 'rtgs_ref_id', label: 'RTGS Ref ID' },
    { key: 'guest_name', label: 'Guest Name' },
    { key: 'payment_mode', label: 'Payment Mode' },
    { key: 'card_number', label: 'Card number' },
    { key: 'total_txn_amount', label: 'Total Txn Amount' },
    { key: 'tagged_amount', label: 'Tagged Amount' },
    { key: 'untagged_amount', label: 'Untagged Amount' },
    { key: 'pg_commission_amount', label: 'PG Commission' },
    { key: 'collection_source', label: 'Collection Source' },
    { key: 'card_name', label: 'Card Name' },
    { key: 'bank', label: 'Bank' },
    { key: 'booking_source', label: 'Booking Source' },
    { key: 'delete_status', label: 'Delete Status' },
    { key: 'cancellation_reason', label: 'Cancellation Reason' },
    { key: 'collected_by_staff', label: 'Collected By Staff' },
    { key: 'notes', label: 'Notes' },
    { key: 'pg_pay_fields_edited', label: 'PG Pay Fields Edited' },
    { key: 'cancel_action', label: 'Cancel' },
    { key: 'coupon', label: 'Coupon' },
    { key: 'payee_name', label: 'Payee Name' },
    { key: 'document', label: 'Document' },
    { key: 'activity', label: 'Activity' },
    { key: 'print_receipt', label: 'Print Receipt' },
];

const props = defineProps<{
    property?: {
        id: number;
        name: string;
        abbreviation: string;
        country_id: number | null;
    };
    properties?: { id: number; name: string; abbreviation: string }[];
    staff?: { id: number; name: string }[];
    currentUser?: { id: number; name: string };
    reservations?: {
        id: number;
        booking_ref_id: string;
        guest_name: string;
        guest_id?: number | null;
        total: number;
        outstanding: number;
        taxes: number;
        status: string;
    }[];
    countries?: { id: number; name: string; iso2: string; phonecode: string }[];
    /** The selected property's payment modes (Settings → Booking → Payment Settings); null with none selected. */
    paymentModes?: string[] | null;
    payments: {
        data: PaymentRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        prev_page_url?: string | null;
        next_page_url?: string | null;
    };
    filters: Record<string, string | number | null | undefined>;
    activeTab: string;
    downloadEligible: boolean;
}>();

const searchTerm = ref((props.filters.q as string) ?? '');
const advancedSearchOpen = ref(false);
const columnSettingsOpen = ref(false);
const addPaymentOpen = ref(false);
const cancelModalPayment = ref<PaymentRow | null>(null);
const cancelReason = ref('');
const activePaymentForActivity = ref<PaymentRow | null>(null);
const printPaymentModal = ref<PaymentRow | null>(null);
const selectedReceiptForModal = ref<PaymentRow | null>(null);

const currentTab = computed(() => props.activeTab || 'receipts');

const columnVisibility = useColumnVisibility(
    'payments-column-visibility',
    columns.map((c) => c.key),
);

const visibleColumns = computed(() =>
    columns.filter((column) => columnVisibility[column.key]),
);

function onColumnsSaved(next: Record<string, boolean>): void {
    Object.assign(columnVisibility, next);
    columnSettingsOpen.value = false;
}

function reloadWith(
    params: Record<string, string | number | null | undefined>,
): void {
    router.get(
        admin.databases.payments.index().url,
        {
            property: props.property?.id,
            tab: currentTab.value,
            ...props.filters,
            ...params,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function setTab(tab: 'receipts' | 'refunds'): void {
    router.get(
        admin.databases.payments.index().url,
        { property: props.property?.id, tab },
        { preserveScroll: true, replace: true },
    );
}

function submitSearch(): void {
    reloadWith({ q: searchTerm.value || undefined, page: undefined });
}

function resetSearch(): void {
    searchTerm.value = '';
    router.get(
        admin.databases.payments.index().url,
        { property: props.property?.id, tab: currentTab.value },
        { preserveScroll: true, replace: true },
    );
}

function applyAdvancedSearch(filters: PaymentAdvancedSearchFilters): void {
    reloadWith({ ...filters, q: undefined, page: undefined });
    advancedSearchOpen.value = false;
}

const downloadHref = computed(() => {
    return (
        `/admin/databases/payments/download?property=${props.property?.id ?? ''}&tab=${currentTab.value}` +
        (props.filters.date_from
            ? `&date_from=${props.filters.date_from}`
            : '') +
        (props.filters.date_to ? `&date_to=${props.filters.date_to}` : '') +
        (props.filters.created_from
            ? `&created_from=${props.filters.created_from}`
            : '') +
        (props.filters.created_to
            ? `&created_to=${props.filters.created_to}`
            : '')
    );
});

function formatDateTime(value: string | null): string {
    return propertyTime.dateTime(value);
}

function formatCurrency(value: number): string {
    return amount(value);
}

function openCancel(payment: PaymentRow): void {
    cancelModalPayment.value = payment;
    cancelReason.value = '';
}

function confirmCancel(): void {
    if (!cancelModalPayment.value) return;
    router.post(
        `/admin/databases/payments/${cancelModalPayment.value.id}/cancel`,
        { cancellation_reason: cancelReason.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelModalPayment.value = null;
            },
        },
    );
}

function printReceipt(payment: PaymentRow): void {
    printPaymentModal.value = payment;
    setTimeout(() => {
        window.print();
    }, 200);
}

function cellValue(payment: PaymentRow, key: string): string {
    switch (key) {
        case 'payment_creation_date':
            return formatDateTime(payment.payment_creation_date);
        case 'payment_transaction_date':
            return formatDateTime(payment.payment_transaction_date);
        case 'voucher_number':
            return payment.voucher_number ?? '—';
        case 'receipt_number':
            return payment.receipt_number ?? '—';
        case 'property_name':
            return payment.property_name ?? '—';
        case 'status':
            return payment.status;
        case 'payment_ref_id':
            return payment.payment_ref_id ?? '—';
        case 'pg_txn_id':
            return payment.pg_txn_id ?? '—';
        case 'ota_booking_ref_id':
            return payment.ota_booking_ref_id ?? '—';
        case 'neft_ref_id':
            return payment.neft_ref_id ?? '—';
        case 'imps_ref_id':
            return payment.imps_ref_id ?? '—';
        case 'rtgs_ref_id':
            return payment.rtgs_ref_id ?? '—';
        case 'guest_name':
            return payment.guest_name ?? '—';
        case 'payment_mode':
            return payment.payment_mode ?? '—';
        case 'card_number':
            return payment.card_number ?? '—';
        case 'total_txn_amount':
            return formatCurrency(payment.total_txn_amount);
        case 'tagged_amount':
            return payment.tagged_amount !== undefined
                ? formatCurrency(payment.tagged_amount)
                : '—';
        case 'untagged_amount':
            return payment.untagged_amount !== undefined
                ? formatCurrency(payment.untagged_amount)
                : '—';
        case 'pg_commission_amount':
            return payment.pg_commission_amount != null
                ? formatCurrency(payment.pg_commission_amount)
                : '—';
        case 'collection_source':
            return payment.collection_source ?? '—';
        case 'card_name':
            return payment.card_name ?? '—';
        case 'bank':
            return payment.bank ?? '—';
        case 'booking_source':
            return payment.booking_source ?? 'Direct';
        case 'delete_status':
            return payment.delete_status ?? '—';
        case 'cancellation_reason':
            return payment.cancellation_reason ?? '—';
        case 'collected_by_staff':
            return payment.collected_by_staff ?? '—';
        case 'notes':
            return payment.notes ?? '—';
        case 'pg_pay_fields_edited':
            return payment.pg_pay_fields_edited ?? '—';
        case 'coupon':
            return payment.coupon ?? '—';
        case 'payee_name':
            return payment.payee_name ?? '—';
        case 'document':
            return payment.document ?? '—';
        default:
            return '—';
    }
}
</script>

<template>
    <Head title="Payments Database" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Payments Database
        </h1>
    </div>

    <!-- Property empty state -->
    <div
        v-if="!property"
        class="rounded-lg border border-dashed border-[#e3e3e0] bg-white px-6 py-16 text-center text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#A1A09A]"
    >
        Select a property to view its payments database.
    </div>

    <template v-else>
        <!-- Tabs: Receipts | Refunds -->
        <div
            class="mb-4 flex items-center gap-1 border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <button
                type="button"
                class="rounded-t-md px-6 py-2 text-sm font-semibold transition"
                :class="
                    currentTab === 'receipts'
                        ? 'border-b-2 border-[#f53003] bg-white text-[#1b1b18] dark:bg-[#161615] dark:text-white'
                        : 'text-[#706f6c] hover:bg-[#f5f5f4] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]'
                "
                @click="setTab('receipts')"
            >
                Receipts
            </button>
            <button
                type="button"
                class="rounded-t-md px-6 py-2 text-sm font-semibold transition"
                :class="
                    currentTab === 'refunds'
                        ? 'border-b-2 border-[#f53003] bg-white text-[#1b1b18] dark:bg-[#161615] dark:text-white'
                        : 'text-[#706f6c] hover:bg-[#f5f5f4] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]'
                "
                @click="setTab('refunds')"
            >
                Refunds
            </button>
        </div>

        <!-- Search Bar / Action Toolbar -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <form
                    class="relative flex items-center"
                    @submit.prevent="submitSearch"
                >
                    <div class="relative flex items-center">
                        <input
                            v-model="searchTerm"
                            type="text"
                            placeholder="Payment Reference ID/ Guest/ Booking Reference ID"
                            class="block w-80 rounded-l-md border border-r-0 border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <button
                            type="button"
                            title="Advanced Filter"
                            class="border border-y border-[#e3e3e0] bg-white px-2 py-2.5 text-[#706f6c] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#A1A09A]"
                            @click="advancedSearchOpen = true"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M19 9l-7 7-7-7"
                                />
                            </svg>
                        </button>
                    </div>
                    <button
                        type="submit"
                        title="Search"
                        class="flex items-center justify-center rounded-r-md bg-[#0284c7] px-3.5 py-2.5 text-white hover:bg-[#0369a1]"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                            />
                        </svg>
                    </button>
                    <button
                        v-if="searchTerm"
                        type="button"
                        class="ml-2 text-xs text-[#706f6c] hover:underline dark:text-[#A1A09A]"
                        @click="resetSearch"
                    >
                        Reset
                    </button>
                </form>

                <a
                    :href="downloadHref"
                    class="rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    :class="downloadEligible ? '' : 'opacity-70'"
                    :title="
                        downloadEligible
                            ? 'Download payments list'
                            : 'Add a date filter of 30 days or fewer to download'
                    "
                >
                    View Downloads
                </a>
            </div>

            <!-- Right Toolbar Controls -->
            <div class="flex items-center gap-2">
                <div
                    class="flex items-center gap-2 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    <span v-if="payments.prev_page_url">
                        <Link
                            :href="payments.prev_page_url"
                            preserve-scroll
                            preserve-state
                            class="hover:underline"
                            >&lt;&lt; Prev</Link
                        >
                    </span>
                    <span v-else class="text-[#A1A09A]">&lt;&lt; Prev</span>

                    <span v-if="payments.next_page_url">
                        <Link
                            :href="payments.next_page_url"
                            preserve-scroll
                            preserve-state
                            class="hover:underline"
                            >Next &gt;&gt;</Link
                        >
                    </span>
                    <span v-else class="text-[#A1A09A]">Next &gt;&gt;</span>
                </div>

                <!-- Settings Gear Icon (Table Columns) -->
                <button
                    type="button"
                    title="Column settings"
                    class="rounded-md border border-[#e3e3e0] p-2 text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    @click="columnSettingsOpen = true"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z"
                        />
                    </svg>
                </button>

                <!-- Export / Download Icon -->
                <a
                    :href="downloadHref"
                    title="Export to CSV"
                    class="rounded-md border border-[#e3e3e0] p-2 text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
                        />
                    </svg>
                </a>

                <!-- Add Button -->
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm font-semibold text-[#1b1b18] shadow-sm hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    @click="addPaymentOpen = true"
                >
                    Add
                </button>
            </div>
        </div>

        <!-- DataTable -->
        <div
            class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <table
                class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
            >
                <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                    <tr>
                        <th
                            v-for="column in visibleColumns"
                            :key="column.key"
                            class="px-3 py-2.5 text-left text-xs font-semibold tracking-wide whitespace-nowrap text-[#555] uppercase dark:text-[#A1A09A]"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-[#e3e3e0] bg-white text-xs dark:divide-[#3E3E3A] dark:bg-[#161615]"
                >
                    <tr
                        v-for="payment in payments.data"
                        :key="payment.id"
                        class="hover:bg-slate-50 dark:hover:bg-neutral-800/40"
                    >
                        <td
                            v-for="column in visibleColumns"
                            :key="column.key"
                            class="px-3 py-2.5 whitespace-nowrap text-[#555] dark:text-[#A1A09A]"
                        >
                            <!-- Voucher/Order No link -->
                            <template v-if="column.key === 'voucher_number'">
                                <Link
                                    v-if="
                                        payment.booking_id &&
                                        (payment.property_id || property?.id)
                                    "
                                    :href="
                                        admin.bookings.show({
                                            property:
                                                payment.property_id ||
                                                property!.id,
                                            booking: payment.booking_id,
                                        }).url
                                    "
                                    class="cursor-pointer font-medium text-[#0284c7] hover:underline"
                                >
                                    {{ payment.voucher_number || '—' }}
                                </Link>
                                <span
                                    v-else
                                    class="font-medium text-[#555] dark:text-[#A1A09A]"
                                >
                                    {{ payment.voucher_number || '—' }}
                                </span>
                            </template>

                            <!-- Status Badge -->
                            <template v-else-if="column.key === 'status'">
                                <span
                                    class="inline-block rounded px-2 py-0.5 text-xs font-semibold"
                                    :class="{
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300':
                                            payment.status === 'Received' ||
                                            payment.status === 'Success',
                                        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300':
                                            payment.status === 'Pending',
                                        'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300':
                                            payment.status === 'Cancelled',
                                    }"
                                >
                                    {{ payment.status }}
                                </span>
                            </template>

                            <!-- Cancel Action -->
                            <template
                                v-else-if="column.key === 'cancel_action'"
                            >
                                <button
                                    v-if="payment.status !== 'Cancelled'"
                                    type="button"
                                    class="text-[#0284c7] hover:underline"
                                    @click="openCancel(payment)"
                                >
                                    Cancel
                                </button>
                                <span v-else class="text-[#A1A09A]">—</span>
                            </template>

                            <!-- Receipt Number Link -->
                            <template
                                v-else-if="column.key === 'receipt_number'"
                            >
                                <button
                                    v-if="
                                        payment.receipt_number &&
                                        payment.receipt_number !== '—'
                                    "
                                    type="button"
                                    class="cursor-pointer text-left text-[#0284c7] hover:underline"
                                    @click="selectedReceiptForModal = payment"
                                >
                                    {{ payment.receipt_number }}
                                </button>
                                <span v-else class="text-[#A1A09A]">—</span>
                            </template>

                            <!-- Activity View -->
                            <template v-else-if="column.key === 'activity'">
                                <button
                                    type="button"
                                    class="text-[#0284c7] hover:underline"
                                    @click="activePaymentForActivity = payment"
                                >
                                    View...
                                </button>
                            </template>

                            <!-- Print Receipt Action -->
                            <template
                                v-else-if="column.key === 'print_receipt'"
                            >
                                <button
                                    type="button"
                                    class="text-[#0284c7] hover:underline"
                                    @click="printReceipt(payment)"
                                >
                                    Print
                                </button>
                            </template>

                            <!-- Default Cell -->
                            <template v-else>
                                {{ cellValue(payment, column.key) }}
                            </template>
                        </td>
                    </tr>

                    <tr v-if="!payments.data.length">
                        <td
                            :colspan="visibleColumns.length"
                            class="px-4 py-12 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            No
                            {{
                                currentTab === 'refunds'
                                    ? 'refunds'
                                    : 'receipts'
                            }}
                            found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination Footer -->
        <div class="mt-4 flex items-center justify-between">
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                {{ payments.total }}
                {{ currentTab === 'refunds' ? 'refund' : 'receipt'
                }}{{ payments.total === 1 ? '' : 's' }}
            </p>
            <div v-if="payments.links.length > 3" class="flex flex-wrap gap-1">
                <template v-for="(link, index) in payments.links" :key="index">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-state
                        preserve-scroll
                        class="rounded-md px-3 py-1.5 text-sm"
                        :class="
                            link.active
                                ? 'bg-[#1b1b18] text-white dark:bg-[#eeeeec] dark:text-[#1C1C1A]'
                                : 'text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]'
                        "
                        ><span v-html="link.label"
                    /></Link>
                    <span
                        v-else
                        class="rounded-md px-3 py-1.5 text-sm text-[#A1A09A]"
                        v-html="link.label"
                    />
                </template>
            </div>
        </div>

        <!-- Modals -->
        <PaymentAdvancedSearchModal
            :show="advancedSearchOpen"
            :filters="filters"
            @close="advancedSearchOpen = false"
            @search="applyAdvancedSearch"
        />

        <TableColumnSettingsModal
            :show="columnSettingsOpen"
            :columns="columns"
            :model-value="columnVisibility"
            @close="columnSettingsOpen = false"
            @save="onColumnsSaved"
        />

        <AddPaymentModal
            :show="addPaymentOpen"
            :property="property"
            :properties="properties"
            :staff="staff"
            :current-user="currentUser"
            :reservations="reservations"
            :countries="countries"
            :payment-modes="paymentModes ?? undefined"
            @close="addPaymentOpen = false"
            @saved="() => router.reload()"
        />

        <!-- Receipt Details Modal -->
        <ReceiptDetailsModal
            :show="!!selectedReceiptForModal"
            :receipt="selectedReceiptForModal"
            @close="selectedReceiptForModal = null"
        />

        <!-- Cancel Confirmation Modal -->
        <div
            v-if="cancelModalPayment"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        >
            <div
                class="w-full max-w-md rounded-lg border border-[#e3e3e0] bg-white p-6 shadow-xl dark:border-[#3E3E3A] dark:bg-[#161615]"
            >
                <h3
                    class="font-display text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Cancel Payment Receipt
                </h3>
                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    Are you sure you want to cancel receipt
                    <strong class="text-[#1b1b18] dark:text-white">{{
                        cancelModalPayment.receipt_number ||
                        cancelModalPayment.voucher_number
                    }}</strong>
                    of amount
                    <strong>{{
                        formatCurrency(cancelModalPayment.total_txn_amount)
                    }}</strong
                    >?
                </p>

                <label
                    class="mt-4 block text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Cancellation Reason
                    <input
                        v-model="cancelReason"
                        type="text"
                        placeholder="Reason for cancellation..."
                        class="mt-1 block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-md px-4 py-2 text-sm font-medium text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]"
                        @click="cancelModalPayment = null"
                    >
                        Back
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700"
                        @click="confirmCancel"
                    >
                        Confirm Cancel
                    </button>
                </div>
            </div>
        </div>

        <!-- Activity Modal -->
        <div
            v-if="activePaymentForActivity"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        >
            <div
                class="w-full max-w-lg rounded-lg border border-[#e3e3e0] bg-white p-6 shadow-xl dark:border-[#3E3E3A] dark:bg-[#161615]"
            >
                <div
                    class="flex items-center justify-between border-b border-[#e3e3e0] pb-3 dark:border-[#3E3E3A]"
                >
                    <h3
                        class="font-display text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Payment Activity
                    </h3>
                    <button
                        type="button"
                        class="text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A]"
                        @click="activePaymentForActivity = null"
                    >
                        ✕
                    </button>
                </div>
                <div
                    class="mt-4 space-y-2 text-sm text-[#555] dark:text-[#A1A09A]"
                >
                    <div>
                        <strong>Receipt Number:</strong>
                        {{ activePaymentForActivity.receipt_number || '—' }}
                    </div>
                    <div>
                        <strong>Voucher Number:</strong>
                        {{ activePaymentForActivity.voucher_number || '—' }}
                    </div>
                    <div>
                        <strong>Guest:</strong>
                        {{ activePaymentForActivity.guest_name || '—' }}
                    </div>
                    <div>
                        <strong>Mode:</strong>
                        {{ activePaymentForActivity.payment_mode || '—' }}
                    </div>
                    <div>
                        <strong>Amount:</strong>
                        {{
                            formatCurrency(
                                activePaymentForActivity.total_txn_amount,
                            )
                        }}
                    </div>
                    <div>
                        <strong>Status:</strong>
                        {{ activePaymentForActivity.status }}
                    </div>
                    <div>
                        <strong>Recorded By:</strong>
                        {{
                            activePaymentForActivity.collected_by_staff ||
                            'System'
                        }}
                    </div>
                    <div>
                        <strong>Transaction Date:</strong>
                        {{
                            formatDateTime(
                                activePaymentForActivity.payment_transaction_date,
                            )
                        }}
                    </div>
                    <div>
                        <strong>Creation Date:</strong>
                        {{
                            formatDateTime(
                                activePaymentForActivity.payment_creation_date,
                            )
                        }}
                    </div>
                    <div v-if="activePaymentForActivity.cancellation_reason">
                        <strong>Cancellation Reason:</strong>
                        {{ activePaymentForActivity.cancellation_reason }}
                    </div>
                    <div v-if="activePaymentForActivity.notes">
                        <strong>Notes:</strong>
                        {{ activePaymentForActivity.notes }}
                    </div>
                </div>
                <div class="mt-6 flex justify-end">
                    <button
                        type="button"
                        class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-semibold text-white hover:bg-black dark:bg-white dark:text-black"
                        @click="activePaymentForActivity = null"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>

        <!-- Printable Receipt Hidden/Modal -->
        <div
            v-if="printPaymentModal"
            class="fixed inset-0 z-50 hidden bg-white p-8 text-black print:block"
        >
            <div class="mx-auto max-w-xl border p-6">
                <div class="mb-4 text-center text-xl font-bold uppercase">
                    {{ printPaymentModal.property_name || 'Payment Receipt' }}
                </div>
                <div
                    class="mb-4 border-b pb-2 text-center text-sm font-semibold"
                >
                    PAYMENT RECEIPT
                </div>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span>Receipt No:</span>
                        <span class="font-bold">{{
                            printPaymentModal.receipt_number || '—'
                        }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Date:</span>
                        <span>{{
                            formatDateTime(
                                printPaymentModal.payment_transaction_date,
                            )
                        }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Booking Ref / Voucher:</span>
                        <span>{{
                            printPaymentModal.voucher_number || '—'
                        }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Guest Name:</span>
                        <span class="font-bold">{{
                            printPaymentModal.guest_name || '—'
                        }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Payment Mode:</span>
                        <span>{{ printPaymentModal.payment_mode || '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Status:</span>
                        <span class="font-bold">{{
                            printPaymentModal.status
                        }}</span>
                    </div>
                    <div
                        class="flex justify-between border-t pt-2 text-base font-bold"
                    >
                        <span>Total Paid:</span>
                        <span>{{
                            formatCurrency(printPaymentModal.total_txn_amount)
                        }}</span>
                    </div>
                </div>
            </div>
        </div>
    </template>
</template>
