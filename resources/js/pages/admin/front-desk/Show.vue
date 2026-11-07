<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useCurrency } from '@/lib/useCurrency';
import { calendarDate } from '@/lib/usePropertyTime';
import { useUrlTab } from '@/lib/useUrlTab';
import admin from '@/routes/admin';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineOptions({ layout: AdminLayout });

type StayRow = {
    sub_booking_id: number;
    booking_id: number;
    booking_ref_id: string | null;
    guest: string | null;
    company?: string | null;
    phone: string | null;
    room: string | null;
    category: string | null;
    adults: number;
    children: number;
    check_in: string;
    check_out: string;
    status: 'hold' | 'booked' | 'checked_in' | 'checked_out' | 'no_show';
    source: string | null;
    balance: number;
    notes: string | null;
};

const props = defineProps<{
    property: { id: number; name: string };
    today: string;
    board: {
        date: string;
        is_today: boolean;
        kpis: {
            rooms_available: number;
            rooms_sold: number;
            out_of_order: number;
            vacant: number;
            occupancy_percent: number;
            adr: number;
            revpar: number;
            room_revenue: number;
            arrivals_expected: number;
            arrivals_done: number;
            departures_expected: number;
            departures_done: number;
            in_house_rooms: number;
            in_house_guests: number;
        };
        arrivals: StayRow[];
        departures: StayRow[];
        in_house: StayRow[];
    };
}>();

const { money } = useCurrency();
const tabs = ['arrivals', 'departures', 'in_house'] as const;
const activeTab = useUrlTab(tabs, 'arrivals');
const search = ref('');

const statusLabels: Record<StayRow['status'], string> = {
    hold: 'On hold',
    booked: 'Expected',
    checked_in: 'Checked in',
    checked_out: 'Checked out',
    no_show: 'No-show',
};

const statusClasses: Record<StayRow['status'], string> = {
    hold: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    booked: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    checked_in:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    checked_out:
        'bg-stone-200 text-stone-700 dark:bg-stone-800 dark:text-stone-300',
    no_show: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
};

const tabLabels = computed(() => ({
    arrivals: `Arrivals (${props.board.kpis.arrivals_done}/${props.board.kpis.arrivals_expected})`,
    departures: `Departures (${props.board.kpis.departures_done}/${props.board.kpis.departures_expected})`,
    in_house: `${props.board.is_today ? 'In-house' : 'Staying'} (${props.board.kpis.in_house_rooms})`,
}));

const rows = computed(() => {
    const term = search.value.trim().toLowerCase();
    const list = props.board[activeTab.value];

    return term === ''
        ? list
        : list.filter((row) =>
              [
                  row.guest,
                  row.phone,
                  row.room,
                  row.category,
                  row.booking_ref_id,
              ].some((value) => (value ?? '').toLowerCase().includes(term)),
          );
});

const cards = computed(() => {
    const kpis = props.board.kpis;

    return [
        {
            label: 'Occupancy',
            value: `${kpis.occupancy_percent}%`,
            hint: `${kpis.rooms_sold} of ${kpis.rooms_available} rooms sold`,
        },
        {
            label: 'ADR',
            value: money(kpis.adr),
            hint: `Room revenue ${money(kpis.room_revenue)}`,
        },
        {
            label: 'RevPAR',
            value: money(kpis.revpar),
            hint: `${kpis.vacant} vacant, ${kpis.out_of_order} blocked`,
        },
        {
            label: props.board.is_today ? 'In-house' : 'Staying',
            value: `${kpis.in_house_rooms}`,
            hint: `${kpis.in_house_guests} guest(s)`,
        },
    ];
});

function shiftDate(days: number): string {
    const date = new Date(`${props.board.date}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

function goTo(date: string): void {
    router.get(
        admin.frontDesk.show(props.property.id).url,
        date === props.today ? {} : { date },
        { preserveScroll: true, preserveState: true },
    );
}

const cardClass =
    'rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]';
const buttonClass =
    'rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm font-medium dark:border-[#3E3E3A] dark:text-[#EDEDEC]';
const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
</script>

<template>
    <Head :title="`Front Desk: ${property.name}`" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1
                class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Front Desk
            </h1>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                {{ property.name }}, {{ calendarDate(board.date)
                }}<span v-if="board.is_today"> (today)</span>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                :class="buttonClass"
                @click="goTo(shiftDate(-1))"
            >
                Previous day
            </button>
            <button
                type="button"
                :class="buttonClass"
                :disabled="board.is_today"
                @click="goTo(today)"
            >
                Today
            </button>
            <button
                type="button"
                :class="buttonClass"
                @click="goTo(shiftDate(1))"
            >
                Next day
            </button>
            <input
                type="date"
                :value="board.date"
                :class="inputClass"
                aria-label="Date"
                @change="
                    goTo(($event.target as HTMLInputElement).value || today)
                "
            />
        </div>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div v-for="card in cards" :key="card.label" :class="cardClass">
            <p
                class="text-xs tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
            >
                {{ card.label }}
            </p>
            <p
                class="mt-1 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                {{ card.value }}
            </p>
            <p class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                {{ card.hint }}
            </p>
        </div>
    </div>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div
            class="flex flex-wrap gap-1 border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <button
                v-for="tab in tabs"
                :key="tab"
                type="button"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium"
                :class="
                    activeTab === tab
                        ? 'border-[#1b1b18] text-[#1b1b18] dark:border-[#EDEDEC] dark:text-[#EDEDEC]'
                        : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
                "
                @click="activeTab = tab"
            >
                {{ tabLabels[tab] }}
            </button>
        </div>
        <input
            v-model="search"
            type="search"
            placeholder="Search guest, room, phone or booking"
            :class="[inputClass, 'w-full sm:w-72']"
        />
    </div>

    <div :class="cardClass">
        <p
            v-if="!rows.length"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            Nobody here.
        </p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm dark:text-[#EDEDEC]">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-1.5 pr-3">Guest</th>
                        <th class="pr-3">Room</th>
                        <th class="pr-3">Pax</th>
                        <th class="pr-3">Stay</th>
                        <th class="pr-3">Status</th>
                        <th class="pr-3 text-right">Balance</th>
                        <th class="pr-3">Booking</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.sub_booking_id"
                        class="border-t border-[#e3e3e0] align-top dark:border-[#3E3E3A]"
                    >
                        <td class="py-2 pr-3">
                            <p class="font-medium">{{ row.guest ?? '—' }}</p>
                            <p
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ row.phone }}
                            </p>
                            <p
                                v-if="row.notes"
                                class="mt-0.5 text-xs text-amber-700 dark:text-amber-300"
                            >
                                {{ row.notes }}
                            </p>
                        </td>
                        <td class="pr-3">
                            <p>{{ row.room ?? 'Unassigned' }}</p>
                            <p
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ row.category }}
                            </p>
                        </td>
                        <td class="pr-3">
                            {{ row.adults
                            }}<span v-if="row.children">
                                + {{ row.children }}</span
                            >
                        </td>
                        <td class="pr-3 whitespace-nowrap">
                            {{ calendarDate(row.check_in) }} –
                            {{ calendarDate(row.check_out) }}
                        </td>
                        <td class="pr-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="statusClasses[row.status]"
                                >{{ statusLabels[row.status] }}</span
                            >
                        </td>
                        <td
                            class="pr-3 text-right whitespace-nowrap"
                            :class="
                                row.balance > 0
                                    ? 'font-semibold text-red-700 dark:text-red-300'
                                    : ''
                            "
                        >
                            {{ money(row.balance) }}
                        </td>
                        <td class="pr-3">
                            <Link
                                :href="
                                    admin.bookings.show({
                                        property: property.id,
                                        booking: row.booking_id,
                                    }).url
                                "
                                class="font-medium underline underline-offset-4"
                                >{{ row.booking_ref_id }}</Link
                            >
                            <span
                                v-if="row.company"
                                class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs whitespace-nowrap text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300"
                                :title="`Billed to ${row.company} (City Ledger)`"
                                >Company pays</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
