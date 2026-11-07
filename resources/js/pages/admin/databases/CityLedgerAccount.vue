<script setup lang="ts">
import CityLedgerAccountForm, {
    type CityLedgerAccountFields,
} from '@/components/CityLedgerAccountForm.vue';
import ListViewButton from '@/components/ListViewButton.vue';
import Modal from '@/components/Modal.vue';
import type { TableColumn } from '@/components/TableColumnSettingsModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useCan } from '@/lib/useCan';
import { useColumnVisibility } from '@/lib/useColumnVisibility';
import { useCurrency } from '@/lib/useCurrency';
import { calendarDate } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

defineOptions({ layout: AdminLayout });

type BucketKey =
    | 'current'
    | 'days_1_30'
    | 'days_31_60'
    | 'days_61_90'
    | 'days_90_plus';

const props = defineProps<{
    property: { id: number; name: string };
    account: {
        id: number;
        name: string;
        code: string | null;
        account_type: string;
        agency_id: number | null;
        agency: string | null;
        tax_number: string | null;
        contact_name: string | null;
        email: string | null;
        phone: string | null;
        address: string | null;
        credit_limit: number | null;
        payment_terms_days: number;
        is_active: boolean;
        notes: string | null;
    };
    ageing: {
        balance: number;
        overdue: number;
        buckets: Record<BucketKey, number>;
    };
    statement: {
        opening: number;
        closing: number;
        entries: {
            id: number;
            entry_type: 'charge' | 'payment' | 'adjustment';
            entry_date: string;
            due_date: string | null;
            reference: string | null;
            description: string | null;
            booking_id: number | null;
            booking_ref_id: string | null;
            receipt_number: string | null;
            debit: number;
            credit: number;
            balance: number;
            created_by: string | null;
        }[];
    };
    period: { from: string; to: string };
    buckets: Record<BucketKey, string>;
    types: Record<string, string>;
    agencies: { id: number; name: string }[];
    paymentModes: { value: string; label: string }[];
}>();

const can = useCan();
const { money } = useCurrency();

// Date and Balance always show: the brought-forward and closing rows hang on them.
const columns: TableColumn[] = [
    { key: 'reference', label: 'Reference' },
    { key: 'particulars', label: 'Particulars' },
    { key: 'due', label: 'Due' },
    { key: 'debit', label: 'Debit' },
    { key: 'credit', label: 'Credit' },
    { key: 'by', label: 'By' },
];
const shown = useColumnVisibility(
    'city-ledger-statement-column-visibility',
    columns.map((column) => column.key),
);
/** The cells before Balance in the brought-forward and closing rows. */
const leadingColumns = computed(
    () =>
        1 +
        ['reference', 'particulars', 'due', 'debit', 'credit'].filter(
            (key) => shown[key],
        ).length,
);
const action = ref<'transfer' | 'payment' | 'adjust' | 'edit' | null>(null);
const period = reactive({ ...props.period });

const transferForm = useForm({
    booking_ref_id: '',
    amount: '' as number | string,
});
const paymentForm = useForm({
    amount: '' as number | string,
    payment_mode: 'Bank Transfer',
    reference: '',
});
const adjustForm = useForm({ amount: '' as number | string, description: '' });
const accountForm = useForm<CityLedgerAccountFields>({
    name: props.account.name,
    code: props.account.code ?? '',
    account_type: props.account.account_type,
    agency_id: props.account.agency_id,
    tax_number: props.account.tax_number ?? '',
    contact_name: props.account.contact_name ?? '',
    email: props.account.email ?? '',
    phone: props.account.phone ?? '',
    address: props.account.address ?? '',
    credit_limit: props.account.credit_limit,
    payment_terms_days: props.account.payment_terms_days,
    is_active: props.account.is_active,
    notes: props.account.notes ?? '',
});

const done = { preserveScroll: true, onSuccess: () => (action.value = null) };

function submitTransfer(): void {
    transferForm
        .transform((data) => ({
            ...data,
            amount: data.amount === '' ? null : data.amount,
        }))
        .post(admin.databases.cityLedger.transfer(props.account.id).url, {
            ...done,
            onSuccess: () => {
                action.value = null;
                transferForm.reset();
            },
        });
}

function submitPayment(): void {
    paymentForm.post(admin.databases.cityLedger.payment(props.account.id).url, {
        ...done,
        onSuccess: () => {
            action.value = null;
            paymentForm.reset();
        },
    });
}

function submitAdjustment(): void {
    adjustForm.post(admin.databases.cityLedger.adjust(props.account.id).url, {
        ...done,
        onSuccess: () => {
            action.value = null;
            adjustForm.reset();
        },
    });
}

function submitAccount(): void {
    accountForm.put(
        admin.databases.cityLedger.update(props.account.id).url,
        done,
    );
}

function showPeriod(): void {
    router.get(
        admin.databases.cityLedger.show(props.account.id).url,
        { ...period },
        { preserveScroll: true },
    );
}

const statementUrl = () =>
    admin.databases.cityLedger.statement(props.account.id, {
        query: { ...period },
    }).url;

const cardClass =
    'rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]';
const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const labelClass =
    'flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]';
const buttonClass =
    'rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm font-medium dark:border-[#3E3E3A] dark:text-[#EDEDEC]';
const primaryButton =
    'rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]';
</script>

<template>
    <Head :title="`City Ledger: ${account.name}`" />

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <Link
                :href="
                    admin.databases.cityLedger.index({
                        query: { property: property.id },
                    }).url
                "
                class="text-xs text-[#706f6c] underline dark:text-[#A1A09A]"
                >City Ledger</Link
            >
            <h1
                class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                {{ account.name }}
            </h1>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                {{ types[account.account_type]
                }}<span v-if="account.agency"> · {{ account.agency }}</span
                ><span v-if="account.code"> · {{ account.code }}</span> ·
                {{ account.payment_terms_days }}-day terms ·
                {{
                    account.credit_limit === null
                        ? 'No credit limit'
                        : `Credit limit ${money(account.credit_limit)}`
                }}
                <span
                    v-if="!account.is_active"
                    class="ml-1 rounded-full bg-stone-200 px-2 py-0.5 text-xs dark:bg-stone-800"
                    >Closed</span
                >
            </p>
        </div>
        <div
            v-if="can('database.city_ledger.manage')"
            class="flex flex-wrap gap-2"
        >
            <button
                type="button"
                :class="buttonClass"
                :disabled="!account.is_active"
                @click="action = 'transfer'"
            >
                Bill a booking
            </button>
            <button
                type="button"
                :class="buttonClass"
                @click="action = 'payment'"
            >
                Record payment
            </button>
            <button
                type="button"
                :class="buttonClass"
                @click="action = 'adjust'"
            >
                Adjustment
            </button>
            <button type="button" :class="buttonClass" @click="action = 'edit'">
                Edit account
            </button>
        </div>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-6">
        <div :class="cardClass">
            <p
                class="text-xs tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
            >
                Balance
            </p>
            <p
                class="mt-1 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                {{ money(ageing.balance) }}
            </p>
        </div>
        <div
            v-for="(label, bucket) in buckets"
            :key="bucket"
            :class="cardClass"
        >
            <p
                class="text-xs tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
            >
                {{ label }}
            </p>
            <p
                class="mt-1 text-xl font-semibold"
                :class="
                    bucket !== 'current' && ageing.buckets[bucket] > 0
                        ? 'text-red-700 dark:text-red-300'
                        : 'text-[#1b1b18] dark:text-[#EDEDEC]'
                "
            >
                {{ money(ageing.buckets[bucket]) }}
            </p>
        </div>
    </div>

    <div :class="cardClass">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <h2
                class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Statement
            </h2>
            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="showPeriod"
            >
                <label :class="labelClass"
                    >From
                    <input
                        v-model="period.from"
                        type="date"
                        required
                        :class="inputClass"
                /></label>
                <label :class="labelClass"
                    >To
                    <input
                        v-model="period.to"
                        type="date"
                        :min="period.from"
                        required
                        :class="inputClass"
                /></label>
                <button type="submit" :class="buttonClass">Show</button>
                <a
                    :href="statementUrl()"
                    target="_blank"
                    rel="noopener"
                    :class="buttonClass"
                    >Print statement</a
                >
                <ListViewButton :columns="columns" :visibility="shown" />
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm dark:text-[#EDEDEC]">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-1.5 pr-3">Date</th>
                        <th v-if="shown.reference" class="pr-3">Reference</th>
                        <th v-if="shown.particulars" class="pr-3">
                            Particulars
                        </th>
                        <th v-if="shown.due" class="pr-3">Due</th>
                        <th v-if="shown.debit" class="pr-3 text-right">
                            Debit
                        </th>
                        <th v-if="shown.credit" class="pr-3 text-right">
                            Credit
                        </th>
                        <th class="pr-3 text-right">Balance</th>
                        <th v-if="shown.by" class="pr-3">By</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]">
                        <td class="py-1.5 pr-3" :colspan="leadingColumns">
                            Balance brought forward
                        </td>
                        <td class="pr-3 text-right font-semibold">
                            {{ money(statement.opening) }}
                        </td>
                        <td v-if="shown.by"></td>
                    </tr>
                    <tr
                        v-for="entry in statement.entries"
                        :key="entry.id"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <td class="py-1.5 pr-3 whitespace-nowrap">
                            {{ calendarDate(entry.entry_date) }}
                        </td>
                        <td
                            v-if="shown.reference"
                            class="pr-3 whitespace-nowrap"
                        >
                            <Link
                                v-if="entry.booking_id"
                                :href="
                                    admin.bookings.show({
                                        property: property.id,
                                        booking: entry.booking_id,
                                    }).url
                                "
                                class="underline underline-offset-4"
                                >{{ entry.reference }}</Link
                            >
                            <span v-else>{{ entry.reference }}</span>
                        </td>
                        <td v-if="shown.particulars" class="pr-3">
                            {{ entry.description }}
                        </td>
                        <td v-if="shown.due" class="pr-3 whitespace-nowrap">
                            {{
                                entry.due_date
                                    ? calendarDate(entry.due_date)
                                    : ''
                            }}
                        </td>
                        <td
                            v-if="shown.debit"
                            class="pr-3 text-right whitespace-nowrap"
                        >
                            {{ entry.debit > 0 ? money(entry.debit) : '' }}
                        </td>
                        <td
                            v-if="shown.credit"
                            class="pr-3 text-right whitespace-nowrap"
                        >
                            {{ entry.credit > 0 ? money(entry.credit) : '' }}
                        </td>
                        <td class="pr-3 text-right whitespace-nowrap">
                            {{ money(entry.balance) }}
                        </td>
                        <td
                            v-if="shown.by"
                            class="pr-3 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{ entry.created_by }}
                        </td>
                    </tr>
                    <tr class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]">
                        <td
                            class="py-1.5 pr-3 font-semibold"
                            :colspan="leadingColumns"
                        >
                            Closing balance
                        </td>
                        <td class="pr-3 text-right font-semibold">
                            {{ money(statement.closing) }}
                        </td>
                        <td v-if="shown.by"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <Modal
        :show="action === 'transfer'"
        title="Bill a booking to this account"
        @close="action = null"
    >
        <form class="space-y-3" @submit.prevent="submitTransfer">
            <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                The booking's balance moves here: the guest owes nothing more,
                and the account owes it within
                {{ account.payment_terms_days }} days.
            </p>
            <label :class="labelClass">
                Booking reference
                <input
                    v-model="transferForm.booking_ref_id"
                    type="text"
                    required
                    maxlength="60"
                    :class="inputClass"
                />
                <span
                    v-if="transferForm.errors.booking_ref_id"
                    class="text-red-600"
                    >{{ transferForm.errors.booking_ref_id }}</span
                >
            </label>
            <label :class="labelClass">
                Amount (empty: all it owes)
                <input
                    v-model="transferForm.amount"
                    type="number"
                    min="0.01"
                    step="0.01"
                    :class="inputClass"
                />
                <span v-if="transferForm.errors.amount" class="text-red-600">{{
                    transferForm.errors.amount
                }}</span>
            </label>
            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="transferForm.processing"
                    :class="primaryButton"
                >
                    Bill to account
                </button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="action === 'payment'"
        title="Record a payment"
        @close="action = null"
    >
        <form class="space-y-3" @submit.prevent="submitPayment">
            <label :class="labelClass">
                Amount
                <input
                    v-model="paymentForm.amount"
                    type="number"
                    min="0.01"
                    step="0.01"
                    required
                    :class="inputClass"
                />
                <span v-if="paymentForm.errors.amount" class="text-red-600">{{
                    paymentForm.errors.amount
                }}</span>
            </label>
            <label :class="labelClass">
                Mode
                <select v-model="paymentForm.payment_mode" :class="inputClass">
                    <option
                        v-for="mode in paymentModes"
                        :key="mode.value"
                        :value="mode.value"
                    >
                        {{ mode.label }}
                    </option>
                </select>
            </label>
            <label :class="labelClass">
                Reference (UTR, cheque no.)
                <input
                    v-model="paymentForm.reference"
                    type="text"
                    maxlength="120"
                    :class="inputClass"
                />
            </label>
            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="paymentForm.processing"
                    :class="primaryButton"
                >
                    Record payment
                </button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="action === 'adjust'"
        title="Adjustment"
        @close="action = null"
    >
        <form class="space-y-3" @submit.prevent="submitAdjustment">
            <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                A positive amount adds to what the account owes; a negative one
                is a credit (write-off, agreed discount).
            </p>
            <label :class="labelClass">
                Amount
                <input
                    v-model="adjustForm.amount"
                    type="number"
                    step="0.01"
                    required
                    :class="inputClass"
                />
                <span v-if="adjustForm.errors.amount" class="text-red-600">{{
                    adjustForm.errors.amount
                }}</span>
            </label>
            <label :class="labelClass">
                Reason
                <input
                    v-model="adjustForm.description"
                    type="text"
                    required
                    maxlength="500"
                    :class="inputClass"
                />
            </label>
            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="adjustForm.processing"
                    :class="primaryButton"
                >
                    Post adjustment
                </button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="action === 'edit'"
        title="Edit account"
        max-width="max-w-2xl"
        @close="action = null"
    >
        <CityLedgerAccountForm
            :form="accountForm"
            :types="types"
            :agencies="agencies"
            @submit="submitAccount"
            @cancel="action = null"
        />
    </Modal>
</template>
