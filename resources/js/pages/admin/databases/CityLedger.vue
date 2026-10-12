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
import admin from '@/routes/admin';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineOptions({ layout: AdminLayout });

type Buckets = Record<
    'current' | 'days_1_30' | 'days_31_60' | 'days_61_90' | 'days_90_plus',
    number
>;

const props = defineProps<{
    property: { id: number; name: string } | null;
    accounts: {
        id: number;
        name: string;
        code: string | null;
        account_type: string;
        email: string | null;
        credit_limit: number | null;
        available_credit: number | null;
        payment_terms_days: number;
        is_active: boolean;
        balance: number;
        overdue: number;
        buckets: Buckets;
    }[];
    totals: { balance: number; overdue: number } & Buckets;
    buckets: Record<keyof Buckets, string>;
    types: Record<string, string>;
    agencies: { id: number; name: string }[];
}>();

const can = useCan();
const { money } = useCurrency();
const search = ref('');
const creating = ref(false);

const form = useForm<CityLedgerAccountFields>({
    name: '',
    code: '',
    account_type: 'company',
    agency_id: null,
    tax_number: '',
    contact_name: '',
    email: '',
    phone: '',
    address: '',
    credit_limit: null,
    payment_terms_days: 30,
    is_active: true,
    notes: '',
});

// The account name always shows: it opens the account.
const columns: TableColumn[] = [
    { key: 'type', label: 'Type' },
    { key: 'balance', label: 'Balance' },
    { key: 'credit_left', label: 'Credit left' },
    ...Object.entries(props.buckets).map(([key, label]) => ({ key, label })),
];
const shown = useColumnVisibility(
    'city-ledger-column-visibility',
    columns.map((column) => column.key),
);
const shownBuckets = computed(
    () =>
        Object.fromEntries(
            Object.entries(props.buckets).filter(([bucket]) => shown[bucket]),
        ) as Partial<Record<keyof Buckets, string>>,
);

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return term === ''
        ? props.accounts
        : props.accounts.filter((account) =>
              [account.name, account.code, account.email].some((value) =>
                  (value ?? '').toLowerCase().includes(term),
              ),
          );
});

function create(): void {
    if (props.property) {
        form.post(admin.databases.cityLedger.store(props.property.id).url, {
            onSuccess: () => (creating.value = false),
        });
    }
}

const cardClass =
    'rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]';
</script>

<template>
    <Head title="City Ledger" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1
                class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                City Ledger
            </h1>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Companies and agencies billed on credit{{
                    property ? ` at ${property.name}` : ''
                }}.
            </p>
        </div>
        <button
            v-if="property && can('database.city_ledger.manage')"
            type="button"
            class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
            @click="creating = true"
        >
            New account
        </button>
    </div>

    <p v-if="!property" class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
        Select a hotel from the dropdown at the top of the page.
    </p>

    <template v-else>
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div :class="cardClass">
                <p
                    class="text-xs tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Owed to the hotel
                </p>
                <p
                    class="mt-1 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ money(totals.balance) }}
                </p>
            </div>
            <div :class="cardClass">
                <p
                    class="text-xs tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Overdue
                </p>
                <p
                    class="mt-1 text-2xl font-semibold"
                    :class="
                        totals.overdue > 0
                            ? 'text-red-700 dark:text-red-300'
                            : 'text-[#1b1b18] dark:text-[#EDEDEC]'
                    "
                >
                    {{ money(totals.overdue) }}
                </p>
            </div>
            <div :class="cardClass">
                <p
                    class="text-xs tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Over 60 days
                </p>
                <p
                    class="mt-1 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ money(totals.days_61_90 + totals.days_90_plus) }}
                </p>
            </div>
            <div :class="cardClass">
                <p
                    class="text-xs tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Accounts
                </p>
                <p
                    class="mt-1 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ accounts.length }}
                </p>
            </div>
        </div>

        <div class="mb-3 flex items-center justify-between gap-2">
            <input
                v-model="search"
                type="search"
                placeholder="Search accounts"
                class="w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm sm:w-72 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
            />
            <ListViewButton :columns="columns" :visibility="shown" />
        </div>

        <div :class="cardClass">
            <p
                v-if="!filtered.length"
                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
            >
                No accounts yet.
            </p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm dark:text-[#EDEDEC]">
                    <thead
                        class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        <tr>
                            <th class="py-1.5 pr-3">Account</th>
                            <th v-if="shown.type" class="pr-3">Type</th>
                            <th v-if="shown.balance" class="pr-3 text-right">
                                Balance
                            </th>
                            <th
                                v-if="shown.credit_left"
                                class="pr-3 text-right"
                            >
                                Credit left
                            </th>
                            <th
                                v-for="(label, bucket) in shownBuckets"
                                :key="bucket"
                                class="pr-3 text-right"
                            >
                                {{ label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="account in filtered"
                            :key="account.id"
                            class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                        >
                            <td class="py-2 pr-3">
                                <Link
                                    :href="
                                        admin.databases.cityLedger.show(
                                            account.id,
                                        ).url
                                    "
                                    class="font-medium underline underline-offset-4"
                                    >{{ account.name }}</Link
                                >
                                <span
                                    v-if="account.code"
                                    class="ml-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                    >{{ account.code }}</span
                                >
                                <span
                                    v-if="!account.is_active"
                                    class="ml-1 rounded-full bg-stone-200 px-2 py-0.5 text-xs dark:bg-stone-800"
                                    >Closed</span
                                >
                            </td>
                            <td v-if="shown.type" class="pr-3">
                                {{
                                    types[account.account_type] ??
                                    account.account_type
                                }}
                            </td>
                            <td
                                v-if="shown.balance"
                                class="pr-3 text-right font-semibold whitespace-nowrap"
                            >
                                {{ money(account.balance) }}
                            </td>
                            <td
                                v-if="shown.credit_left"
                                class="pr-3 text-right whitespace-nowrap"
                                :class="
                                    account.available_credit !== null &&
                                    account.available_credit < 0
                                        ? 'text-red-700 dark:text-red-300'
                                        : ''
                                "
                            >
                                {{
                                    account.available_credit === null
                                        ? 'No limit'
                                        : money(account.available_credit)
                                }}
                            </td>
                            <td
                                v-for="(label, bucket) in shownBuckets"
                                :key="bucket"
                                class="pr-3 text-right whitespace-nowrap"
                                :class="
                                    bucket !== 'current' &&
                                    account.buckets[bucket] > 0
                                        ? 'text-red-700 dark:text-red-300'
                                        : ''
                                "
                            >
                                {{ money(account.buckets[bucket]) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    <Modal
        :show="creating"
        title="New city ledger account"
        max-width="max-w-2xl"
        @close="creating = false"
    >
        <CityLedgerAccountForm
            :form="form"
            :types="types"
            :agencies="agencies"
            @submit="create"
            @cancel="creating = false"
        />
    </Modal>
</template>
