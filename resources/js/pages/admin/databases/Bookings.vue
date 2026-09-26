<script setup lang="ts">
import BookingAdvancedSearchModal, {
    type BookingAdvancedSearchFilters,
} from '@/components/BookingAdvancedSearchModal.vue';
import TableColumnSettingsModal, {
    type TableColumn,
} from '@/components/TableColumnSettingsModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useColumnVisibility } from '@/lib/useColumnVisibility';
import admin from '@/routes/admin';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { calendarDate, usePropertyTime } from '@/lib/usePropertyTime';

/** Times arrive in UTC and are shown in the property's timezone. */
const propertyTime = usePropertyTime();

defineOptions({ layout: AdminLayout });

type BookingRow = {
    id: number;
    property_name: string | null;
    booking_ref_id: string;
    guest_name: string | null;
    guest_id: number | null;
    guest_email: string | null;
    guest_mobile: string | null;
    guest_address: string | null;
    guest_city: string | null;
    guest_state: string | null;
    guest_country: string | null;
    guest_postal_code: string | null;
    guest_nationality: string | null;
    guest_company_name: string | null;
    guest_company_email: string | null;
    source: string;
    booking_type: string | null;
    check_in: string | null;
    check_out: string | null;
    nights: number;
    rooms_count: number;
    check_in_status: string | null;
    status: string;
    room_total: number;
    discount: number;
    taxes: number;
    total: number;
    outstanding: number;
    special_instructions: string | null;
    internal_notes: string | null;
    created_at: string | null;
};

const columns: TableColumn[] = [
    { key: 'property_name', label: 'Property Name' },
    { key: 'booking_ref_id', label: 'Reservation No.' },
    { key: 'guest_name', label: 'Guest Name' },
    { key: 'guest_id', label: 'Guest ID' },
    { key: 'guest_email', label: 'Guest E-mail' },
    { key: 'guest_mobile', label: 'Guest Contact No.' },
    { key: 'guest_address', label: 'Guest Address' },
    { key: 'guest_city', label: 'Guest City' },
    { key: 'guest_state', label: 'Guest State' },
    { key: 'guest_country', label: 'Guest Country' },
    { key: 'guest_postal_code', label: 'Guest Postal Code' },
    { key: 'guest_nationality', label: 'Guest Nationality' },
    { key: 'guest_company_name', label: 'Guest Company Name' },
    { key: 'guest_company_email', label: 'Guest Company E-mail' },
    { key: 'source', label: 'Booking Source' },
    { key: 'booking_type', label: 'Booking Type' },
    { key: 'check_in', label: 'Check-in' },
    { key: 'check_out', label: 'Check-out' },
    { key: 'nights', label: 'Nights' },
    { key: 'rooms_count', label: 'Rooms' },
    { key: 'check_in_status', label: 'Check-in Status' },
    { key: 'status', label: 'Status' },
    { key: 'room_total', label: 'Room Total' },
    { key: 'discount', label: 'Discount' },
    { key: 'taxes', label: 'Taxes' },
    { key: 'total', label: 'Total' },
    { key: 'outstanding', label: 'Outstanding' },
    { key: 'special_instructions', label: 'Special Instructions' },
    { key: 'internal_notes', label: 'Internal Notes' },
    { key: 'created_at', label: 'Booking Creation Date' },
];

const props = defineProps<{
    property?: { id: number; name: string; country_id: number | null };
    bookings: {
        data: BookingRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: Record<string, string | number | null | undefined>;
    downloadEligible: boolean;
}>();

const searchTerm = ref((props.filters.q as string) ?? '');
const advancedSearchOpen = ref(false);
const columnSettingsOpen = ref(false);

const columnVisibility = useColumnVisibility(
    'bookings-column-visibility',
    columns.map((column) => column.key),
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
        admin.databases.bookings.index().url,
        { property: props.property?.id, ...props.filters, ...params },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function submitSearch(): void {
    reloadWith({ q: searchTerm.value || undefined, page: undefined });
}

function resetSearch(): void {
    searchTerm.value = '';
    router.get(
        admin.databases.bookings.index().url,
        { property: props.property?.id },
        { preserveScroll: true, replace: true },
    );
}

function applyAdvancedSearch(filters: BookingAdvancedSearchFilters): void {
    reloadWith({ ...filters, q: undefined, page: undefined });
    advancedSearchOpen.value = false;
}

const downloadHref = computed(
    () =>
        admin.databases.bookings.download({
            query: { property: props.property?.id, ...props.filters },
        }).url,
);

function formatDate(value: string | null): string {
    return calendarDate(value);
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
}

function cellValue(booking: BookingRow, key: string): string {
    switch (key) {
        case 'property_name':
            return booking.property_name ?? '—';
        case 'booking_ref_id':
            return booking.booking_ref_id;
        case 'guest_name':
            return booking.guest_name ?? '—';
        case 'guest_id':
            return booking.guest_id ? String(booking.guest_id) : '—';
        case 'guest_email':
            return booking.guest_email ?? '—';
        case 'guest_mobile':
            return booking.guest_mobile || '—';
        case 'guest_address':
            return booking.guest_address ?? '—';
        case 'guest_city':
            return booking.guest_city ?? '—';
        case 'guest_state':
            return booking.guest_state ?? '—';
        case 'guest_country':
            return booking.guest_country ?? '—';
        case 'guest_postal_code':
            return booking.guest_postal_code ?? '—';
        case 'guest_nationality':
            return booking.guest_nationality ?? '—';
        case 'guest_company_name':
            return booking.guest_company_name ?? '—';
        case 'guest_company_email':
            return booking.guest_company_email ?? '—';
        case 'source': {
            const sourceMap: Record<string, string> = {
                direct: 'Direct',
                corporate: 'Corporate',
                travel_agent: 'Agent',
                ota: 'OTA',
                meta: 'Meta',
                booking_engine: 'Booking Engine',
            };
            return sourceMap[booking.source] ?? booking.source;
        }
        case 'booking_type':
            return booking.booking_type ?? '—';
        case 'check_in':
            return formatDate(booking.check_in);
        case 'check_out':
            return formatDate(booking.check_out);
        case 'nights':
            return String(booking.nights);
        case 'rooms_count':
            return String(booking.rooms_count);
        case 'check_in_status':
            return booking.check_in_status || '—';
        case 'status':
            return booking.status;
        case 'room_total':
            return formatCurrency(booking.room_total);
        case 'discount':
            return formatCurrency(booking.discount);
        case 'taxes':
            return formatCurrency(booking.taxes);
        case 'total':
            return formatCurrency(booking.total);
        case 'outstanding':
            return formatCurrency(booking.outstanding);
        case 'special_instructions':
            return booking.special_instructions ?? '—';
        case 'internal_notes':
            return booking.internal_notes ?? '—';
        case 'created_at':
            return propertyTime.date(booking.created_at);
        default:
            return '—';
    }
}
</script>

<template>
    <Head title="Bookings" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Bookings
        </h1>
        <div v-if="property" class="flex items-center gap-3">
            <a
                :href="downloadHref"
                class="inline-flex items-center rounded-md border px-4 py-2 text-sm font-medium"
                :class="
                    downloadEligible
                        ? 'border-[#e3e3e0] text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]'
                        : 'pointer-events-none border-[#e3e3e0] text-[#A1A09A] dark:border-[#3E3E3A]'
                "
                :aria-disabled="!downloadEligible"
                :title="
                    downloadEligible
                        ? undefined
                        : 'Add a date filter (30 days or fewer) in Advanced Search to enable download'
                "
            >
                Download Booking List
            </a>
            <Link
                :href="admin.bookings.create(property)"
                class="inline-flex items-center rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-medium text-white hover:bg-[#d92a02]"
            >
                Quick Book
            </Link>
        </div>
    </div>

    <div
        v-if="!property"
        class="rounded-lg border border-dashed border-[#e3e3e0] bg-white px-6 py-16 text-center text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#A1A09A]"
    >
        Select a property to view its bookings.
    </div>

    <template v-else>
        <p
            v-if="!downloadEligible"
            class="mb-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            Add a date filter of 30 days or fewer in Advanced Search to enable
            Download Booking List.
        </p>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <form
                class="flex flex-1 items-center gap-2"
                @submit.prevent="submitSearch"
            >
                <input
                    v-model="searchTerm"
                    type="text"
                    placeholder="Search by reservation no., guest name, mobile or e-mail"
                    class="block w-full max-w-sm rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
                <button
                    type="submit"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                >
                    Search
                </button>
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-medium text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]"
                    @click="resetSearch"
                >
                    Reset
                </button>
            </form>
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                @click="advancedSearchOpen = true"
            >
                Advanced Search
            </button>
            <button
                type="button"
                title="Column settings"
                class="rounded-md border border-[#e3e3e0] p-2 text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                @click="columnSettingsOpen = true"
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
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z"
                    />
                </svg>
            </button>
        </div>

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
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                >
                    <tr v-for="booking in bookings.data" :key="booking.id">
                        <td
                            v-for="column in visibleColumns"
                            :key="column.key"
                            class="px-4 py-3 text-sm whitespace-nowrap text-[#706f6c] dark:text-[#A1A09A]"
                            :class="{
                                'max-w-xs truncate':
                                    column.key === 'special_instructions' ||
                                    column.key === 'internal_notes',
                                'text-[#1b1b18] dark:text-[#EDEDEC]':
                                    column.key === 'booking_ref_id' ||
                                    column.key === 'guest_name',
                            }"
                        >
                            <Link
                                v-if="
                                    column.key === 'booking_ref_id' && property
                                "
                                :href="
                                    admin.bookings.show({
                                        property,
                                        booking: booking.id,
                                    }).url
                                "
                                class="cursor-pointer font-medium text-[#0284c7] hover:underline"
                            >
                                {{ cellValue(booking, column.key) }}
                            </Link>
                            <template v-else>
                                {{ cellValue(booking, column.key) }}
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!bookings.data.length">
                        <td
                            :colspan="visibleColumns.length"
                            class="px-4 py-8 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            No bookings found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex items-center justify-between">
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                {{ bookings.total }} booking{{
                    bookings.total === 1 ? '' : 's'
                }}
            </p>
            <div v-if="bookings.links.length > 3" class="flex flex-wrap gap-1">
                <template v-for="(link, index) in bookings.links" :key="index">
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

        <BookingAdvancedSearchModal
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
    </template>
</template>
