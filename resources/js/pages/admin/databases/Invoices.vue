<script setup lang="ts">
import InvoiceAdvancedSearchModal, {
    type InvoiceAdvancedSearchFilters,
} from '@/components/InvoiceAdvancedSearchModal.vue';
import Modal from '@/components/Modal.vue';
import TableColumnSettingsModal, {
    type TableColumn,
} from '@/components/TableColumnSettingsModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useCan } from '@/lib/useCan';
import { useColumnVisibility } from '@/lib/useColumnVisibility';
import admin from '@/routes/admin';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useCurrency } from '@/lib/useCurrency';
import { computed, ref } from 'vue';
import { calendarDate, usePropertyTime } from '@/lib/usePropertyTime';

/** Times arrive in UTC and are shown in the property's timezone. */
const propertyTime = usePropertyTime();

const { amount } = useCurrency();

defineOptions({ layout: AdminLayout });

export type CreditNoteRow = {
    id: number;
    credit_note_number: string;
    issue_date: string;
    invoice_number: string | null;
    party_name: string | null;
    folio_number: string | null;
    booking_id: number | null;
    property_id: number;
    reason: string;
    taxable_amount: number;
    tax_amount: number;
    total: number;
    issued_by: string | null;
};

export type InvoiceRow = {
    id: number;
    property_id: number;
    booking_id: number | null;
    invoice_generation_date: string | null;
    reservation_date: string | null;
    folio_number: string | null;
    order_number: string | null;
    property_name: string | null;
    outlet_name: string | null;
    unit_type: string | null;
    invoice_number: string;
    status: string;
    paid_by: string | null;
    booking_source: string | null;
    guest_name: string | null;
    agent_ota_name: string | null;
    corporate_account: string | null;
    corporate_id: string | null;
    /** Billed to a company's City Ledger account (Bill To Guest Company). */
    city_ledger_account_id: number | null;
    due_date: string | null;
    /** What the company still owes on the booking; null when the guest pays. */
    company_unpaid: number | null;
    last_receipt_date: string | null;
    invoice_amount: number;
    taxable_amount: number;
    total_tax: number;
    amount_paid: number;
    tax_exempted: number;
    tax_exemption_type: string | null;
    invoice_outstanding_amount: number;
    generated_by_staff: string | null;
    booking_reference: string | null;
    days_in_outstanding: string | null;
    sales_person: string | null;
    promo_name: string | null;
    document_attached: string | null;
    party_name: string | null;
    party_gstin: string | null;
    show_tax_breakup: boolean;
    credited_total: number;
    checkin_date: string | null;
    checkout_date: string | null;
    total_pax: number;
    total_children: number;
    rooms_count: number;
    discount: number;
    room_nights: number;
    meal_plans: string | null;
    item_types: string | null;
    tally_status: string | null;
    tally_response: string | null;
    has_attachment: boolean;
    document: string | null;
    send_bill: boolean;
    soa_generated: boolean;
};

const props = defineProps<{
    property?: {
        id: number;
        name: string;
        abbreviation: string;
        country_id?: number | null;
    };
    properties: { id: number; name: string; abbreviation: string }[];
    staff: { id: number; name: string }[];
    invoices: {
        data: InvoiceRow[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Record<string, string | number | null | undefined>;
    activeTab: 'invoice' | 'credit_note';
    creditNotes: {
        data: CreditNoteRow[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    } | null;
    downloadEligible: boolean;
}>();

const can = useCan();

// Credit notes: issued against an invoice for at most what it has left to credit.
const creditingInvoice = ref<InvoiceRow | null>(null);
const creditForm = useForm({ total: '' as string | number, reason: '' });

function creditable(invoice: InvoiceRow): number {
    return Math.max(
        0,
        Math.round((invoice.invoice_amount - invoice.credited_total) * 100) /
            100,
    );
}

function openCreditNote(invoice: InvoiceRow): void {
    creditingInvoice.value = invoice;
    creditForm.reset();
    creditForm.clearErrors();
    creditForm.total = creditable(invoice);
}

function issueCreditNote(): void {
    if (!creditingInvoice.value) {
        return;
    }

    creditForm.post(
        admin.databases.invoices.creditNotes.store(creditingInvoice.value.id)
            .url,
        {
            preserveScroll: true,
            onSuccess: () => {
                creditingInvoice.value = null;
            },
        },
    );
}

const searchTerm = ref(String(props.filters.search ?? ''));
const advancedSearchOpen = ref(false);
const columnSettingsOpen = ref(false);

const columns: TableColumn[] = [
    { key: 'invoice_generation_date', label: 'Invoice Generation Date' },
    { key: 'reservation_date', label: 'Reservation Date' },
    { key: 'folio_number', label: 'Folio Number' },
    { key: 'order_number', label: 'Order Number' },
    { key: 'property_name', label: 'Property Name' },
    { key: 'outlet_name', label: 'Outlet Name' },
    { key: 'unit_type', label: 'Unit Type' },
    { key: 'invoice_number', label: 'Invoice Number' },
    { key: 'status', label: 'Status' },
    { key: 'paid_by', label: 'Paid By' },
    { key: 'booking_source', label: 'Booking Source' },
    { key: 'guest_name', label: 'Guest Name' },
    { key: 'agent_ota_name', label: 'Agent / OTA Name' },
    { key: 'corporate_account', label: 'Corporate Account' },
    { key: 'corporate_id', label: 'Corporate ID' },
    { key: 'due_date', label: 'Due Date' },
    { key: 'company_unpaid', label: 'Unpaid by Company' },
    { key: 'last_receipt_date', label: 'Last Receipt Date' },
    { key: 'invoice_amount', label: 'Invoice Amount' },
    { key: 'taxable_amount', label: 'Taxable Amount' },
    { key: 'total_tax', label: 'Total Tax' },
    { key: 'amount_paid', label: 'Amount Paid' },
    { key: 'tax_exempted', label: 'Tax Exempted' },
    { key: 'tax_exemption_type', label: 'Tax Exemption Type' },
    { key: 'invoice_outstanding_amount', label: 'Invoice Outstanding amount' },
    { key: 'generated_by_staff', label: 'Generated By Staff' },
    { key: 'booking_reference', label: 'Booking Reference' },
    { key: 'days_in_outstanding', label: 'Days in Outstanding' },
    { key: 'sales_person', label: 'Sales Person' },
    { key: 'promo_name', label: 'Promo Name' },
    { key: 'document_attached', label: 'Document Attached' },
    { key: 'party_name', label: 'Party Name' },
    { key: 'party_gstin', label: 'Party GSTIN / Tax Reg No.' },
    { key: 'show_tax_breakup', label: 'Show Tax breakup' },
    { key: 'checkin_date', label: 'CheckIn Date' },
    { key: 'checkout_date', label: 'CheckOut Date' },
    { key: 'total_pax', label: 'Total Pax' },
    { key: 'total_children', label: 'Total Children' },
    { key: 'rooms_count', label: 'Rooms' },
    { key: 'discount', label: 'Discount' },
    { key: 'room_nights', label: 'Room Nights' },
    { key: 'meal_plans', label: 'Meal Plans' },
    { key: 'item_types', label: 'Item Types' },
    { key: 'tally_status', label: 'Tally Status' },
    { key: 'tally_response', label: 'Tally Response' },
    { key: 'activity', label: 'Activity' },
];

const defaultKeys = [
    'invoice_generation_date',
    'reservation_date',
    'folio_number',
    'order_number',
    'property_name',
    'outlet_name',
    'unit_type',
    'invoice_number',
    'status',
    'paid_by',
    'booking_source',
    'guest_name',
    'agent_ota_name',
    'corporate_account',
    'corporate_id',
    'due_date',
    'company_unpaid',
    'last_receipt_date',
    'invoice_amount',
    'taxable_amount',
    'total_tax',
    'amount_paid',
    'invoice_outstanding_amount',
    'generated_by_staff',
    'booking_reference',
    'days_in_outstanding',
    'promo_name',
    'party_name',
    'checkin_date',
    'checkout_date',
    'total_pax',
    'rooms_count',
    'discount',
    'item_types',
    'meal_plans',
    'activity',
];

const columnVisibility = useColumnVisibility(
    'invoices-column-visibility',
    columns.map((c) => c.key),
    Object.fromEntries(
        columns.map((c) => [c.key, defaultKeys.includes(c.key)]),
    ),
);

const visibleColumns = computed(() =>
    columns.filter((column) => columnVisibility[column.key]),
);

function onColumnsSaved(next: Record<string, boolean>): void {
    Object.assign(columnVisibility, next);
    columnSettingsOpen.value = false;
}

function switchTab(tab: 'invoice' | 'credit_note'): void {
    router.get(
        admin.databases.invoices.index().url,
        {
            property: props.property?.id,
            tab,
            search: searchTerm.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function submitSearch(): void {
    router.get(
        admin.databases.invoices.index().url,
        {
            property: props.property?.id,
            tab: props.activeTab,
            search: searchTerm.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function resetSearch(): void {
    searchTerm.value = '';
    router.get(
        admin.databases.invoices.index().url,
        {
            property: props.property?.id,
            tab: props.activeTab,
        },
        { preserveState: true, replace: true },
    );
}

/** Billed to: anyone, the guest, a company, or a company that hasn't paid yet. */
function filterBilledTo(billedTo: string): void {
    router.get(
        admin.databases.invoices.index().url,
        {
            ...props.filters,
            property: props.property?.id,
            tab: props.activeTab,
            billed_to: billedTo || undefined,
            page: undefined,
        },
        { preserveState: true, replace: true },
    );
}

function applyAdvancedSearch(advFilters: InvoiceAdvancedSearchFilters): void {
    router.get(
        admin.databases.invoices.index().url,
        {
            property: props.property?.id,
            tab: props.activeTab,
            search: searchTerm.value || undefined,
            ...advFilters,
        },
        { preserveState: true, replace: true },
    );
}

const downloadHref = computed(() => {
    return admin.databases.invoices.download({
        query: {
            property: props.property?.id,
            tab: props.activeTab,
            ...props.filters,
        },
    }).url;
});

function formatCurrency(value: number): string {
    return amount(value);
}

function cellValue(invoice: InvoiceRow, key: string): string | number {
    switch (key) {
        case 'invoice_amount':
            return formatCurrency(invoice.invoice_amount);
        case 'taxable_amount':
            return formatCurrency(invoice.taxable_amount);
        case 'total_tax':
            return formatCurrency(invoice.total_tax);
        case 'amount_paid':
            return formatCurrency(invoice.amount_paid);
        case 'tax_exempted':
            return formatCurrency(invoice.tax_exempted);
        case 'invoice_outstanding_amount':
            return formatCurrency(invoice.invoice_outstanding_amount);
        case 'discount':
            return formatCurrency(invoice.discount);
        case 'show_tax_breakup':
            return invoice.show_tax_breakup ? 'Yes' : 'No';
        case 'invoice_generation_date':
            return propertyTime.date(invoice.invoice_generation_date);
        case 'reservation_date':
            return propertyTime.date(invoice.reservation_date);
        case 'last_receipt_date':
            return calendarDate(invoice.last_receipt_date);
        case 'due_date':
            return invoice.due_date ? calendarDate(invoice.due_date) : '—';
        default:
            return (invoice as Record<string, any>)[key] ?? '—';
    }
}
</script>

<template>
    <Head title="Invoice Database" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Invoice Database
        </h1>
    </div>

    <!-- Tabs: Invoice | Credit Note -->
    <div
        class="mb-4 flex items-center gap-1 border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <button
            v-for="tab in [
                { key: 'invoice', label: 'Invoice' },
                { key: 'credit_note', label: 'Credit Note' },
            ] as const"
            :key="tab.key"
            type="button"
            class="rounded-t-md px-6 py-2 text-sm font-semibold transition"
            :class="
                activeTab === tab.key
                    ? 'border-b-2 border-[#f53003] bg-white text-[#1b1b18] dark:bg-[#161615] dark:text-white'
                    : 'text-[#706f6c] hover:bg-[#f5f5f4] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]'
            "
            @click="switchTab(tab.key)"
        >
            {{ tab.label }}
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
                        placeholder="Invoice No. / Voucher No. / Guest Name"
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
                    v-if="searchTerm || Object.keys(filters).length > 2"
                    type="button"
                    class="ml-2 text-xs text-[#706f6c] hover:underline dark:text-[#A1A09A]"
                    @click="resetSearch"
                >
                    Reset
                </button>
            </form>

            <!-- Billed to: guest, company, or a company that hasn't paid yet -->
            <select
                v-if="activeTab === 'invoice'"
                :value="filters.billed_to ?? ''"
                class="rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                data-test="billed-to-filter"
                @change="
                    filterBilledTo(($event.target as HTMLSelectElement).value)
                "
            >
                <option value="">Billed to: anyone</option>
                <option value="guest">Billed to guest</option>
                <option value="company">Billed to company</option>
                <option value="company_unpaid">Company, unpaid</option>
            </select>

            <a
                :href="downloadHref"
                class="rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                :class="downloadEligible ? '' : 'opacity-70'"
                :title="
                    downloadEligible
                        ? 'Download invoice list'
                        : 'Add a date filter of 30 days or fewer in Advanced Search to download'
                "
            >
                View Downloads
            </a>
        </div>

        <!-- Right Toolbar Controls -->
        <div class="flex items-center gap-2">
            <div
                v-if="activeTab === 'invoice'"
                class="flex items-center gap-2 text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                <span v-if="invoices.prev_page_url">
                    <Link
                        :href="invoices.prev_page_url"
                        preserve-scroll
                        preserve-state
                        class="hover:underline"
                        >&lt;&lt; Prev</Link
                    >
                </span>
                <span v-else class="text-[#A1A09A]">&lt;&lt; Prev</span>

                <span v-if="invoices.next_page_url">
                    <Link
                        :href="invoices.next_page_url"
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
                v-if="activeTab === 'invoice'"
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
                :class="{ 'pointer-events-none opacity-50': !downloadEligible }"
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
        </div>
    </div>

    <!-- Credit notes -->
    <div
        v-if="activeTab === 'credit_note'"
        class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <table
            class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
        >
            <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                <tr>
                    <th
                        v-for="label in [
                            'Credit Note No.',
                            'Issue Date',
                            'Invoice No.',
                            'Folio Number',
                            'Party',
                            'Reason',
                            'Taxable Amount',
                            'Tax',
                            'Total',
                            'Issued By',
                            '',
                        ]"
                        :key="label"
                        class="px-3 py-2.5 text-left text-xs font-semibold tracking-wide whitespace-nowrap text-[#555] uppercase dark:text-[#A1A09A]"
                    >
                        {{ label }}
                    </th>
                </tr>
            </thead>
            <tbody
                class="divide-y divide-[#e3e3e0] bg-white text-xs dark:divide-[#3E3E3A] dark:bg-[#161615]"
            >
                <tr
                    v-for="note in creditNotes?.data ?? []"
                    :key="note.id"
                    class="hover:bg-slate-50 dark:hover:bg-neutral-800/40"
                >
                    <td
                        class="px-3 py-2.5 font-medium whitespace-nowrap text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        {{ note.credit_note_number }}
                    </td>
                    <td
                        class="px-3 py-2.5 whitespace-nowrap text-[#555] dark:text-[#A1A09A]"
                    >
                        {{ calendarDate(note.issue_date) }}
                    </td>
                    <td
                        class="px-3 py-2.5 whitespace-nowrap text-[#555] dark:text-[#A1A09A]"
                    >
                        {{ note.invoice_number ?? '—' }}
                    </td>
                    <td class="px-3 py-2.5 whitespace-nowrap">
                        <Link
                            v-if="note.booking_id"
                            :href="
                                admin.bookings.show({
                                    property: note.property_id,
                                    booking: note.booking_id,
                                }).url
                            "
                            class="font-medium text-[#0284c7] hover:underline"
                            >{{ note.folio_number ?? '—' }}</Link
                        >
                        <span v-else class="text-[#555] dark:text-[#A1A09A]">{{
                            note.folio_number ?? '—'
                        }}</span>
                    </td>
                    <td
                        class="px-3 py-2.5 whitespace-nowrap text-[#555] dark:text-[#A1A09A]"
                    >
                        {{ note.party_name ?? '—' }}
                    </td>
                    <td class="px-3 py-2.5 text-[#555] dark:text-[#A1A09A]">
                        {{ note.reason }}
                    </td>
                    <td
                        class="px-3 py-2.5 whitespace-nowrap text-[#555] dark:text-[#A1A09A]"
                    >
                        {{ formatCurrency(note.taxable_amount) }}
                    </td>
                    <td
                        class="px-3 py-2.5 whitespace-nowrap text-[#555] dark:text-[#A1A09A]"
                    >
                        {{ formatCurrency(note.tax_amount) }}
                    </td>
                    <td
                        class="px-3 py-2.5 font-medium whitespace-nowrap text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        {{ formatCurrency(note.total) }}
                    </td>
                    <td
                        class="px-3 py-2.5 whitespace-nowrap text-[#555] dark:text-[#A1A09A]"
                    >
                        {{ note.issued_by ?? '—' }}
                    </td>
                    <td class="px-3 py-2.5 whitespace-nowrap">
                        <a
                            :href="
                                admin.databases.invoices.creditNotes.print(
                                    note.id,
                                ).url
                            "
                            target="_blank"
                            rel="noopener"
                            class="text-[#0284c7] hover:underline"
                            >Print</a
                        >
                    </td>
                </tr>
                <tr v-if="!creditNotes?.data.length">
                    <td
                        colspan="11"
                        class="px-4 py-12 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        No credit notes issued.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- DataTable -->
    <div
        v-else
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
                    <th
                        v-if="can('database.invoices.issue_credit_note')"
                        class="px-3 py-2.5 text-left text-xs font-semibold tracking-wide whitespace-nowrap text-[#555] uppercase dark:text-[#A1A09A]"
                    >
                        Credit Note
                    </th>
                </tr>
            </thead>
            <tbody
                class="divide-y divide-[#e3e3e0] bg-white text-xs dark:divide-[#3E3E3A] dark:bg-[#161615]"
            >
                <tr
                    v-for="invoice in invoices.data"
                    :key="invoice.id"
                    class="hover:bg-slate-50 dark:hover:bg-neutral-800/40"
                >
                    <td
                        v-for="column in visibleColumns"
                        :key="column.key"
                        class="px-3 py-2.5 whitespace-nowrap text-[#555] dark:text-[#A1A09A]"
                    >
                        <!-- Folio Number Link to Reservation -->
                        <template v-if="column.key === 'folio_number'">
                            <Link
                                v-if="
                                    invoice.booking_id &&
                                    (invoice.property_id || property?.id)
                                "
                                :href="
                                    admin.bookings.show({
                                        property:
                                            invoice.property_id || property!.id,
                                        booking: invoice.booking_id,
                                    }).url
                                "
                                class="cursor-pointer font-medium text-[#0284c7] hover:underline"
                            >
                                {{ invoice.folio_number || '—' }}
                            </Link>
                            <span
                                v-else
                                class="font-medium text-[#555] dark:text-[#A1A09A]"
                            >
                                {{ invoice.folio_number || '—' }}
                            </span>
                        </template>

                        <!-- Invoice Number -->
                        <template v-else-if="column.key === 'invoice_number'">
                            <span
                                class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >{{ invoice.invoice_number }}</span
                            >
                        </template>

                        <!-- Status Badge -->
                        <template v-else-if="column.key === 'status'">
                            <span
                                class="inline-block rounded px-2 py-0.5 text-xs font-semibold"
                                :class="
                                    /cancel|void/i.test(invoice.status)
                                        ? 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'
                                        : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                "
                            >
                                {{ invoice.status }}
                            </span>
                        </template>

                        <!-- Paid By: a company billed through its City Ledger account gets a badge -->
                        <template v-else-if="column.key === 'paid_by'">
                            <span
                                v-if="invoice.city_ledger_account_id"
                                class="inline-block rounded bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                                :title="`City Ledger: ${invoice.corporate_account}`"
                                data-test="invoice-company-pays"
                            >
                                Company
                            </span>
                            <template v-else>{{ invoice.paid_by }}</template>
                        </template>

                        <!-- What the company still owes on it -->
                        <template v-else-if="column.key === 'company_unpaid'">
                            <span
                                v-if="invoice.company_unpaid === null"
                                class="text-[#A1A09A]"
                                >—</span
                            >
                            <Link
                                v-else
                                :href="
                                    admin.databases.cityLedger.show(
                                        invoice.city_ledger_account_id!,
                                    ).url
                                "
                                class="font-medium hover:underline"
                                :class="
                                    invoice.company_unpaid > 0
                                        ? 'text-[#f53003] dark:text-[#FF4433]'
                                        : 'text-emerald-700 dark:text-emerald-400'
                                "
                            >
                                {{
                                    invoice.company_unpaid > 0
                                        ? formatCurrency(invoice.company_unpaid)
                                        : 'Paid'
                                }}
                            </Link>
                        </template>

                        <!-- Activity View Link -->
                        <template v-else-if="column.key === 'activity'">
                            <Link
                                v-if="
                                    invoice.booking_id &&
                                    (invoice.property_id || property?.id)
                                "
                                :href="
                                    admin.bookings.show({
                                        property:
                                            invoice.property_id || property!.id,
                                        booking: invoice.booking_id,
                                    }).url
                                "
                                class="text-[#0284c7] hover:underline"
                            >
                                View...
                            </Link>
                            <span v-else class="text-[#A1A09A]">—</span>
                        </template>

                        <!-- Default Cell -->
                        <template v-else>
                            {{ cellValue(invoice, column.key) }}
                        </template>
                    </td>
                    <td
                        v-if="can('database.invoices.issue_credit_note')"
                        class="px-3 py-2.5 whitespace-nowrap text-[#555] dark:text-[#A1A09A]"
                    >
                        <button
                            v-if="creditable(invoice) > 0"
                            type="button"
                            class="text-[#0284c7] hover:underline"
                            @click="openCreditNote(invoice)"
                        >
                            Issue
                        </button>
                        <span v-else class="text-[#A1A09A]"
                            >Fully credited</span
                        >
                        <span
                            v-if="
                                invoice.credited_total > 0 &&
                                creditable(invoice) > 0
                            "
                            class="ml-1 text-[#706f6c] dark:text-[#A1A09A]"
                            >({{
                                formatCurrency(invoice.credited_total)
                            }}
                            credited)</span
                        >
                    </td>
                </tr>
                <tr v-if="!invoices.data.length">
                    <td
                        :colspan="visibleColumns.length + 1"
                        class="px-4 py-12 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        No invoices found.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination Footer -->
    <div
        v-if="activeTab === 'credit_note' && creditNotes"
        class="mt-4 flex items-center justify-between"
    >
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            {{ creditNotes.total }} credit note{{
                creditNotes.total === 1 ? '' : 's'
            }}
        </p>
        <div v-if="creditNotes.links.length > 3" class="flex flex-wrap gap-1">
            <template v-for="(link, index) in creditNotes.links" :key="index">
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
    <div v-else class="mt-4 flex items-center justify-between">
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            {{ invoices.total }} invoice{{ invoices.total === 1 ? '' : 's' }}
        </p>
        <div v-if="invoices.links.length > 3" class="flex flex-wrap gap-1">
            <template v-for="(link, index) in invoices.links" :key="index">
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

    <!-- Issue Credit Note Modal -->
    <Modal
        :show="creditingInvoice !== null"
        :title="`Credit note on ${creditingInvoice?.invoice_number ?? ''}`"
        @close="creditingInvoice = null"
    >
        <form
            v-if="creditingInvoice"
            class="space-y-4"
            @submit.prevent="issueCreditNote"
        >
            <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                Invoice
                {{ formatCurrency(creditingInvoice.invoice_amount) }} (tax
                {{ formatCurrency(creditingInvoice.total_tax) }}). Up to
                {{ formatCurrency(creditable(creditingInvoice)) }} can be
                credited; the tax is credited in the invoice's proportion. The
                booking's folio is credited by the same amount.
            </p>
            <label
                class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                Amount to credit (tax included)
                <input
                    v-model="creditForm.total"
                    type="number"
                    min="0.01"
                    step="0.01"
                    :max="creditable(creditingInvoice)"
                    required
                    class="rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                />
                <span v-if="creditForm.errors.total" class="text-red-600">{{
                    creditForm.errors.total
                }}</span>
            </label>
            <label
                class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                Reason
                <textarea
                    v-model="creditForm.reason"
                    rows="3"
                    maxlength="500"
                    required
                    class="rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                />
                <span v-if="creditForm.errors.reason" class="text-red-600">{{
                    creditForm.errors.reason
                }}</span>
            </label>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="creditingInvoice = null"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :disabled="creditForm.processing"
                    class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                >
                    Issue credit note
                </button>
            </div>
        </form>
    </Modal>

    <!-- Advanced Search Modal -->
    <InvoiceAdvancedSearchModal
        :show="advancedSearchOpen"
        :filters="filters"
        :staff="staff"
        @close="advancedSearchOpen = false"
        @search="applyAdvancedSearch"
    />

    <!-- Column Settings Modal -->
    <TableColumnSettingsModal
        :show="columnSettingsOpen"
        :columns="columns"
        :model-value="columnVisibility"
        @close="columnSettingsOpen = false"
        @save="onColumnsSaved"
    />
</template>
