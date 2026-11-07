<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useCan } from '@/lib/useCan';
import { calendarDate, usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineOptions({ layout: AdminLayout });

type WaitlistRow = {
    id: number;
    guest_name: string;
    phone: string | null;
    email: string | null;
    room_category_id: number | null;
    room_category: string;
    check_in: string;
    check_out: string;
    nights: number;
    rooms: number;
    adults: number;
    children: number;
    notes: string | null;
    source: string;
    status: string;
    status_label: string;
    is_open: boolean;
    available_since: string | null;
    booking_id: number | null;
    booking_ref_id: string | null;
    created_at: string | null;
    created_by: string | null;
};

const props = defineProps<{
    property: { id: number; name: string } | null;
    requests: {
        data: WaitlistRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    } | null;
    filters: { q?: string; status?: string };
    counts: Record<string, number>;
    roomCategories: { id: number; name: string }[];
    statuses: { value: string; label: string }[];
}>();

const propertyTime = usePropertyTime();
const can = useCan();
const canManage = computed(() => can('database.waitlist.manage'));

const searchTerm = ref(props.filters.q ?? '');
const statusFilter = ref(props.filters.status ?? '');

function reload(): void {
    router.get(
        admin.databases.waitlist.index().url,
        {
            property: props.property?.id,
            q: searchTerm.value || undefined,
            status: statusFilter.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function resetSearch(): void {
    searchTerm.value = '';
    statusFilter.value = '';
    reload();
}

const availableCount = computed(() => props.counts.available ?? 0);
const waitingCount = computed(() => props.counts.waiting ?? 0);

function statusClass(status: string): string {
    switch (status) {
        case 'available':
            return 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300';
        case 'waiting':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300';
        case 'converted':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300';
        default:
            return 'bg-gray-100 text-gray-600 dark:bg-[#262625] dark:text-[#A1A09A]';
    }
}

function sourceLabel(source: string): string {
    return source === 'booking_engine' ? 'Booking engine' : 'Front desk';
}

/* Actions */

function convert(item: WaitlistRow): void {
    if (!props.property) {
        return;
    }

    router.post(
        admin.databases.waitlist.convert({
            property: props.property.id,
            waitlistRequest: item.id,
        }).url,
        {},
        { preserveScroll: true },
    );
}

function cancel(item: WaitlistRow): void {
    if (!props.property) {
        return;
    }

    router.patch(
        admin.databases.waitlist.cancel({
            property: props.property.id,
            waitlistRequest: item.id,
        }).url,
        {},
        { preserveScroll: true },
    );
}

function recheck(): void {
    if (!props.property) {
        return;
    }

    router.post(
        admin.databases.waitlist.refresh(props.property.id).url,
        {},
        { preserveScroll: true },
    );
}

/* Add request */

const formOpen = ref(false);

function todayIso(): string {
    return propertyTime.today();
}

function addDaysIso(date: string, days: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);

    return value.toISOString().slice(0, 10);
}

const form = useForm({
    guest_name: '',
    phone: '',
    email: '',
    room_category_id: '' as number | '',
    check_in: todayIso(),
    check_out: addDaysIso(todayIso(), 1),
    rooms: 1,
    adults: 2,
    children: 0,
    notes: '',
});

function openForm(): void {
    form.reset();
    form.clearErrors();
    form.check_in = todayIso();
    form.check_out = addDaysIso(todayIso(), 1);
    formOpen.value = true;
}

function submit(): void {
    if (!props.property) {
        return;
    }

    form.transform((data) => ({
        ...data,
        room_category_id:
            data.room_category_id === '' ? null : data.room_category_id,
    })).post(admin.databases.waitlist.store(props.property.id).url, {
        preserveScroll: true,
        onSuccess: () => (formOpen.value = false),
    });
}

const inputClass =
    'w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const labelClass =
    'mb-1 block text-xs font-medium text-[#1b1b18] dark:text-[#EDEDEC]';
</script>

<template>
    <Head title="Waitlist" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1
                class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Waitlist
            </h1>
            <p class="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Hold requests for sold-out dates. They turn "Rooms available"
                when inventory frees up; convert one to book it.
            </p>
        </div>
        <div v-if="property && canManage" class="flex items-center gap-2">
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                @click="recheck"
            >
                Re-check availability
            </button>
            <button
                type="button"
                class="rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-medium text-white hover:bg-[#d92a02]"
                @click="openForm"
            >
                Add request
            </button>
        </div>
    </div>

    <div
        v-if="!property || !requests"
        class="rounded-lg border border-dashed border-[#e3e3e0] bg-white px-6 py-16 text-center text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#A1A09A]"
    >
        Select a property to view its waitlist.
    </div>

    <template v-else>
        <div
            v-if="availableCount"
            class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-300"
            data-test="waitlist-available-banner"
        >
            Rooms have freed up for {{ availableCount }} request{{
                availableCount === 1 ? '' : 's'
            }}. Convert them before the rooms are sold again.
        </div>

        <form
            class="mb-4 flex flex-wrap items-center gap-2"
            @submit.prevent="reload"
        >
            <input
                v-model="searchTerm"
                type="text"
                placeholder="Search by guest name, phone or e-mail"
                class="block w-full max-w-sm rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            />
            <select
                v-model="statusFilter"
                aria-label="Status"
                class="rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                @change="reload"
            >
                <option value="">All statuses</option>
                <option
                    v-for="status in statuses"
                    :key="status.value"
                    :value="status.value"
                >
                    {{ status.label }}
                </option>
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
            <span class="ml-auto text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >{{ waitingCount }} waiting · {{ availableCount }} with rooms
                available</span
            >
        </form>

        <div
            class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <table
                class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
            >
                <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                    <tr
                        class="text-left text-xs font-medium tracking-wide whitespace-nowrap text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        <th class="px-4 py-3">Guest</th>
                        <th class="px-4 py-3">Contact</th>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3">Check-in</th>
                        <th class="px-4 py-3">Check-out</th>
                        <th class="px-4 py-3">Nights</th>
                        <th class="px-4 py-3">Rooms · Guests</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Source</th>
                        <th class="px-4 py-3">Added</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-[#e3e3e0] bg-white text-sm whitespace-nowrap text-[#706f6c] dark:divide-[#3E3E3A] dark:bg-[#161615] dark:text-[#A1A09A]"
                >
                    <tr
                        v-for="item in requests.data"
                        :key="item.id"
                        :class="
                            item.status === 'available'
                                ? 'bg-green-50/60 dark:bg-green-950/30'
                                : ''
                        "
                        data-test="waitlist-row"
                    >
                        <td
                            class="px-4 py-3 text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ item.guest_name }}
                            <p
                                v-if="item.notes"
                                class="max-w-xs truncate text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                :title="item.notes"
                            >
                                {{ item.notes }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            {{ item.phone || item.email || '—' }}
                        </td>
                        <td class="px-4 py-3">{{ item.room_category }}</td>
                        <td class="px-4 py-3">
                            {{ calendarDate(item.check_in) }}
                        </td>
                        <td class="px-4 py-3">
                            {{ calendarDate(item.check_out) }}
                        </td>
                        <td class="px-4 py-3">{{ item.nights }}</td>
                        <td class="px-4 py-3">
                            {{ item.rooms }} · {{ item.adults }}A<template
                                v-if="item.children"
                            >
                                {{ item.children }}C</template
                            >
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="statusClass(item.status)"
                                >{{ item.status_label }}</span
                            >
                            <p
                                v-if="
                                    item.status === 'available' &&
                                    item.available_since
                                "
                                class="mt-1 text-xs"
                            >
                                since
                                {{
                                    propertyTime.dateTime(item.available_since)
                                }}
                            </p>
                            <Link
                                v-if="
                                    item.status === 'converted' &&
                                    item.booking_id
                                "
                                :href="
                                    admin.bookings.show({
                                        property: property.id,
                                        booking: item.booking_id,
                                    }).url
                                "
                                class="mt-1 block text-xs text-[#0284c7] hover:underline"
                            >
                                {{ item.booking_ref_id }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            {{ sourceLabel(item.source) }}
                        </td>
                        <td class="px-4 py-3">
                            {{ propertyTime.date(item.created_at)
                            }}<template v-if="item.created_by">
                                · {{ item.created_by }}</template
                            >
                        </td>
                        <td class="px-4 py-3">
                            <div
                                v-if="item.is_open && canManage"
                                class="flex items-center gap-3"
                            >
                                <button
                                    type="button"
                                    class="text-sm font-medium hover:underline"
                                    :class="
                                        item.status === 'available'
                                            ? 'text-green-700 dark:text-green-400'
                                            : 'text-[#0284c7]'
                                    "
                                    data-test="waitlist-convert"
                                    @click="convert(item)"
                                >
                                    Convert to booking
                                </button>
                                <button
                                    type="button"
                                    class="text-sm text-[#706f6c] hover:text-red-600"
                                    @click="cancel(item)"
                                >
                                    Cancel
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!requests.data.length">
                        <td colspan="11" class="px-4 py-8 text-center">
                            No waitlist requests.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex items-center justify-between">
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                {{ requests.total }} request{{
                    requests.total === 1 ? '' : 's'
                }}
            </p>
            <div v-if="requests.links.length > 3" class="flex flex-wrap gap-1">
                <template v-for="(link, index) in requests.links" :key="index">
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

        <div
            v-if="formOpen"
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/30 p-4 pt-16"
            @click.self="formOpen = false"
        >
            <form
                class="w-full max-w-2xl rounded-lg border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
                @submit.prevent="submit"
            >
                <div
                    class="flex items-center justify-between bg-[#1b1b18] px-6 py-3"
                >
                    <h2 class="font-display text-base font-semibold text-white">
                        Add Waitlist Request
                    </h2>
                    <button
                        type="button"
                        class="text-white/80 hover:text-white"
                        aria-label="Close"
                        @click="formOpen = false"
                    >
                        ✕
                    </button>
                </div>

                <div class="flex flex-col gap-4 px-6 py-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="wl_guest_name" :class="labelClass"
                                >Guest name*</label
                            >
                            <input
                                id="wl_guest_name"
                                v-model="form.guest_name"
                                type="text"
                                :class="inputClass"
                            />
                            <p
                                v-if="form.errors.guest_name"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.guest_name }}
                            </p>
                        </div>
                        <div>
                            <label for="wl_category" :class="labelClass"
                                >Room category</label
                            >
                            <select
                                id="wl_category"
                                v-model="form.room_category_id"
                                :class="inputClass"
                            >
                                <option value="">Any category</option>
                                <option
                                    v-for="category in roomCategories"
                                    :key="category.id"
                                    :value="category.id"
                                >
                                    {{ category.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label for="wl_phone" :class="labelClass"
                                >Phone</label
                            >
                            <input
                                id="wl_phone"
                                v-model="form.phone"
                                type="tel"
                                :class="inputClass"
                            />
                            <p
                                v-if="form.errors.phone"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.phone }}
                            </p>
                        </div>
                        <div>
                            <label for="wl_email" :class="labelClass"
                                >E-mail</label
                            >
                            <input
                                id="wl_email"
                                v-model="form.email"
                                type="email"
                                :class="inputClass"
                            />
                            <p
                                v-if="form.errors.email"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.email }}
                            </p>
                        </div>
                        <div>
                            <label for="wl_check_in" :class="labelClass"
                                >Check-in*</label
                            >
                            <input
                                id="wl_check_in"
                                v-model="form.check_in"
                                type="date"
                                :class="inputClass"
                            />
                            <p
                                v-if="form.errors.check_in"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.check_in }}
                            </p>
                        </div>
                        <div>
                            <label for="wl_check_out" :class="labelClass"
                                >Check-out*</label
                            >
                            <input
                                id="wl_check_out"
                                v-model="form.check_out"
                                type="date"
                                :min="form.check_in"
                                :class="inputClass"
                            />
                            <p
                                v-if="form.errors.check_out"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.check_out }}
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label for="wl_rooms" :class="labelClass"
                                >Rooms*</label
                            >
                            <input
                                id="wl_rooms"
                                v-model.number="form.rooms"
                                type="number"
                                min="1"
                                max="20"
                                :class="inputClass"
                            />
                            <p
                                v-if="form.errors.rooms"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.rooms }}
                            </p>
                        </div>
                        <div>
                            <label for="wl_adults" :class="labelClass"
                                >Adults*</label
                            >
                            <input
                                id="wl_adults"
                                v-model.number="form.adults"
                                type="number"
                                min="1"
                                max="20"
                                :class="inputClass"
                            />
                        </div>
                        <div>
                            <label for="wl_children" :class="labelClass"
                                >Children</label
                            >
                            <input
                                id="wl_children"
                                v-model.number="form.children"
                                type="number"
                                min="0"
                                max="20"
                                :class="inputClass"
                            />
                        </div>
                    </div>

                    <div>
                        <label for="wl_notes" :class="labelClass">Notes</label>
                        <textarea
                            id="wl_notes"
                            v-model="form.notes"
                            rows="2"
                            :class="inputClass"
                        />
                    </div>
                </div>

                <div class="flex items-center gap-4 px-6 pb-5">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-semibold text-white hover:bg-[#d92a02] disabled:opacity-60"
                    >
                        {{ form.processing ? 'Saving…' : 'SAVE' }}
                    </button>
                    <button
                        type="button"
                        class="text-sm font-medium text-[#0284c7] hover:underline"
                        @click="formOpen = false"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </template>
</template>
