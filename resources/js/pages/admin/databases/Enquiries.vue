<script setup lang="ts">
import ListViewButton from '@/components/ListViewButton.vue';
import type { TableColumn } from '@/components/TableColumnSettingsModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useColumnVisibility } from '@/lib/useColumnVisibility';
import admin from '@/routes/admin';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { calendarDate, usePropertyTime } from '@/lib/usePropertyTime';

/** Times arrive in UTC and are shown in the property's timezone. */
const propertyTime = usePropertyTime();

defineOptions({ layout: AdminLayout });

type EnquiryRow = {
    id: number;
    booking_ref_id: string;
    guest_name: string | null;
    guest_email: string | null;
    guest_mobile: string | null;
    source: string;
    check_in: string | null;
    check_out: string | null;
    nights: number;
    rooms_count: number;
    status: string;
    total: number;
    created_at: string | null;
    release_at: string | null;
};

const props = defineProps<{
    property?: { id: number; name: string } | null;
    enquiries: {
        data: EnquiryRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { q?: string | null; reservation_status?: string | null };
}>();

const columns: TableColumn[] = [
    { key: 'booking_ref_id', label: 'Enquiry No.' },
    { key: 'guest_name', label: 'Guest Name' },
    { key: 'guest_contact', label: 'Guest Contact' },
    { key: 'check_in', label: 'Check-in' },
    { key: 'check_out', label: 'Check-out' },
    { key: 'nights', label: 'Nights' },
    { key: 'rooms_count', label: 'Rooms' },
    { key: 'total', label: 'Total' },
    { key: 'release_at', label: 'Release Date & Time' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Created' },
];
const shown = useColumnVisibility(
    'enquiries-column-visibility',
    columns.map((column) => column.key),
);
const visibleColumns = computed(() =>
    columns.filter((column) => shown[column.key]),
);

const searchTerm = ref(props.filters.q ?? '');
const statusFilter = ref(props.filters.reservation_status ?? '');

function reload(): void {
    router.get(
        admin.databases.enquiries.index().url,
        {
            property: props.property?.id,
            q: searchTerm.value || undefined,
            reservation_status: statusFilter.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function resetSearch(): void {
    searchTerm.value = '';
    statusFilter.value = '';
    reload();
}

function formatDate(value: string | null): string {
    return calendarDate(value);
}

function formatDateTime(value: string | null): string {
    return propertyTime.dateTime(value);
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
}
</script>

<template>
    <Head title="Enquiries" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Enquiries
        </h1>
        <Link
            v-if="property"
            :href="
                admin.bookings.create(property.id, { query: { enquiry: 1 } })
                    .url
            "
            class="inline-flex items-center rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-medium text-white hover:bg-[#d92a02]"
        >
            Log Enquiry
        </Link>
    </div>

    <div
        v-if="!property"
        class="rounded-lg border border-dashed border-[#e3e3e0] bg-white px-6 py-16 text-center text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#A1A09A]"
    >
        Select a property to view its enquiries.
    </div>

    <template v-else>
        <form
            class="mb-4 flex flex-wrap items-center gap-2"
            @submit.prevent="reload"
        >
            <input
                v-model="searchTerm"
                type="text"
                placeholder="Search by enquiry no., guest name, mobile or e-mail"
                class="block w-full max-w-sm rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            />
            <select
                v-model="statusFilter"
                aria-label="Status"
                class="rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                @change="reload"
            >
                <option value="">All statuses</option>
                <option value="enquiry">Enquiry</option>
                <option value="expired_quote">Expired Quote</option>
            </select>
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
            <ListViewButton
                class="ml-auto"
                :columns="columns"
                :visibility="shown"
            />
        </form>

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
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide whitespace-nowrap text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-[#e3e3e0] bg-white text-sm whitespace-nowrap text-[#706f6c] dark:divide-[#3E3E3A] dark:bg-[#161615] dark:text-[#A1A09A]"
                >
                    <tr v-for="enquiry in enquiries.data" :key="enquiry.id">
                        <td v-if="shown.booking_ref_id" class="px-4 py-3">
                            <Link
                                :href="
                                    admin.bookings.show({
                                        property: property.id,
                                        booking: enquiry.id,
                                    }).url
                                "
                                class="font-medium text-[#0284c7] hover:underline"
                            >
                                {{ enquiry.booking_ref_id }}
                            </Link>
                        </td>
                        <td
                            v-if="shown.guest_name"
                            class="px-4 py-3 text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ enquiry.guest_name ?? '—' }}
                        </td>
                        <td v-if="shown.guest_contact" class="px-4 py-3">
                            {{
                                enquiry.guest_mobile ||
                                enquiry.guest_email ||
                                '—'
                            }}
                        </td>
                        <td v-if="shown.check_in" class="px-4 py-3">
                            {{ formatDate(enquiry.check_in) }}
                        </td>
                        <td v-if="shown.check_out" class="px-4 py-3">
                            {{ formatDate(enquiry.check_out) }}
                        </td>
                        <td v-if="shown.nights" class="px-4 py-3">
                            {{ enquiry.nights }}
                        </td>
                        <td v-if="shown.rooms_count" class="px-4 py-3">
                            {{ enquiry.rooms_count }}
                        </td>
                        <td v-if="shown.total" class="px-4 py-3">
                            {{ formatCurrency(enquiry.total) }}
                        </td>
                        <td v-if="shown.release_at" class="px-4 py-3">
                            {{ formatDateTime(enquiry.release_at) }}
                        </td>
                        <td v-if="shown.status" class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="
                                    enquiry.status === 'enquiry'
                                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                                        : 'bg-gray-100 text-gray-600 dark:bg-[#262625] dark:text-[#A1A09A]'
                                "
                            >
                                {{
                                    enquiry.status === 'enquiry'
                                        ? 'Enquiry'
                                        : 'Expired Quote'
                                }}
                            </span>
                        </td>
                        <td v-if="shown.created_at" class="px-4 py-3">
                            {{ propertyTime.date(enquiry.created_at) }}
                        </td>
                    </tr>
                    <tr v-if="!enquiries.data.length">
                        <td
                            :colspan="visibleColumns.length || 1"
                            class="px-4 py-8 text-center"
                        >
                            No enquiries found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex items-center justify-between">
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                {{ enquiries.total }} enquir{{
                    enquiries.total === 1 ? 'y' : 'ies'
                }}
            </p>
            <div v-if="enquiries.links.length > 3" class="flex flex-wrap gap-1">
                <template v-for="(link, index) in enquiries.links" :key="index">
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
    </template>
</template>
