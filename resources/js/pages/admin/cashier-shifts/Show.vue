<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useCurrency } from '@/lib/useCurrency';
import { usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: AdminLayout });

type ModeTotal = {
    mode: string;
    receipts: number;
    refunds: number;
    count: number;
};

const props = defineProps<{
    property: { id: number; name: string };
    current: {
        id: number;
        opened_at: string;
        opening_float: number;
        opening_notes: string | null;
        receipts_total: number;
        refunds_total: number;
        cash_receipts: number;
        cash_refunds: number;
        expected_cash: number;
        by_mode: ModeTotal[];
    } | null;
    cashiers: { id: number; name: string }[];
    history: {
        id: number;
        cashier: string | null;
        opened_at: string;
        closed_at: string | null;
        opening_float: number;
        cash_receipts: number | null;
        cash_refunds: number | null;
        expected_cash: number | null;
        cash_counted: number | null;
        variance: number | null;
        handed_over_to: string | null;
        closing_notes: string | null;
    }[];
    viewAll: boolean;
}>();

const { money } = useCurrency();
const propertyTime = usePropertyTime();

const openForm = useForm({
    opening_float: 0 as number | string,
    opening_notes: '',
});
const closeForm = useForm({
    cash_counted: '' as number | string,
    closing_notes: '',
    handed_over_to: null as number | null,
});

const variance = computed(() => {
    if (!props.current || closeForm.cash_counted === '') {
        return null;
    }

    return (
        Math.round(
            (Number(closeForm.cash_counted) - props.current.expected_cash) *
                100,
        ) / 100
    );
});

function openShift(): void {
    openForm.post(admin.cashierShifts.open(props.property.id).url, {
        preserveScroll: true,
    });
}

function closeShift(): void {
    closeForm.post(admin.cashierShifts.close(props.property.id).url, {
        preserveScroll: true,
        onSuccess: () => closeForm.reset(),
    });
}

function varianceClass(value: number | null): string {
    if (value === null || Math.abs(value) < 0.01) {
        return '';
    }

    return value < 0
        ? 'font-semibold text-red-700 dark:text-red-300'
        : 'font-semibold text-amber-700 dark:text-amber-300';
}

const cardClass =
    'rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]';
const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const labelClass =
    'flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]';
const primaryButton =
    'rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]';
</script>

<template>
    <Head :title="`Cashier Shift: ${property.name}`" />

    <div class="mb-4">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Cashier Shift
        </h1>
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            {{ property.name }}
        </p>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- No shift open: open one with the float in the drawer. -->
        <form
            v-if="!current"
            :class="[cardClass, 'space-y-3']"
            @submit.prevent="openShift"
        >
            <h2
                class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Open a shift
            </h2>
            <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                Receipts and refunds you take while the shift is open are
                counted in it.
            </p>
            <label :class="labelClass">
                Opening cash float
                <input
                    v-model="openForm.opening_float"
                    type="number"
                    min="0"
                    step="0.01"
                    required
                    :class="inputClass"
                />
                <span
                    v-if="openForm.errors.opening_float"
                    class="text-red-600"
                    >{{ openForm.errors.opening_float }}</span
                >
            </label>
            <label :class="labelClass">
                Notes
                <input
                    v-model="openForm.opening_notes"
                    type="text"
                    maxlength="500"
                    :class="inputClass"
                />
            </label>
            <button
                type="submit"
                :disabled="openForm.processing"
                :class="primaryButton"
            >
                Open shift
            </button>
        </form>

        <!-- The open shift: what it has taken, and closing it with the cash count. -->
        <template v-else>
            <div :class="cardClass">
                <h2
                    class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Your shift
                </h2>
                <p class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Open since {{ propertyTime.dateTime(current.opened_at)
                    }}<span v-if="current.opening_notes">
                        · {{ current.opening_notes }}</span
                    >
                </p>
                <dl
                    class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm dark:text-[#EDEDEC]"
                >
                    <dt class="text-[#706f6c] dark:text-[#A1A09A]">
                        Opening float
                    </dt>
                    <dd class="text-right">
                        {{ money(current.opening_float) }}
                    </dd>
                    <dt class="text-[#706f6c] dark:text-[#A1A09A]">
                        Cash received
                    </dt>
                    <dd class="text-right">
                        {{ money(current.cash_receipts) }}
                    </dd>
                    <dt class="text-[#706f6c] dark:text-[#A1A09A]">
                        Cash refunded
                    </dt>
                    <dd class="text-right">
                        − {{ money(current.cash_refunds) }}
                    </dd>
                    <dt class="font-semibold">Cash expected in the drawer</dt>
                    <dd class="text-right font-semibold">
                        {{ money(current.expected_cash) }}
                    </dd>
                </dl>
                <h3
                    class="mt-4 text-xs font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    All payment modes
                </h3>
                <p
                    v-if="!current.by_mode.length"
                    class="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Nothing taken yet.
                </p>
                <table
                    v-else
                    class="mt-1 w-full text-left text-sm dark:text-[#EDEDEC]"
                >
                    <thead
                        class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        <tr>
                            <th class="py-1">Mode</th>
                            <th class="text-right">Entries</th>
                            <th class="text-right">Received</th>
                            <th class="text-right">Refunded</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in current.by_mode"
                            :key="row.mode"
                            class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                        >
                            <td class="py-1">{{ row.mode }}</td>
                            <td class="text-right">{{ row.count }}</td>
                            <td class="text-right">
                                {{ money(row.receipts) }}
                            </td>
                            <td class="text-right">{{ money(row.refunds) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form
                :class="[cardClass, 'space-y-3']"
                @submit.prevent="closeShift"
            >
                <h2
                    class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Close the shift
                </h2>
                <label :class="labelClass">
                    Cash counted in the drawer
                    <input
                        v-model="closeForm.cash_counted"
                        type="number"
                        min="0"
                        step="0.01"
                        required
                        :class="inputClass"
                    />
                    <span
                        v-if="closeForm.errors.cash_counted"
                        class="text-red-600"
                        >{{ closeForm.errors.cash_counted }}</span
                    >
                </label>
                <p
                    v-if="variance !== null"
                    class="text-sm"
                    :class="varianceClass(variance)"
                >
                    <template v-if="Math.abs(variance) < 0.01"
                        >The cash matches.</template
                    >
                    <template v-else-if="variance < 0"
                        >Short by {{ money(-variance) }}.</template
                    >
                    <template v-else>Over by {{ money(variance) }}.</template>
                </p>
                <label :class="labelClass">
                    Hand over the cash to
                    <select
                        v-model="closeForm.handed_over_to"
                        :class="inputClass"
                    >
                        <option :value="null">
                            Nobody (cash goes to the safe)
                        </option>
                        <option
                            v-for="cashier in cashiers"
                            :key="cashier.id"
                            :value="cashier.id"
                        >
                            {{ cashier.name }}
                        </option>
                    </select>
                    <span class="text-[11px]"
                        >Their shift opens with the counted cash as its
                        float.</span
                    >
                    <span
                        v-if="closeForm.errors.handed_over_to"
                        class="text-red-600"
                        >{{ closeForm.errors.handed_over_to }}</span
                    >
                </label>
                <label :class="labelClass">
                    Notes
                    <input
                        v-model="closeForm.closing_notes"
                        type="text"
                        maxlength="500"
                        :class="inputClass"
                    />
                </label>
                <button
                    type="submit"
                    :disabled="closeForm.processing"
                    :class="primaryButton"
                >
                    Close shift
                </button>
            </form>
        </template>
    </div>

    <div :class="cardClass">
        <h2
            class="mb-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            {{ viewAll ? 'Shifts' : 'Your shifts' }}, last 30 days
        </h2>
        <p
            v-if="!history.length"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            No shifts yet.
        </p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm dark:text-[#EDEDEC]">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-1.5 pr-3">Cashier</th>
                        <th class="pr-3">Opened</th>
                        <th class="pr-3">Closed</th>
                        <th class="pr-3 text-right">Float</th>
                        <th class="pr-3 text-right">Cash in</th>
                        <th class="pr-3 text-right">Cash out</th>
                        <th class="pr-3 text-right">Expected</th>
                        <th class="pr-3 text-right">Counted</th>
                        <th class="pr-3 text-right">Variance</th>
                        <th class="pr-3">Handed over to</th>
                        <th class="pr-3">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="shift in history"
                        :key="shift.id"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <td class="py-1.5 pr-3">{{ shift.cashier }}</td>
                        <td class="pr-3 whitespace-nowrap">
                            {{ propertyTime.dateTime(shift.opened_at) }}
                        </td>
                        <td class="pr-3 whitespace-nowrap">
                            {{
                                shift.closed_at
                                    ? propertyTime.dateTime(shift.closed_at)
                                    : 'Open'
                            }}
                        </td>
                        <td class="pr-3 text-right">
                            {{ money(shift.opening_float) }}
                        </td>
                        <td class="pr-3 text-right">
                            {{
                                shift.cash_receipts === null
                                    ? '—'
                                    : money(shift.cash_receipts)
                            }}
                        </td>
                        <td class="pr-3 text-right">
                            {{
                                shift.cash_refunds === null
                                    ? '—'
                                    : money(shift.cash_refunds)
                            }}
                        </td>
                        <td class="pr-3 text-right">
                            {{
                                shift.expected_cash === null
                                    ? '—'
                                    : money(shift.expected_cash)
                            }}
                        </td>
                        <td class="pr-3 text-right">
                            {{
                                shift.cash_counted === null
                                    ? '—'
                                    : money(shift.cash_counted)
                            }}
                        </td>
                        <td
                            class="pr-3 text-right"
                            :class="varianceClass(shift.variance)"
                        >
                            {{
                                shift.variance === null
                                    ? '—'
                                    : money(shift.variance)
                            }}
                        </td>
                        <td class="pr-3">{{ shift.handed_over_to ?? '—' }}</td>
                        <td class="pr-3">{{ shift.closing_notes ?? '' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
