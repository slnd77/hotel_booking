<script setup lang="ts">
import TableColumnSettingsModal, {
    type TableColumn,
} from '@/components/TableColumnSettingsModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useColumnVisibility } from '@/lib/useColumnVisibility';
import { usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** Times arrive in UTC and are shown in the property's timezone. */
const propertyTime = usePropertyTime();

defineOptions({ layout: AdminLayout });

type FolioRow = {
    id: number;
    type: string;
    type_label: string;
    status: string;
    currency: string;
    owner_name: string | null;
    owner_detail: string | null;
    booking_id: number | null;
    booking_ref_id: string | null;
    booking_status: string | null;
    room: string | null;
    check_in: string | null;
    check_out: string | null;
    windows: number;
    charges: number;
    payments: number;
    balance: number;
    created_at: string | null;
    updated_at: string | null;
};

type Option = { value: string; label: string };

const columns: TableColumn[] = [
    { key: 'id', label: 'Folio No.' },
    { key: 'type_label', label: 'Type' },
    { key: 'status', label: 'Status' },
    { key: 'currency', label: 'Currency' },
    { key: 'owner_name', label: 'Owner' },
    { key: 'owner_detail', label: 'Owner Contact / GSTIN' },
    { key: 'booking_ref_id', label: 'Reservation No.' },
    { key: 'booking_status', label: 'Reservation Status' },
    { key: 'room', label: 'Room' },
    { key: 'check_in', label: 'Check-in' },
    { key: 'check_out', label: 'Check-out' },
    { key: 'windows', label: 'Windows' },
    { key: 'charges', label: 'Charges' },
    { key: 'payments', label: 'Payments' },
    { key: 'balance', label: 'Balance' },
    { key: 'created_at', label: 'Created' },
    { key: 'updated_at', label: 'Last Updated' },
];

const props = defineProps<{
    property?: { id: number; name: string } | null;
    folios: {
        data: FolioRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: Record<string, string | number | null | undefined>;
    typeOptions: Option[];
    statusOptions: Option[];
}>();

const searchTerm = ref((props.filters.q as string) ?? '');
const columnSettingsOpen = ref(false);

const columnVisibility = useColumnVisibility(
    'folios-column-visibility',
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
        admin.databases.folios.index().url,
        {
            property: props.property?.id,
            ...props.filters,
            ...params,
            page: undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function submitSearch(): void {
    reloadWith({ q: searchTerm.value || undefined });
}

function resetSearch(): void {
    searchTerm.value = '';
    router.get(
        admin.databases.folios.index().url,
        { property: props.property?.id },
        { preserveScroll: true, replace: true },
    );
}

function filterBy(key: string, event: Event): void {
    reloadWith({
        [key]: (event.target as HTMLSelectElement).value || undefined,
    });
}

const downloadHref = computed(
    () =>
        admin.databases.folios.download({
            query: { property: props.property?.id, ...props.filters },
        }).url,
);

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.split('-');
    return `${day}/${month}/${year}`;
}

function formatAmount(value: number, currency: string): string {
    return `${currency} ${new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)}`;
}

const statusClasses: Record<string, string> = {
    open: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    locked: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    settled:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
};

function cellValue(folio: FolioRow, key: string): string {
    switch (key) {
        case 'id':
            return `#${folio.id}`;
        case 'type_label':
            return folio.type_label;
        case 'status':
            return folio.status.charAt(0).toUpperCase() + folio.status.slice(1);
        case 'currency':
            return folio.currency;
        case 'owner_name':
            return folio.owner_name || '—';
        case 'owner_detail':
            return folio.owner_detail || '—';
        case 'booking_ref_id':
            return folio.booking_ref_id ?? '—';
        case 'booking_status':
            return folio.booking_status ?? '—';
        case 'room':
            return folio.room ?? '—';
        case 'check_in':
            return formatDate(folio.check_in);
        case 'check_out':
            return formatDate(folio.check_out);
        case 'windows':
            return String(folio.windows);
        case 'charges':
            return formatAmount(folio.charges, folio.currency);
        case 'payments':
            return formatAmount(folio.payments, folio.currency);
        case 'balance':
            return formatAmount(folio.balance, folio.currency);
        case 'created_at':
            return propertyTime.dateTime(folio.created_at);
        case 'updated_at':
            return propertyTime.dateTime(folio.updated_at);
        default:
            return '—';
    }
}

const selectClass =
    'rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]';
</script>

<template>
    <Head title="Folios" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Folios
        </h1>
        <a
            v-if="property"
            :href="downloadHref"
            class="inline-flex items-center rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
        >
            Download Folio List
        </a>
    </div>

    <div
        v-if="!property"
        class="rounded-lg border border-dashed border-[#e3e3e0] bg-white px-6 py-16 text-center text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#A1A09A]"
    >
        Select a property to view its folios.
    </div>

    <template v-else>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <form
                class="flex flex-1 items-center gap-2"
                @submit.prevent="submitSearch"
            >
                <input
                    v-model="searchTerm"
                    type="text"
                    placeholder="Search by folio no., reservation no., guest or company"
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
            <select
                :value="filters.type ?? ''"
                :class="selectClass"
                aria-label="Type"
                @change="filterBy('type', $event)"
            >
                <option value="">All types</option>
                <option
                    v-for="option in typeOptions"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
            <select
                :value="filters.status ?? ''"
                :class="selectClass"
                aria-label="Status"
                @change="filterBy('status', $event)"
            >
                <option value="">All statuses</option>
                <option
                    v-for="option in statusOptions"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
            <select
                :value="filters.balance ?? ''"
                :class="selectClass"
                aria-label="Balance"
                @change="filterBy('balance', $event)"
            >
                <option value="">Any balance</option>
                <option value="due">Balance due</option>
                <option value="credit">In credit</option>
                <option value="zero">Zero balance</option>
            </select>
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
                            class="px-4 py-3 text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            :class="
                                [
                                    'charges',
                                    'payments',
                                    'balance',
                                    'windows',
                                ].includes(column.key)
                                    ? 'text-right'
                                    : 'text-left'
                            "
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                >
                    <tr v-for="folio in folios.data" :key="folio.id">
                        <td
                            v-for="column in visibleColumns"
                            :key="column.key"
                            class="px-4 py-3 text-sm whitespace-nowrap text-[#706f6c] dark:text-[#A1A09A]"
                            :class="{
                                'text-right tabular-nums': [
                                    'charges',
                                    'payments',
                                    'balance',
                                    'windows',
                                ].includes(column.key),
                                'text-[#1b1b18] dark:text-[#EDEDEC]':
                                    column.key === 'id' ||
                                    column.key === 'owner_name',
                                'font-semibold text-red-600 dark:text-red-400':
                                    column.key === 'balance' &&
                                    folio.balance > 0.004,
                                'font-semibold text-emerald-700 dark:text-emerald-400':
                                    column.key === 'balance' &&
                                    folio.balance < -0.004,
                            }"
                        >
                            <Link
                                v-if="
                                    column.key === 'booking_ref_id' &&
                                    folio.booking_id
                                "
                                :href="
                                    admin.bookings.show({
                                        property: property.id,
                                        booking: folio.booking_id,
                                    }).url
                                "
                                class="font-medium text-[#0284c7] hover:underline"
                            >
                                {{ cellValue(folio, column.key) }}
                            </Link>
                            <span
                                v-else-if="column.key === 'status'"
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="statusClasses[folio.status] ?? ''"
                            >
                                {{ cellValue(folio, column.key) }}
                            </span>
                            <template v-else>
                                {{ cellValue(folio, column.key) }}
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!folios.data.length">
                        <td
                            :colspan="visibleColumns.length"
                            class="px-4 py-8 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            No folios found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex items-center justify-between">
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                {{ folios.total }} folio{{ folios.total === 1 ? '' : 's' }}
            </p>
            <div v-if="folios.links.length > 3" class="flex flex-wrap gap-1">
                <template v-for="(link, index) in folios.links" :key="index">
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

        <TableColumnSettingsModal
            :show="columnSettingsOpen"
            :columns="columns"
            :model-value="columnVisibility"
            @close="columnSettingsOpen = false"
            @save="onColumnsSaved"
        />
    </template>
</template>
