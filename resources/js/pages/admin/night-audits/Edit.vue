<script setup lang="ts">
import ListViewButton from '@/components/ListViewButton.vue';
import type { TableColumn } from '@/components/TableColumnSettingsModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useColumnVisibility } from '@/lib/useColumnVisibility';
import { useCurrency } from '@/lib/useCurrency';
import { calendarDate, usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// The audit history: the business date always shows, it opens the audit.
const historyColumns: TableColumn[] = [
    { key: 'status', label: 'Status' },
    { key: 'run_by', label: 'Run by' },
    { key: 'closed_at', label: 'Closed at' },
    { key: 'occupancy', label: 'Occupancy' },
    { key: 'revenue', label: 'Revenue' },
    { key: 'no_shows', label: 'No-shows' },
    { key: 'overstays', label: 'Overstays' },
];
const historyShown = useColumnVisibility(
    'night-audits-column-visibility',
    historyColumns.map((column) => column.key),
);
const historyColumnCount = computed(
    () =>
        1 + historyColumns.filter((column) => historyShown[column.key]).length,
);

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    property: { id: number; name: string };
    currentRolloverDate: string;
    automated: boolean;
    rolloverTime: string;
    nextAutomaticRunAt: string | null;
    canRunNow: boolean;
    paymentReconciliation: boolean;
    invoiceValidation: boolean;
    invoiceStartDate: string | null;
    invoiceProblems: {
        booking_id: number;
        booking_ref_id: string;
        guest: string | null;
        invoice_number: string | null;
        booking_total: number;
        invoice_amount: number | null;
        problem: 'missing' | 'outdated';
        message: string;
    }[];
    paymentsToReconcile: {
        id: number;
        type: string;
        receipt_number: string | null;
        guest_name: string | null;
        payment_mode: string | null;
        amount: number;
        received_at: string | null;
        reconciled_at: string | null;
        reconciled_by: string | null;
    }[];
    audits: {
        id: number;
        business_date: string;
        status: string;
        run_by: string | null;
        finished_at: string | null;
        error: string | null;
        occupancy_percent: number | null;
        total_revenue: number | null;
        no_shows: number;
        overstays: number;
    }[];
}>();

const { money } = useCurrency();
const propertyTime = usePropertyTime();
/** Nothing to send (the server closes its current business date); errors come back on business_date. */
const runForm = useForm<{ business_date: string | null }>({
    business_date: null,
});

// ── Validate Invoice: issue the missing invoices, bring outdated ones in line with their bills ──
const fixingInvoices = ref(false);

function fixInvoices(): void {
    fixingInvoices.value = true;
    router.post(
        admin.nightAudits.invoices(props.property.id).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => (fixingInvoices.value = false),
        },
    );
}

// ── Payment Reconciliation: tick the day's payments once checked against the till / bank ──
const selectedPaymentIds = ref<number[]>([]);
const reconciling = ref(false);
const unreconciledCount = computed(
    () =>
        props.paymentsToReconcile.filter((payment) => !payment.reconciled_at)
            .length,
);
const allSelected = computed({
    get: () =>
        props.paymentsToReconcile.length > 0 &&
        selectedPaymentIds.value.length === props.paymentsToReconcile.length,
    set: (value: boolean) =>
        (selectedPaymentIds.value = value
            ? props.paymentsToReconcile.map((payment) => payment.id)
            : []),
});

function markReconciled(reconciled: boolean): void {
    reconciling.value = true;
    router.post(
        admin.nightAudits.reconcile(props.property.id).url,
        { payment_ids: selectedPaymentIds.value, reconciled },
        {
            preserveScroll: true,
            onSuccess: () => (selectedPaymentIds.value = []),
            onFinish: () => (reconciling.value = false),
        },
    );
}

function runNightAudit(): void {
    runForm.post(admin.nightAudits.store(props.property.id).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Night Audit: ${property.name}`" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Night Audit
        </h1>
        <Link
            :href="admin.nightAuditSettings.edit(property.id).url"
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
        >
            Settings
        </Link>
    </div>

    <div
        class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
    >
        <div class="flex flex-col gap-1 text-sm">
            <p class="text-[#1b1b18] dark:text-[#EDEDEC]">
                Current business date:
                <span class="font-semibold">{{
                    calendarDate(currentRolloverDate)
                }}</span>
            </p>
            <p v-if="automated" class="text-[#706f6c] dark:text-[#A1A09A]">
                Automated night audit — closes at {{ rolloverTime }} on the next
                day ({{ propertyTime.dateTime(nextAutomaticRunAt) }}).
            </p>
            <p v-else class="text-[#706f6c] dark:text-[#A1A09A]">
                Night audit is off — the day closes only when you run it.
            </p>
        </div>
        <div class="flex flex-col items-end gap-1">
            <button
                type="button"
                :disabled="!canRunNow || runForm.processing"
                class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                @click="runNightAudit"
            >
                {{
                    runForm.processing
                        ? 'Running…'
                        : `Run night audit for ${calendarDate(currentRolloverDate)}`
                }}
            </button>
            <p
                v-if="!canRunNow"
                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                This business date hasn't started yet.
            </p>
            <p v-if="runForm.errors.business_date" class="text-xs text-red-600">
                {{ runForm.errors.business_date }}
            </p>
        </div>
    </div>

    <div
        v-if="paymentReconciliation || paymentsToReconcile.length > 0"
        class="mb-6 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
    >
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3
                    class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Payment reconciliation —
                    {{ calendarDate(currentRolloverDate) }}
                </h3>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    <template v-if="paymentReconciliation"
                        >The night audit won't close this day while a payment is
                        unreconciled ({{ unreconciledCount }} left).</template
                    >
                    <template v-else
                        >Payment Reconciliation is off in the night audit
                        settings; ticking is optional.</template
                    >
                </p>
            </div>
            <div class="flex gap-2">
                <button
                    type="button"
                    :disabled="selectedPaymentIds.length === 0 || reconciling"
                    class="rounded-md bg-[#1b1b18] px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                    @click="markReconciled(true)"
                >
                    Mark reconciled
                </button>
                <button
                    type="button"
                    :disabled="selectedPaymentIds.length === 0 || reconciling"
                    class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm font-medium disabled:opacity-50 dark:border-[#3E3E3A]"
                    @click="markReconciled(false)"
                >
                    Undo
                </button>
            </div>
        </div>
        <p
            v-if="paymentsToReconcile.length === 0"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            No payments on this day.
        </p>
        <table v-else class="w-full text-left text-sm">
            <thead class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]">
                <tr>
                    <th class="w-8 py-1.5">
                        <input
                            v-model="allSelected"
                            type="checkbox"
                            class="h-4 w-4"
                            aria-label="Select all payments"
                        />
                    </th>
                    <th>Receipt</th>
                    <th>Guest</th>
                    <th>Mode</th>
                    <th>Received</th>
                    <th class="text-right">Amount</th>
                    <th>Reconciled</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="payment in paymentsToReconcile"
                    :key="payment.id"
                    class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                >
                    <td class="py-1.5">
                        <input
                            v-model="selectedPaymentIds"
                            :value="payment.id"
                            type="checkbox"
                            class="h-4 w-4"
                            :aria-label="`Select ${payment.receipt_number ?? payment.id}`"
                        />
                    </td>
                    <td>
                        {{ payment.receipt_number ?? '—' }}
                        <span
                            v-if="payment.type !== 'receipt'"
                            class="text-xs text-[#706f6c]"
                            >({{ payment.type }})</span
                        >
                    </td>
                    <td>{{ payment.guest_name ?? '—' }}</td>
                    <td>{{ payment.payment_mode ?? '—' }}</td>
                    <td>{{ propertyTime.dateTime(payment.received_at) }}</td>
                    <td class="text-right">{{ money(payment.amount) }}</td>
                    <td>
                        <span
                            v-if="payment.reconciled_at"
                            class="text-green-700 dark:text-green-400"
                            >✓ {{ payment.reconciled_by ?? '' }},
                            {{
                                propertyTime.dateTime(payment.reconciled_at)
                            }}</span
                        >
                        <span v-else class="text-amber-700 dark:text-amber-400"
                            >Pending</span
                        >
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div
        v-if="invoiceValidation"
        class="mb-6 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
        data-test="invoice-validation"
    >
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3
                    class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Invoice validation — {{ calendarDate(currentRolloverDate) }}
                </h3>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    The night audit won't close this day while a booking checked
                    out<template v-if="invoiceStartDate">
                        since {{ calendarDate(invoiceStartDate) }}</template
                    ><template v-else> on it</template> has no invoice, or one
                    that no longer matches its bill.
                </p>
            </div>
            <button
                v-if="invoiceProblems.length > 0"
                type="button"
                :disabled="fixingInvoices"
                class="rounded-md bg-[#1b1b18] px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                data-test="fix-invoices"
                @click="fixInvoices"
            >
                Generate / update invoices
            </button>
        </div>
        <p
            v-if="invoiceProblems.length === 0"
            class="text-sm text-green-700 dark:text-green-400"
        >
            ✓ Every checked-out booking has an up-to-date invoice.
        </p>
        <table v-else class="w-full text-left text-sm">
            <thead class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]">
                <tr>
                    <th class="py-1.5">Booking</th>
                    <th>Guest</th>
                    <th>Invoice</th>
                    <th class="text-right">Bill</th>
                    <th class="text-right">Invoiced</th>
                    <th>Problem</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="problem in invoiceProblems"
                    :key="problem.booking_id"
                    class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                >
                    <td class="py-1.5">{{ problem.booking_ref_id }}</td>
                    <td>{{ problem.guest ?? '—' }}</td>
                    <td>{{ problem.invoice_number ?? '—' }}</td>
                    <td class="text-right">
                        {{ money(problem.booking_total) }}
                    </td>
                    <td class="text-right">
                        {{
                            problem.invoice_amount === null
                                ? '—'
                                : money(problem.invoice_amount)
                        }}
                    </td>
                    <td class="text-amber-700 dark:text-amber-400">
                        {{
                            problem.problem === 'missing'
                                ? 'No invoice'
                                : 'Out of date'
                        }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="mb-2 flex justify-end">
        <ListViewButton :columns="historyColumns" :visibility="historyShown" />
    </div>
    <div
        class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <table class="w-full text-left text-sm">
            <thead
                class="bg-[#FDFDFC] text-xs tracking-wide text-[#706f6c] uppercase dark:bg-[#161615] dark:text-[#A1A09A]"
            >
                <tr>
                    <th class="px-4 py-3">Business date</th>
                    <th v-if="historyShown.status" class="px-4 py-3">Status</th>
                    <th v-if="historyShown.run_by" class="px-4 py-3">Run by</th>
                    <th v-if="historyShown.closed_at" class="px-4 py-3">
                        Closed at
                    </th>
                    <th
                        v-if="historyShown.occupancy"
                        class="px-4 py-3 text-right"
                    >
                        Occupancy
                    </th>
                    <th
                        v-if="historyShown.revenue"
                        class="px-4 py-3 text-right"
                    >
                        Revenue
                    </th>
                    <th
                        v-if="historyShown.no_shows"
                        class="px-4 py-3 text-right"
                    >
                        No-shows
                    </th>
                    <th
                        v-if="historyShown.overstays"
                        class="px-4 py-3 text-right"
                    >
                        Overstays
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="audits.length === 0">
                    <td
                        :colspan="historyColumnCount"
                        class="px-4 py-6 text-center text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        No night audit has run yet.
                    </td>
                </tr>
                <tr
                    v-for="audit in audits"
                    :key="audit.id"
                    class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                >
                    <td class="px-4 py-3">
                        <Link
                            :href="
                                admin.nightAudits.show({
                                    property: property.id,
                                    nightAudit: audit.id,
                                }).url
                            "
                            class="font-medium text-[#f53003] hover:underline dark:text-[#FF4433]"
                        >
                            {{ calendarDate(audit.business_date) }}
                        </Link>
                    </td>
                    <td v-if="historyShown.status" class="px-4 py-3">
                        <span
                            :class="
                                audit.status === 'completed'
                                    ? 'text-green-700 dark:text-green-400'
                                    : 'text-red-600'
                            "
                            :title="audit.error ?? ''"
                        >
                            {{
                                audit.status === 'completed'
                                    ? 'Completed'
                                    : 'Failed'
                            }}
                        </span>
                    </td>
                    <td v-if="historyShown.run_by" class="px-4 py-3">
                        {{ audit.run_by ?? 'Scheduler' }}
                    </td>
                    <td v-if="historyShown.closed_at" class="px-4 py-3">
                        {{ propertyTime.dateTime(audit.finished_at) }}
                    </td>
                    <td
                        v-if="historyShown.occupancy"
                        class="px-4 py-3 text-right"
                    >
                        {{
                            audit.occupancy_percent === null
                                ? '—'
                                : `${audit.occupancy_percent}%`
                        }}
                    </td>
                    <td
                        v-if="historyShown.revenue"
                        class="px-4 py-3 text-right"
                    >
                        {{
                            audit.total_revenue === null
                                ? '—'
                                : money(audit.total_revenue)
                        }}
                    </td>
                    <td
                        v-if="historyShown.no_shows"
                        class="px-4 py-3 text-right"
                    >
                        {{ audit.no_shows }}
                    </td>
                    <td
                        v-if="historyShown.overstays"
                        class="px-4 py-3 text-right"
                    >
                        {{ audit.overstays }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
