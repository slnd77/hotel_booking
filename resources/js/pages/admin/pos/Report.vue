<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useCan } from '@/lib/useCan';
import { useCurrency } from '@/lib/useCurrency';
import { calendarDate } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

defineOptions({ layout: AdminLayout });

type Totals = {
    bills: number;
    sub_total: number;
    discount: number;
    net_sales: number;
    tax_total: number;
    total: number;
};

const props = defineProps<{
    property: { id: number; name: string };
    outlets: { id: number; name: string }[];
    filters: { from: string; to: string; outlet: number | null };
    maxDays: number;
    summary: {
        currency: string;
        from: string;
        to: string;
        totals: Totals & {
            charged_to_rooms: number;
            collected: number;
            open_bills: number;
            open_amount: number;
            void_bills: number;
            void_amount: number;
        };
        outlets: (Totals & { outlet: string; void_bills: number })[];
        settlements: { label: string; count: number; amount: number }[];
        items: {
            outlet: string;
            name: string;
            quantity: number;
            amount: number;
        }[];
        bills: {
            id: number;
            business_date: string;
            outlet: string;
            bill_number: string;
            status: 'open' | 'posted' | 'settled' | 'void';
            status_label: string;
            guest_name: string | null;
            room: string | null;
            booking_ref_id: string | null;
            sub_total: number;
            discount: number;
            tax_total: number;
            total: number;
            payment_mode: string | null;
            void_reason: string | null;
        }[];
    };
}>();

const can = useCan();
const { money } = useCurrency();
const filters = reactive({
    from: props.filters.from,
    to: props.filters.to,
    outlet: props.filters.outlet,
});

function apply(): void {
    router.get(
        admin.pos.report(props.property.id).url,
        {
            from: filters.from,
            to: filters.to,
            ...(filters.outlet ? { outlet: filters.outlet } : {}),
        },
        { preserveScroll: true },
    );
}

const cards = computed(() => {
    const totals = props.summary.totals;

    return [
        {
            label: 'Net sales',
            value: money(totals.net_sales),
            hint: `${totals.bills} bill(s), ${money(totals.discount)} discount`,
        },
        {
            label: 'Tax',
            value: money(totals.tax_total),
            hint: `Total billed ${money(totals.total)}`,
        },
        {
            label: 'Charged to rooms',
            value: money(totals.charged_to_rooms),
            hint: `Collected at outlets ${money(totals.collected)}`,
        },
        {
            label: 'Open / void',
            value: `${totals.open_bills} / ${totals.void_bills}`,
            hint: `${money(totals.open_amount)} open, ${money(totals.void_amount)} voided`,
        },
    ];
});

const excelUrl = computed(
    () =>
        admin.reports.download(
            { property: props.property.id, report: 'pos_sales_report' },
            {
                query: {
                    date: props.summary.from,
                    end_date: props.summary.to,
                    type: 'excel',
                },
            },
        ).url,
);

const cardClass =
    'rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]';
const headingClass =
    'mb-3 text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]';
const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
</script>

<template>
    <Head :title="`POS Reports: ${property.name}`" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1
                class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                POS Reports
            </h1>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Outlet sales by business date,
                {{ calendarDate(summary.from) }} to
                {{ calendarDate(summary.to) }}
            </p>
        </div>
        <div class="flex gap-2">
            <a
                v-if="can('manage_property.report_download_excel')"
                :href="excelUrl"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                >Download Excel</a
            >
            <Link
                :href="admin.pos.show(property.id).url"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                >Back to POS</Link
            >
        </div>
    </div>

    <form class="mb-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
        <label
            class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
        >
            From
            <input
                v-model="filters.from"
                type="date"
                :class="inputClass"
                required
            />
        </label>
        <label
            class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
        >
            To
            <input
                v-model="filters.to"
                type="date"
                :min="filters.from"
                :class="inputClass"
                required
            />
        </label>
        <label
            class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
        >
            Outlet
            <select v-model="filters.outlet" :class="inputClass">
                <option :value="null">All outlets</option>
                <option
                    v-for="outlet in outlets"
                    :key="outlet.id"
                    :value="outlet.id"
                >
                    {{ outlet.name }}
                </option>
            </select>
        </label>
        <button
            type="submit"
            class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
        >
            Show
        </button>
        <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >Up to {{ maxDays }} days.</span
        >
    </form>

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

    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div :class="cardClass">
            <h3 :class="headingClass">Sales by outlet</h3>
            <p
                v-if="!summary.outlets.length"
                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
            >
                No bills.
            </p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm dark:text-[#EDEDEC]">
                    <thead
                        class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        <tr>
                            <th class="py-1.5">Outlet</th>
                            <th class="text-right">Bills</th>
                            <th class="text-right">Net sales</th>
                            <th class="text-right">Tax</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Voids</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in summary.outlets"
                            :key="row.outlet"
                            class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                        >
                            <td class="py-1.5">{{ row.outlet }}</td>
                            <td class="text-right">{{ row.bills }}</td>
                            <td class="text-right">
                                {{ money(row.net_sales) }}
                            </td>
                            <td class="text-right">
                                {{ money(row.tax_total) }}
                            </td>
                            <td class="text-right">{{ money(row.total) }}</td>
                            <td class="text-right">{{ row.void_bills }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div :class="cardClass">
            <h3 :class="headingClass">Settlement</h3>
            <table class="w-full text-sm dark:text-[#EDEDEC]">
                <tbody>
                    <tr
                        v-for="row in summary.settlements"
                        :key="row.label"
                        class="border-b border-[#e3e3e0] last:border-0 dark:border-[#3E3E3A]"
                    >
                        <td class="py-1.5">
                            {{ row.label }}
                            <span class="text-xs text-[#706f6c]"
                                >({{ row.count }})</span
                            >
                        </td>
                        <td class="py-1.5 text-right">
                            {{ money(row.amount) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mb-6" :class="cardClass">
        <h3 :class="headingClass">Item-wise sales</h3>
        <p
            v-if="!summary.items.length"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            No items sold.
        </p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm dark:text-[#EDEDEC]">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-1.5">Item</th>
                        <th>Outlet</th>
                        <th class="text-right">Quantity</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in summary.items"
                        :key="`${row.outlet}-${row.name}`"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <td class="py-1.5">{{ row.name }}</td>
                        <td>{{ row.outlet }}</td>
                        <td class="text-right">{{ row.quantity }}</td>
                        <td class="text-right">{{ money(row.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div :class="cardClass">
        <h3 :class="headingClass">Bills</h3>
        <p
            v-if="!summary.bills.length"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            No bills.
        </p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm dark:text-[#EDEDEC]">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-1.5">Date</th>
                        <th>Outlet</th>
                        <th>Bill</th>
                        <th>Status</th>
                        <th>Guest / Room</th>
                        <th class="text-right">Discount</th>
                        <th class="text-right">Tax</th>
                        <th class="text-right">Total</th>
                        <th>Paid by</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="bill in summary.bills"
                        :key="bill.id"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                        :class="
                            bill.status === 'void'
                                ? 'text-[#706f6c] line-through'
                                : ''
                        "
                        :title="bill.void_reason ?? ''"
                    >
                        <td class="py-1.5">
                            {{ calendarDate(bill.business_date) }}
                        </td>
                        <td>{{ bill.outlet }}</td>
                        <td>#{{ bill.bill_number }}</td>
                        <td>{{ bill.status_label }}</td>
                        <td>
                            {{
                                [
                                    bill.guest_name,
                                    bill.room,
                                    bill.booking_ref_id,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || 'Walk-in'
                            }}
                        </td>
                        <td class="text-right">{{ money(bill.discount) }}</td>
                        <td class="text-right">{{ money(bill.tax_total) }}</td>
                        <td class="text-right">{{ money(bill.total) }}</td>
                        <td>{{ bill.payment_mode ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
