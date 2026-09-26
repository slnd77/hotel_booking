<script setup lang="ts">
import GuestAdvancedSearchModal, {
    type GuestAdvancedSearchFilters,
} from '@/components/GuestAdvancedSearchModal.vue';
import GuestFormModal from '@/components/GuestFormModal.vue';
import GuestMergeModal from '@/components/GuestMergeModal.vue';
import GuestUploadModal from '@/components/GuestUploadModal.vue';
import Modal from '@/components/Modal.vue';
import type { PhoneCountryOption } from '@/components/PhoneCountryCodeSelect.vue';
import TableColumnSettingsModal, {
    type TableColumn,
} from '@/components/TableColumnSettingsModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useCan } from '@/lib/useCan';
import { useColumnVisibility } from '@/lib/useColumnVisibility';
import admin from '@/routes/admin';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { calendarDate, usePropertyTime } from '@/lib/usePropertyTime';

/** Times arrive in UTC and are shown in the property's timezone. */
const propertyTime = usePropertyTime();

defineOptions({ layout: AdminLayout });

type GuestRow = {
    id: number;
    title: string | null;
    first_name: string;
    last_name: string | null;
    mobile: string;
    email: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    country: string | null;
    postal_code: string | null;
    guest_preferences: string | null;
    nationality: string | null;
    dob: string | null;
    anniversary: string | null;
    frequent_flyer: string | null;
    vehicle_number: string | null;
    passport_number: string | null;
    passport_issue_place: string | null;
    passport_issue_date: string | null;
    passport_validity: string | null;
    visa_number: string | null;
    visa_issue_date: string | null;
    visa_valid_till: string | null;
    visa_type: string | null;
    visa_issue_place: string | null;
    gender: string | null;
    place_of_birth: string | null;
    present_business_address: string | null;
    subscribe_newsletter: string;
    purpose_of_visit: string | null;
    created_at: string | null;
};

const columns: TableColumn[] = [
    { key: 'id', label: 'Guest ID' },
    { key: 'title', label: 'Title' },
    { key: 'first_name', label: 'First Name' },
    { key: 'last_name', label: 'Last Name' },
    { key: 'mobile', label: 'Mobile' },
    { key: 'email', label: 'E-mail' },
    { key: 'address', label: 'Address' },
    { key: 'city', label: 'City' },
    { key: 'state', label: 'State' },
    { key: 'country', label: 'Country' },
    { key: 'postal_code', label: 'Postal Code/ ZIP Code' },
    { key: 'guest_preferences', label: 'Preferences' },
    { key: 'nationality', label: 'Nationality' },
    { key: 'dob', label: 'Date of Birth' },
    { key: 'anniversary', label: 'Anniversary' },
    { key: 'frequent_flyer', label: 'Frequent flyer' },
    { key: 'vehicle_number', label: 'Vehicle Number' },
    { key: 'documents', label: 'Documents' },
    { key: 'passport_number', label: 'Passport No.' },
    { key: 'passport_issue_place', label: 'Place of issue of passport' },
    { key: 'passport_issue_date', label: 'Date of issue of passport' },
    { key: 'passport_validity', label: 'Passport valid till' },
    { key: 'visa_number', label: 'Visa no.' },
    { key: 'visa_issue_date', label: 'Date of issue of visa' },
    { key: 'visa_valid_till', label: 'Visa valid till' },
    { key: 'visa_type', label: 'Type of Visa' },
    { key: 'visa_issue_place', label: 'Place of issue of visa' },
    { key: 'gender', label: 'Gender' },
    { key: 'place_of_birth', label: 'Place of birth' },
    { key: 'present_business_address', label: 'Present/Business address' },
    { key: 'loyalty_card_type', label: 'Loyalty Card Type' },
    { key: 'loyalty_card_number', label: 'Loyalty Card Number' },
    {
        key: 'subscribe_newsletter',
        label: 'Subscribe to Newsletter / Marketing emails',
    },
    { key: 'guest_platform', label: 'Guest Platform' },
    { key: 'purpose_of_visit', label: 'Purpose of Visit' },
    { key: 'created_at', label: 'Guest Creation Date' },
    { key: 'activity_logs', label: 'Activity Logs' },
];

const props = defineProps<{
    property?: { id: number; name: string; country_id: number | null };
    countries: PhoneCountryOption[];
    guests: {
        data: GuestRow[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: Record<string, string | number | null | undefined>;
}>();

const searchTerm = ref((props.filters.q as string) ?? '');
const advancedSearchOpen = ref(false);
const addGuestOpen = ref(false);
const uploadGuestsOpen = ref(false);
const columnSettingsOpen = ref(false);

const columnVisibility = useColumnVisibility(
    'guests-column-visibility',
    columns.map((column) => column.key),
);

const visibleColumns = computed(() =>
    columns.filter((column) => columnVisibility[column.key]),
);

function onColumnsSaved(next: Record<string, boolean>): void {
    Object.assign(columnVisibility, next);
    columnSettingsOpen.value = false;
}

function cellValue(guest: GuestRow, key: string): string {
    switch (key) {
        case 'id':
            return String(guest.id);
        case 'title':
            return guest.title ?? '—';
        case 'first_name':
            return guest.first_name;
        case 'last_name':
            return guest.last_name ?? '—';
        case 'mobile':
            return guest.mobile || '—';
        case 'email':
            return guest.email ?? '—';
        case 'address':
            return guest.address ?? '—';
        case 'city':
            return guest.city ?? '—';
        case 'state':
            return guest.state ?? '—';
        case 'country':
            return guest.country ?? '—';
        case 'postal_code':
            return guest.postal_code ?? '—';
        case 'guest_preferences':
            return guest.guest_preferences ?? '—';
        case 'nationality':
            return guest.nationality ?? '—';
        case 'dob':
            return formatDate(guest.dob);
        case 'anniversary':
            return formatDate(guest.anniversary);
        case 'frequent_flyer':
            return guest.frequent_flyer ?? '—';
        case 'vehicle_number':
            return guest.vehicle_number ?? '—';
        case 'passport_number':
            return guest.passport_number ?? '—';
        case 'passport_issue_place':
            return guest.passport_issue_place ?? '—';
        case 'passport_issue_date':
            return formatDate(guest.passport_issue_date);
        case 'passport_validity':
            return formatDate(guest.passport_validity);
        case 'visa_number':
            return guest.visa_number ?? '—';
        case 'visa_issue_date':
            return formatDate(guest.visa_issue_date);
        case 'visa_valid_till':
            return formatDate(guest.visa_valid_till);
        case 'visa_type':
            return guest.visa_type ?? '—';
        case 'visa_issue_place':
            return guest.visa_issue_place ?? '—';
        case 'gender':
            return guest.gender ?? '—';
        case 'place_of_birth':
            return guest.place_of_birth ?? '—';
        case 'present_business_address':
            return guest.present_business_address ?? '—';
        case 'subscribe_newsletter':
            return guest.subscribe_newsletter;
        case 'purpose_of_visit':
            return guest.purpose_of_visit ?? '—';
        case 'created_at':
            return propertyTime.date(guest.created_at);
        default:
            return '—';
    }
}

function reloadWith(
    params: Record<string, string | number | null | undefined>,
): void {
    router.get(
        admin.databases.guests.index().url,
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
        admin.databases.guests.index().url,
        { property: props.property?.id },
        { preserveScroll: true, replace: true },
    );
}

function applyAdvancedSearch(filters: GuestAdvancedSearchFilters): void {
    reloadWith({ ...filters, q: undefined, page: undefined });
    advancedSearchOpen.value = false;
}

const downloadHref = computed(
    () =>
        admin.databases.guests.download({
            query: { property: props.property?.id, ...props.filters },
        }).url,
);

function onGuestSaved(): void {
    addGuestOpen.value = false;
    router.reload({ only: ['guests'] });
}

function onGuestsUploaded(): void {
    uploadGuestsOpen.value = false;
}

/** Guest data requests (GDPR / DPDP): a copy, erasure, or no more marketing. */
const can = useCan();
const canManagePrivacy = computed(() => can('database.guests.privacy'));
/** The same person entered twice: merge the duplicate into this guest. */
const canMerge = computed(() => can('database.guests.merge'));
const mergeGuest = ref<GuestRow | null>(null);
const showActions = computed(() => canManagePrivacy.value || canMerge.value);
const privacyGuest = ref<GuestRow | null>(null);
const privacyAction = ref<'erase' | 'withdraw' | null>(null);
const privacyError = ref('');
const privacyProcessing = ref(false);

function askPrivacy(guest: GuestRow, action: 'erase' | 'withdraw'): void {
    privacyGuest.value = guest;
    privacyAction.value = action;
    privacyError.value = '';
}

function confirmPrivacy(): void {
    if (!privacyGuest.value || !privacyAction.value) {
        return;
    }

    const route =
        privacyAction.value === 'erase'
            ? admin.databases.guests.privacy.erase
            : admin.databases.guests.privacy.withdrawMarketing;
    privacyProcessing.value = true;
    router.post(
        route({ guest: privacyGuest.value.id }).url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => (privacyGuest.value = null),
            onError: (errors) =>
                (privacyError.value = Object.values(errors).join(' ')),
            onFinish: () => (privacyProcessing.value = false),
        },
    );
}

function formatDate(value: string | null): string {
    return calendarDate(value);
}
</script>

<template>
    <Head title="Guests" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Guests
        </h1>
        <div v-if="property" class="flex items-center gap-3">
            <a
                :href="downloadHref"
                class="inline-flex items-center rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
            >
                Download Guest List
            </a>
            <button
                type="button"
                class="inline-flex items-center rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                @click="uploadGuestsOpen = true"
            >
                Upload Guests
            </button>
            <button
                type="button"
                class="inline-flex items-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                @click="addGuestOpen = true"
            >
                + Add Guest
            </button>
        </div>
    </div>

    <div
        v-if="!property"
        class="rounded-lg border border-dashed border-[#e3e3e0] bg-white px-6 py-16 text-center text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#A1A09A]"
    >
        Select a property to view its guests.
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
                    placeholder="Search by name, mobile, e-mail or Guest ID"
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
                        <th
                            v-if="showActions"
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                >
                    <tr v-for="guest in guests.data" :key="guest.id">
                        <td
                            v-for="column in visibleColumns"
                            :key="column.key"
                            class="px-4 py-3 text-sm whitespace-nowrap text-[#706f6c] dark:text-[#A1A09A]"
                            :class="{
                                'max-w-xs truncate':
                                    column.key === 'guest_preferences',
                                'text-[#1b1b18] dark:text-[#EDEDEC]': [
                                    'id',
                                    'first_name',
                                    'last_name',
                                ].includes(column.key),
                            }"
                        >
                            {{ cellValue(guest, column.key) }}
                        </td>
                        <td
                            v-if="showActions"
                            class="space-x-3 px-4 py-3 text-xs whitespace-nowrap"
                        >
                            <button
                                v-if="canMerge"
                                type="button"
                                class="text-[#1b1b18] underline dark:text-[#EDEDEC]"
                                :data-test="`guest-merge-open-${guest.id}`"
                                @click="mergeGuest = guest"
                            >
                                Merge
                            </button>
                            <template v-if="canManagePrivacy">
                                <a
                                    :href="
                                        admin.databases.guests.privacy.export({
                                            guest: guest.id,
                                        }).url
                                    "
                                    class="text-[#f53003] underline"
                                    >Copy of data</a
                                >
                                <button
                                    v-if="guest.subscribe_newsletter === 'Yes'"
                                    type="button"
                                    class="text-[#706f6c] underline dark:text-[#A1A09A]"
                                    @click="askPrivacy(guest, 'withdraw')"
                                >
                                    Stop marketing
                                </button>
                                <button
                                    type="button"
                                    class="text-red-600 underline"
                                    @click="askPrivacy(guest, 'erase')"
                                >
                                    Erase
                                </button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!guests.data.length">
                        <td
                            :colspan="
                                visibleColumns.length + (showActions ? 1 : 0)
                            "
                            class="px-4 py-8 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            No guests found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex items-center justify-between">
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                {{ guests.total }} guest{{ guests.total === 1 ? '' : 's' }}
            </p>
            <div v-if="guests.links.length > 3" class="flex flex-wrap gap-1">
                <template v-for="(link, index) in guests.links" :key="index">
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

        <GuestAdvancedSearchModal
            :show="advancedSearchOpen"
            :filters="filters"
            @close="advancedSearchOpen = false"
            @search="applyAdvancedSearch"
        />

        <GuestFormModal
            :show="addGuestOpen"
            :property="property"
            :countries="countries"
            @close="addGuestOpen = false"
            @saved="onGuestSaved"
        />

        <GuestUploadModal
            :show="uploadGuestsOpen"
            :property-id="property?.id"
            @close="uploadGuestsOpen = false"
            @uploaded="onGuestsUploaded"
        />

        <Modal
            :show="privacyGuest !== null"
            :title="
                privacyAction === 'erase'
                    ? 'Erase guest data'
                    : 'Stop marketing'
            "
            @close="privacyGuest = null"
        >
            <div
                class="flex flex-col gap-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                <p v-if="privacyAction === 'erase'">
                    Erase the personal details of
                    <strong
                        >{{ privacyGuest?.first_name }}
                        {{ privacyGuest?.last_name }}</strong
                    >
                    (#{{ privacyGuest?.id }}): name, contact details, address,
                    documents, ID images and signatures. Bookings, invoices and
                    payments stay, under an anonymous name, because tax law
                    requires them. This can't be undone.
                </p>
                <p v-else>
                    {{ privacyGuest?.first_name }}
                    {{ privacyGuest?.last_name }} withdraws their marketing
                    consent and will get no more offers.
                </p>
                <p
                    v-if="privacyError"
                    class="rounded bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300"
                    role="alert"
                >
                    {{ privacyError }}
                </p>
                <div
                    class="flex justify-end gap-2 border-t border-[#e3e3e0] pt-3 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-3 py-1.5 dark:border-[#3E3E3A]"
                        @click="privacyGuest = null"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="privacyProcessing"
                        class="rounded-md px-3 py-1.5 font-semibold text-white disabled:opacity-50"
                        :class="
                            privacyAction === 'erase'
                                ? 'bg-red-600 hover:bg-red-700'
                                : 'bg-[#1b1b18] dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                        "
                        @click="confirmPrivacy"
                    >
                        {{
                            privacyAction === 'erase'
                                ? 'Erase personal data'
                                : 'Stop marketing'
                        }}
                    </button>
                </div>
            </div>
        </Modal>

        <GuestMergeModal :guest="mergeGuest" @close="mergeGuest = null" />

        <TableColumnSettingsModal
            :show="columnSettingsOpen"
            :columns="columns"
            :model-value="columnVisibility"
            @close="columnSettingsOpen = false"
            @save="onColumnsSaved"
        />
    </template>
</template>
