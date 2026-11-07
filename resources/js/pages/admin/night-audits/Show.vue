<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useCurrency } from '@/lib/useCurrency';
import { calendarDate, usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: AdminLayout });

type Report = {
    currency: string;
    rooms_available: number;
    rooms_sold: number;
    occupancy_percent: number;
    adr: number;
    revpar: number;
    room_revenue: number;
    room_taxes: number;
    no_show_revenue: number;
    total_revenue: number;
    receipts_total: number;
    receipts_by_mode: { mode: string; count: number; amount: number }[];
    refunds_total: number;
    arrivals: number;
    departures: number;
    in_house: number;
    no_shows: number;
    cancellations: number;
    overstays: number;
    /** Missing on audits closed before POS existed. */
    pos?: {
        bills: number;
        net_sales: number;
        tax_total: number;
        total: number;
        charged_to_rooms: number;
        collected: number;
        void_bills: number;
    };
};

const props = defineProps<{
    property: { id: number; name: string };
    audit: {
        id: number;
        business_date: string;
        status: string;
        run_by: string | null;
        started_at: string | null;
        finished_at: string | null;
        error: string | null;
        report: Report | null;
        no_shows: {
            booking_id: number;
            booking_ref_id: string;
            room: string | null;
            guest: string | null;
            check_in_date: string;
            stay_amount: number;
            penalty_percent: number;
            fee: number;
        }[];
        overstays: {
            booking_id: number;
            booking_ref_id: string;
            room: string | null;
            guest: string | null;
            check_out_date: string;
        }[];
        warnings: string[];
    };
}>();

const { money } = useCurrency();
const propertyTime = usePropertyTime();

const kpis = computed(() => {
    const report = props.audit.report;

    if (!report) {
        return [];
    }

    return [
        {
            label: 'Occupancy',
            value: `${report.occupancy_percent}%`,
            hint: `${report.rooms_sold} of ${report.rooms_available} rooms`,
        },
        {
            label: 'ADR',
            value: money(report.adr),
            hint: 'Room revenue ÷ rooms sold',
        },
        {
            label: 'RevPAR',
            value: money(report.revpar),
            hint: 'Room revenue ÷ rooms available',
        },
        {
            label: 'Total revenue',
            value: money(report.total_revenue),
            hint: `Rooms ${money(report.room_revenue)} · Taxes ${money(report.room_taxes)} · No-shows ${money(report.no_show_revenue)}`,
        },
    ];
});

const movements = computed(() => {
    const report = props.audit.report;

    return report
        ? [
              { label: 'Arrivals', value: report.arrivals },
              { label: 'Departures', value: report.departures },
              { label: 'In house', value: report.in_house },
              { label: 'No-shows', value: report.no_shows },
              { label: 'Cancellations', value: report.cancellations },
              { label: 'Overstays', value: report.overstays },
          ]
        : [];
});

const cardClass =
    'rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]';
const headingClass =
    'mb-3 text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]';
</script>

<template>
    <Head :title="`Night Audit ${calendarDate(audit.business_date)}`" />

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1
                class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Night Audit — {{ calendarDate(audit.business_date) }}
            </h1>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                {{ audit.status === 'completed' ? 'Completed' : 'Failed' }} by
                {{ audit.run_by ?? 'Scheduler' }} ·
                {{ propertyTime.dateTime(audit.finished_at) }}
            </p>
        </div>
        <Link
            :href="admin.nightAudits.edit(property.id).url"
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
        >
            Back
        </Link>
    </div>

    <div
        v-if="audit.error"
        class="mb-6 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300"
    >
        {{ audit.error }}
    </div>
    <div
        v-for="warning in audit.warnings"
        :key="warning"
        class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300"
    >
        {{ warning }}
    </div>

    <template v-if="audit.report">
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="kpi in kpis" :key="kpi.label" :class="cardClass">
                <p
                    class="text-xs tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    {{ kpi.label }}
                </p>
                <p
                    class="mt-1 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ kpi.value }}
                </p>
                <p class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    {{ kpi.hint }}
                </p>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div :class="cardClass">
                <h3 :class="headingClass">Movements</h3>
                <dl class="grid grid-cols-3 gap-3 text-sm">
                    <div v-for="item in movements" :key="item.label">
                        <dt class="text-[#706f6c] dark:text-[#A1A09A]">
                            {{ item.label }}
                        </dt>
                        <dd
                            class="text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ item.value }}
                        </dd>
                    </div>
                </dl>
            </div>
            <div :class="cardClass">
                <h3 :class="headingClass">Receipts by payment mode</h3>
                <p
                    v-if="audit.report.receipts_by_mode.length === 0"
                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    No receipts on this day.
                </p>
                <table v-else class="w-full text-sm">
                    <tbody>
                        <tr
                            v-for="row in audit.report.receipts_by_mode"
                            :key="row.mode"
                            class="border-b border-[#e3e3e0] last:border-0 dark:border-[#3E3E3A]"
                        >
                            <td class="py-1.5">
                                {{ row.mode }}
                                <span class="text-xs text-[#706f6c]"
                                    >({{ row.count }})</span
                                >
                            </td>
                            <td class="py-1.5 text-right">
                                {{ money(row.amount) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="pt-2">Total</td>
                            <td class="pt-2 text-right">
                                {{ money(audit.report.receipts_total) }}
                            </td>
                        </tr>
                        <tr
                            v-if="audit.report.refunds_total > 0"
                            class="text-[#706f6c]"
                        >
                            <td>Refunds</td>
                            <td class="text-right">
                                {{ money(audit.report.refunds_total) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="audit.report.pos" :class="cardClass">
                <h3 :class="headingClass">Outlets (POS)</h3>
                <table class="w-full text-sm">
                    <tbody>
                        <tr
                            class="border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
                        >
                            <td class="py-1.5">Bills</td>
                            <td class="py-1.5 text-right">
                                {{ audit.report.pos.bills }}
                            </td>
                        </tr>
                        <tr
                            class="border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
                        >
                            <td class="py-1.5">Net sales</td>
                            <td class="py-1.5 text-right">
                                {{ money(audit.report.pos.net_sales) }}
                            </td>
                        </tr>
                        <tr
                            class="border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
                        >
                            <td class="py-1.5">Tax</td>
                            <td class="py-1.5 text-right">
                                {{ money(audit.report.pos.tax_total) }}
                            </td>
                        </tr>
                        <tr
                            class="border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
                        >
                            <td class="py-1.5">Charged to rooms</td>
                            <td class="py-1.5 text-right">
                                {{ money(audit.report.pos.charged_to_rooms) }}
                            </td>
                        </tr>
                        <tr
                            class="border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
                        >
                            <td class="py-1.5">Collected at outlets</td>
                            <td class="py-1.5 text-right">
                                {{ money(audit.report.pos.collected) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="py-1.5">Void bills</td>
                            <td class="py-1.5 text-right">
                                {{ audit.report.pos.void_bills }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="pt-2">Total</td>
                            <td class="pt-2 text-right">
                                {{ money(audit.report.pos.total) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </template>

    <div class="mb-6" :class="cardClass">
        <h3 :class="headingClass">No-shows</h3>
        <p
            v-if="audit.no_shows.length === 0"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            None.
        </p>
        <table v-else class="w-full text-left text-sm">
            <thead class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]">
                <tr>
                    <th class="py-1.5">Booking</th>
                    <th>Guest</th>
                    <th>Room</th>
                    <th>Arrival</th>
                    <th class="text-right">Stay amount</th>
                    <th class="text-right">Penalty</th>
                    <th class="text-right">Fee</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in audit.no_shows"
                    :key="`${row.booking_id}-${row.room}`"
                    class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                >
                    <td class="py-1.5">
                        <Link
                            :href="
                                admin.bookings.show({
                                    property: property.id,
                                    booking: row.booking_id,
                                }).url
                            "
                            class="text-[#f53003] hover:underline dark:text-[#FF4433]"
                            >{{ row.booking_ref_id }}</Link
                        >
                    </td>
                    <td>{{ row.guest ?? '—' }}</td>
                    <td>{{ row.room ?? 'Unassigned' }}</td>
                    <td>{{ calendarDate(row.check_in_date) }}</td>
                    <td class="text-right">{{ money(row.stay_amount) }}</td>
                    <td class="text-right">{{ row.penalty_percent }}%</td>
                    <td class="text-right">{{ money(row.fee) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div :class="cardClass">
        <h3 :class="headingClass">Overstays</h3>
        <p
            v-if="audit.overstays.length === 0"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            None.
        </p>
        <table v-else class="w-full text-left text-sm">
            <thead class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]">
                <tr>
                    <th class="py-1.5">Booking</th>
                    <th>Guest</th>
                    <th>Room</th>
                    <th>Due out</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in audit.overstays"
                    :key="`${row.booking_id}-${row.room}`"
                    class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                >
                    <td class="py-1.5">
                        <Link
                            :href="
                                admin.bookings.show({
                                    property: property.id,
                                    booking: row.booking_id,
                                }).url
                            "
                            class="text-[#f53003] hover:underline dark:text-[#FF4433]"
                            >{{ row.booking_ref_id }}</Link
                        >
                    </td>
                    <td>{{ row.guest ?? '—' }}</td>
                    <td>{{ row.room ?? 'Unassigned' }}</td>
                    <td>{{ calendarDate(row.check_out_date) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
