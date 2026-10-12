<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useCan } from '@/lib/useCan';
import { useCurrency } from '@/lib/useCurrency';
import { calendarDate } from '@/lib/usePropertyTime';
import { useUrlTab } from '@/lib/useUrlTab';
import admin from '@/routes/admin';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

defineOptions({ layout: AdminLayout });

type Account = {
    id: number;
    code: string;
    name: string;
    type: string;
    system_key: string | null;
    is_active: boolean;
};
type Mapping = {
    key: string;
    label: string;
    gl_account_id: number | null;
    default?: string;
};

const props = defineProps<{
    property: { id: number; name: string };
    asOf: string;
    trialBalance: {
        rows: {
            id: number;
            code: string;
            name: string;
            type: string;
            debit: number;
            credit: number;
            balance_debit: number;
            balance_credit: number;
            balance: number;
        }[];
        total_debit: number;
        total_credit: number;
    };
    control: {
        gl_balance: number;
        deposits_held: number;
        folio_balance: number;
        difference: number;
        unjournalled: number;
    };
    accounts: Account[];
    types: Record<string, string>;
    statement: {
        account_id: number;
        from: string;
        to: string;
        opening: number;
        closing: number;
        lines: {
            id: number;
            journal_entry_id: number;
            business_date: string;
            description: string | null;
            debit: number;
            credit: number;
            balance: number;
        }[];
    } | null;
    period: { from: string; to: string };
    chargeCodes: Mapping[];
    paymentModes: Mapping[];
}>();

const can = useCan();
const { money } = useCurrency();
const canManage = computed(() =>
    can('manage_property.accounts_finance_settings'),
);
const tabs = ['trial_balance', 'statement', 'accounts', 'mappings'] as const;
const tabLabels: Record<(typeof tabs)[number], string> = {
    trial_balance: 'Trial balance',
    statement: 'Account statement',
    accounts: 'Chart of accounts',
    mappings: 'Posting rules',
};
const activeTab = useUrlTab(
    tabs,
    props.statement ? 'statement' : 'trial_balance',
);

const asOf = ref(props.asOf);
const statementFilter = reactive({
    account: props.statement?.account_id ?? props.accounts[0]?.id ?? null,
    from: props.period.from,
    to: props.period.to,
});
const showUrl = admin.generalLedger.show(props.property.id).url;

function showTrialBalance(): void {
    router.get(
        showUrl,
        { as_of: asOf.value, tab: 'trial_balance' },
        { preserveScroll: true },
    );
}

function showStatement(): void {
    router.get(
        showUrl,
        { ...statementFilter, tab: 'statement' },
        { preserveScroll: true },
    );
}

function openStatement(accountId: number): void {
    statementFilter.account = accountId;
    showStatement();
}

const accountName = (id: number | null | undefined): string => {
    const account = props.accounts.find((item) => item.id === id);

    return account ? `${account.code} · ${account.name}` : '—';
};

const defaultAccountName = (systemKey: string | undefined): string => {
    const account = props.accounts.find(
        (item) => item.system_key === systemKey,
    );

    return account ? `${account.code} · ${account.name}` : 'Default';
};

// Chart of accounts.
const newAccount = useForm({ code: '', name: '', type: 'expense' });
const editing = ref<number | null>(null);
const editForm = useForm({ code: '', name: '', is_active: true });

function addAccount(): void {
    newAccount.post(admin.generalLedger.accounts.store(props.property.id).url, {
        preserveScroll: true,
        onSuccess: () => newAccount.reset(),
    });
}

function startEdit(account: Account): void {
    editing.value = account.id;
    editForm.code = account.code;
    editForm.name = account.name;
    editForm.is_active = account.is_active;
}

function saveEdit(account: Account): void {
    editForm.put(
        admin.generalLedger.accounts.update({
            property: props.property.id,
            glAccount: account.id,
        }).url,
        { preserveScroll: true, onSuccess: () => (editing.value = null) },
    );
}

// Posting rules.
const mappingForm = useForm({
    charge: Object.fromEntries(
        props.chargeCodes.map((item) => [item.key, item.gl_account_id]),
    ) as Record<string, number | null>,
    mode: Object.fromEntries(
        props.paymentModes.map((item) => [item.key, item.gl_account_id]),
    ) as Record<string, number | null>,
});

function saveMappings(): void {
    mappingForm
        .transform((data) => ({
            mappings: [
                ...Object.entries(data.charge).map(([key, id]) => ({
                    type: 'charge_code',
                    key,
                    gl_account_id: id,
                })),
                ...Object.entries(data.mode).map(([key, id]) => ({
                    type: 'payment_mode',
                    key,
                    gl_account_id: id,
                })),
            ],
        }))
        .put(admin.generalLedger.mappings.update(props.property.id).url, {
            preserveScroll: true,
        });
}

const activeAccounts = computed(() =>
    props.accounts.filter((account) => account.is_active),
);
const balanced = computed(
    () =>
        Math.abs(
            props.trialBalance.total_debit - props.trialBalance.total_credit,
        ) < 0.01,
);

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
    <Head :title="`General Ledger: ${property.name}`" />

    <div class="mb-4">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            General Ledger
        </h1>
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            {{ property.name }}: every folio charge and payment is posted as a
            balanced journal entry.
        </p>
    </div>

    <div
        class="mb-4 rounded-lg border p-3 text-sm"
        :class="
            Math.abs(control.difference) < 0.01
                ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
                : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
        "
    >
        Guest Ledger account {{ money(control.gl_balance)
        }}<template v-if="control.deposits_held">
            less advance deposits held
            {{ money(control.deposits_held) }}</template
        >
        · folio balances {{ money(control.folio_balance) }}
        <template v-if="Math.abs(control.difference) < 0.01"
            >: they agree.</template
        >
        <template v-else
            >: {{ money(control.difference) }} apart, with
            {{ control.unjournalled }} folio entr(ies) not journalled. Run
            <code>php artisan ledger:journal-backfill</code>.</template
        >
    </div>

    <div
        class="mb-4 flex flex-wrap gap-1 border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
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

    <!-- Trial balance -->
    <div v-if="activeTab === 'trial_balance'" :class="cardClass">
        <form
            class="mb-3 flex flex-wrap items-end gap-2"
            @submit.prevent="showTrialBalance"
        >
            <label :class="labelClass"
                >As on
                <input v-model="asOf" type="date" required :class="inputClass"
            /></label>
            <button type="submit" :class="buttonClass">Show</button>
            <span
                class="text-sm"
                :class="
                    balanced
                        ? 'text-emerald-700 dark:text-emerald-300'
                        : 'text-red-700 dark:text-red-300'
                "
                >{{
                    balanced ? 'Debits equal credits.' : 'Out of balance!'
                }}</span
            >
        </form>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm dark:text-[#EDEDEC]">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-1.5 pr-3">Code</th>
                        <th class="pr-3">Account</th>
                        <th class="pr-3">Type</th>
                        <th class="pr-3 text-right">Debit</th>
                        <th class="pr-3 text-right">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in trialBalance.rows"
                        :key="row.id"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <td class="py-1.5 pr-3">{{ row.code }}</td>
                        <td class="pr-3">
                            <button
                                type="button"
                                class="underline underline-offset-4"
                                @click="openStatement(row.id)"
                            >
                                {{ row.name }}
                            </button>
                        </td>
                        <td class="pr-3">{{ types[row.type] }}</td>
                        <td class="pr-3 text-right whitespace-nowrap">
                            {{
                                row.balance_debit
                                    ? money(row.balance_debit)
                                    : ''
                            }}
                        </td>
                        <td class="pr-3 text-right whitespace-nowrap">
                            {{
                                row.balance_credit
                                    ? money(row.balance_credit)
                                    : ''
                            }}
                        </td>
                    </tr>
                    <tr
                        class="border-t-2 border-[#1b1b18] font-semibold dark:border-[#EDEDEC]"
                    >
                        <td class="py-1.5 pr-3" colspan="3">Total</td>
                        <td class="pr-3 text-right whitespace-nowrap">
                            {{ money(trialBalance.total_debit) }}
                        </td>
                        <td class="pr-3 text-right whitespace-nowrap">
                            {{ money(trialBalance.total_credit) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Account statement -->
    <div v-else-if="activeTab === 'statement'" :class="cardClass">
        <form
            class="mb-3 flex flex-wrap items-end gap-2"
            @submit.prevent="showStatement"
        >
            <label :class="labelClass">
                Account
                <select
                    v-model="statementFilter.account"
                    required
                    :class="inputClass"
                >
                    <option
                        v-for="account in accounts"
                        :key="account.id"
                        :value="account.id"
                    >
                        {{ account.code }} · {{ account.name }}
                    </option>
                </select>
            </label>
            <label :class="labelClass"
                >From
                <input
                    v-model="statementFilter.from"
                    type="date"
                    required
                    :class="inputClass"
            /></label>
            <label :class="labelClass"
                >To
                <input
                    v-model="statementFilter.to"
                    type="date"
                    :min="statementFilter.from"
                    required
                    :class="inputClass"
            /></label>
            <button type="submit" :class="buttonClass">Show</button>
        </form>
        <p v-if="!statement" class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Pick an account.
        </p>
        <div v-else class="overflow-x-auto">
            <p class="mb-2 text-sm font-semibold dark:text-[#EDEDEC]">
                {{ accountName(statement.account_id) }},
                {{ calendarDate(statement.from) }} to
                {{ calendarDate(statement.to) }}
            </p>
            <table class="w-full text-left text-sm dark:text-[#EDEDEC]">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-1.5 pr-3">Date</th>
                        <th class="pr-3">Entry</th>
                        <th class="pr-3">Particulars</th>
                        <th class="pr-3 text-right">Debit</th>
                        <th class="pr-3 text-right">Credit</th>
                        <th class="pr-3 text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]">
                        <td class="py-1.5 pr-3" colspan="5">
                            Balance brought forward
                        </td>
                        <td class="pr-3 text-right font-semibold">
                            {{ money(statement.opening) }}
                        </td>
                    </tr>
                    <tr
                        v-for="line in statement.lines"
                        :key="line.id"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <td class="py-1.5 pr-3 whitespace-nowrap">
                            {{ calendarDate(line.business_date) }}
                        </td>
                        <td class="pr-3">#{{ line.journal_entry_id }}</td>
                        <td class="pr-3">{{ line.description }}</td>
                        <td class="pr-3 text-right whitespace-nowrap">
                            {{ line.debit ? money(line.debit) : '' }}
                        </td>
                        <td class="pr-3 text-right whitespace-nowrap">
                            {{ line.credit ? money(line.credit) : '' }}
                        </td>
                        <td class="pr-3 text-right whitespace-nowrap">
                            {{ money(line.balance) }}
                        </td>
                    </tr>
                    <tr
                        class="border-t border-[#e3e3e0] font-semibold dark:border-[#3E3E3A]"
                    >
                        <td class="py-1.5 pr-3" colspan="5">Closing balance</td>
                        <td class="pr-3 text-right">
                            {{ money(statement.closing) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Chart of accounts -->
    <div v-else-if="activeTab === 'accounts'" class="space-y-4">
        <form
            v-if="canManage"
            :class="[cardClass, 'flex flex-wrap items-end gap-2']"
            @submit.prevent="addAccount"
        >
            <label :class="labelClass"
                >Code
                <input
                    v-model="newAccount.code"
                    type="text"
                    required
                    maxlength="20"
                    :class="inputClass"
            /></label>
            <label :class="[labelClass, 'flex-1']"
                >Name
                <input
                    v-model="newAccount.name"
                    type="text"
                    required
                    maxlength="255"
                    :class="inputClass"
            /></label>
            <label :class="labelClass">
                Type
                <select v-model="newAccount.type" :class="inputClass">
                    <option
                        v-for="(label, value) in types"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
            </label>
            <button
                type="submit"
                :disabled="newAccount.processing"
                :class="primaryButton"
            >
                Add account
            </button>
            <p
                v-if="newAccount.errors.code"
                class="w-full text-xs text-red-600"
            >
                {{ newAccount.errors.code }}
            </p>
        </form>
        <div :class="cardClass">
            <table class="w-full text-left text-sm dark:text-[#EDEDEC]">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-1.5 pr-3">Code</th>
                        <th class="pr-3">Name</th>
                        <th class="pr-3">Type</th>
                        <th class="pr-3"></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="account in accounts"
                        :key="account.id"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <template v-if="editing === account.id">
                            <td class="py-1.5 pr-3">
                                <input
                                    v-model="editForm.code"
                                    type="text"
                                    maxlength="20"
                                    :class="[inputClass, 'w-24']"
                                />
                            </td>
                            <td class="pr-3">
                                <input
                                    v-model="editForm.name"
                                    type="text"
                                    maxlength="255"
                                    :class="[inputClass, 'w-full']"
                                />
                            </td>
                            <td class="pr-3">{{ types[account.type] }}</td>
                            <td class="pr-3">
                                <label
                                    v-if="!account.system_key"
                                    class="text-xs"
                                    ><input
                                        v-model="editForm.is_active"
                                        type="checkbox"
                                    />
                                    Active</label
                                >
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <button
                                    type="button"
                                    class="text-xs underline"
                                    @click="saveEdit(account)"
                                >
                                    Save
                                </button>
                                <button
                                    type="button"
                                    class="ml-2 text-xs underline"
                                    @click="editing = null"
                                >
                                    Cancel
                                </button>
                            </td>
                        </template>
                        <template v-else>
                            <td class="py-1.5 pr-3">{{ account.code }}</td>
                            <td class="pr-3">
                                {{ account.name
                                }}<span
                                    v-if="!account.is_active"
                                    class="ml-1 text-xs text-[#706f6c]"
                                    >(inactive)</span
                                >
                            </td>
                            <td class="pr-3">{{ types[account.type] }}</td>
                            <td
                                class="pr-3 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ account.system_key ? 'System' : '' }}
                            </td>
                            <td class="text-right">
                                <button
                                    v-if="canManage"
                                    type="button"
                                    class="text-xs underline"
                                    @click="startEdit(account)"
                                >
                                    Edit
                                </button>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Posting rules -->
    <form
        v-else
        :class="[cardClass, 'space-y-4']"
        @submit.prevent="saveMappings"
    >
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Where each charge code and payment mode posts. Leave it on Default
            to use the standard account. Changes apply to new entries.
        </p>
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div>
                <h3
                    class="mb-2 text-xs font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Charge codes
                </h3>
                <div
                    v-for="item in chargeCodes"
                    :key="item.key"
                    class="mb-1.5 flex items-center justify-between gap-2 text-sm dark:text-[#EDEDEC]"
                >
                    <span>{{ item.label }}</span>
                    <select
                        v-model="mappingForm.charge[item.key]"
                        :disabled="!canManage"
                        :class="[inputClass, 'w-64']"
                    >
                        <option :value="null">Default</option>
                        <option
                            v-for="account in activeAccounts"
                            :key="account.id"
                            :value="account.id"
                        >
                            {{ account.code }} · {{ account.name }}
                        </option>
                    </select>
                </div>
            </div>
            <div>
                <h3
                    class="mb-2 text-xs font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Payment modes
                </h3>
                <div
                    v-for="item in paymentModes"
                    :key="item.key"
                    class="mb-1.5 flex items-center justify-between gap-2 text-sm dark:text-[#EDEDEC]"
                >
                    <span>{{ item.label }}</span>
                    <select
                        v-model="mappingForm.mode[item.key]"
                        :disabled="!canManage"
                        :class="[inputClass, 'w-64']"
                    >
                        <option :value="null">
                            Default ({{ defaultAccountName(item.default) }})
                        </option>
                        <option
                            v-for="account in activeAccounts"
                            :key="account.id"
                            :value="account.id"
                        >
                            {{ account.code }} · {{ account.name }}
                        </option>
                    </select>
                </div>
            </div>
        </div>
        <button
            v-if="canManage"
            type="submit"
            :disabled="mappingForm.processing"
            :class="primaryButton"
        >
            Save posting rules
        </button>
    </form>
</template>
