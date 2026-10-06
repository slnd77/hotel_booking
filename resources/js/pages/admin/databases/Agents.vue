<script setup lang="ts">
import PhoneCountryCodeSelect, {
    type PhoneCountryOption,
} from '@/components/PhoneCountryCodeSelect.vue';
import TableColumnSettingsModal, {
    type TableColumn,
} from '@/components/TableColumnSettingsModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useCan } from '@/lib/useCan';
import { useColumnVisibility } from '@/lib/useColumnVisibility';
import { usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineOptions({ layout: AdminLayout });

type AgentRow = {
    id: number;
    agency_id: number;
    agency_name: string;
    agency_code: string;
    agency_class: string | null;
    agent_rate_code_id: number | null;
    source: string;
    contact_name: string;
    phone_country_code: string | null;
    phone: string | null;
    mobile: string | null;
    emails: string[];
    email: string;
    address: string | null;
    city: string | null;
    created_at: string | null;
    created_by: string | null;
    updated_at: string | null;
    updated_by: string | null;
    deleted_at: string | null;
};

type Agency = {
    id: number;
    name: string;
    source: string;
    agent_rate_code_id: number | null;
    address: string | null;
    city: string | null;
};

const columns: TableColumn[] = [
    { key: 'agency_name', label: 'Agency Name' },
    { key: 'agency_code', label: 'Agency Code' },
    { key: 'agency_class', label: 'Agency Class' },
    { key: 'contact_name', label: 'Agent/Contact' },
    { key: 'mobile', label: 'Mobile' },
    { key: 'email', label: 'E-mail' },
    { key: 'address', label: 'Address' },
    { key: 'city', label: 'City' },
    { key: 'source', label: 'Source' },
];

const props = defineProps<{
    property: { id: number; name: string; country_id: number | null } | null;
    agents: {
        data: AgentRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    } | null;
    filters: { q?: string; letter?: string; trashed?: boolean };
    agencies: Agency[];
    rateCodes: { id: number; name: string; is_active: boolean }[];
    sources: { value: string; label: string }[];
    countries: PhoneCountryOption[];
}>();

const propertyTime = usePropertyTime();
const can = useCan();
const showingDeleted = computed(() => Boolean(props.filters.trashed));
const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
const searchTerm = ref(props.filters.q ?? '');
const columnSettingsOpen = ref(false);
const columnVisibility = useColumnVisibility(
    'agents-column-visibility',
    columns.map((column) => column.key),
);
const visibleColumns = computed(() =>
    columns.filter((column) => columnVisibility[column.key]),
);

function onColumnsSaved(next: Record<string, boolean>): void {
    Object.assign(columnVisibility, next);
    columnSettingsOpen.value = false;
}

function reloadWith(params: Record<string, string | undefined>): void {
    router.get(
        admin.databases.agents.index().url,
        {
            property: props.property?.id,
            ...props.filters,
            ...params,
            page: undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function submitSearch(): void {
    reloadWith({ q: searchTerm.value.trim() || undefined });
}

const downloadHref = computed(
    () =>
        admin.databases.agents.download({
            query: { property: props.property?.id, ...props.filters },
        }).url,
);

const sourceLabels = computed(() =>
    Object.fromEntries(
        props.sources.map((source) => [source.value, source.label]),
    ),
);

function cellValue(agent: AgentRow, key: string): string {
    if (key === 'source') {
        return sourceLabels.value[agent.source] ?? agent.source;
    }

    const value = agent[key as keyof AgentRow];

    return value === null || value === '' ? '' : String(value);
}

/* Add/Update Agent */

const formOpen = ref(false);
const editing = ref<AgentRow | null>(null);
const historyOpen = ref(false);

const defaultDialCode = computed(() => {
    const country = props.countries.find(
        (item) => item.id === props.property?.country_id,
    );

    return country ? `+${country.phonecode}` : '+91';
});

const form = useForm({
    source: 'agent',
    agency_name: '',
    agent_rate_code_id: '' as number | '',
    contact_name: '',
    phone_country_code: '+91',
    phone: '',
    emails: [''] as string[],
    address: '',
    city: '',
});

/** Rate codes to offer: active ones, plus the agency's current one even if switched off. */
const rateCodeOptions = computed(() =>
    props.rateCodes.filter(
        (rateCode) =>
            rateCode.is_active || rateCode.id === form.agent_rate_code_id,
    ),
);

const matchedAgency = computed(
    () =>
        props.agencies.find(
            (agency) =>
                agency.name.toLowerCase() ===
                form.agency_name.trim().toLowerCase(),
        ) ?? null,
);

function openForm(agent: AgentRow | null = null): void {
    editing.value = agent;
    historyOpen.value = false;
    form.defaults({
        source: agent?.source ?? 'agent',
        agency_name: agent?.agency_name ?? '',
        agent_rate_code_id: agent?.agent_rate_code_id ?? '',
        contact_name: agent?.contact_name ?? '',
        phone_country_code: agent?.phone_country_code ?? defaultDialCode.value,
        phone: agent?.phone ?? '',
        emails: agent?.emails.length ? [...agent.emails] : [''],
        address: agent?.address ?? '',
        city: agent?.city ?? '',
    });
    form.reset();
    form.clearErrors();
    formOpen.value = true;
}

function closeForm(): void {
    formOpen.value = false;
}

/** Picking an existing agency brings in its source, rate code and address. */
function onAgencyChosen(): void {
    const agency = matchedAgency.value;

    if (!agency) {
        return;
    }

    form.source = agency.source;
    form.agent_rate_code_id = agency.agent_rate_code_id ?? '';
    form.address = agency.address ?? '';
    form.city = agency.city ?? '';
}

function emailError(index: number): string | undefined {
    return (form.errors as Record<string, string>)[`emails.${index}`];
}

/** Soft deletes the agent being edited; "Show deleted" lists it again, with Restore. */
function destroyAgent(): void {
    if (!props.property || !editing.value) {
        return;
    }

    router.delete(
        admin.databases.agents.destroy({
            property: props.property.id,
            agent: editing.value.id,
        }).url,
        {
            preserveScroll: true,
            onSuccess: () => closeForm(),
        },
    );
}

function restoreAgent(agent: AgentRow): void {
    if (!props.property) {
        return;
    }

    router.post(
        admin.databases.agents.restore({
            property: props.property.id,
            agent: agent.id,
        }).url,
        {},
        { preserveScroll: true },
    );
}

function submit(): void {
    if (!props.property) {
        return;
    }

    const options = { preserveScroll: true, onSuccess: () => closeForm() };
    const transformed = form.transform((data) => ({
        ...data,
        agent_rate_code_id:
            data.agent_rate_code_id === '' ? null : data.agent_rate_code_id,
    }));

    if (editing.value) {
        transformed.put(
            admin.databases.agents.update({
                property: props.property.id,
                agent: editing.value.id,
            }).url,
            options,
        );
    } else {
        transformed.post(
            admin.databases.agents.store(props.property.id).url,
            options,
        );
    }
}

const inputClass =
    'w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const labelClass =
    'mb-1 block text-xs font-medium text-[#1b1b18] dark:text-[#EDEDEC]';
const iconButtonClass =
    'rounded-md border border-[#e3e3e0] p-2 text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]';
</script>

<template>
    <Head title="Agents" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Agents
        </h1>
        <Link
            v-if="property"
            :href="admin.agentSettings.edit(property.id).url"
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
        >
            Rate codes
        </Link>
    </div>

    <div
        v-if="!property || !agents"
        class="rounded-lg border border-dashed border-[#e3e3e0] bg-white px-6 py-16 text-center text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#A1A09A]"
    >
        Select a property to view its agents.
    </div>

    <template v-else>
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <form
                class="flex flex-1 items-center gap-2"
                @submit.prevent="submitSearch"
            >
                <input
                    v-model="searchTerm"
                    type="text"
                    placeholder="Search Agents / Agencies"
                    class="block w-full max-w-xl rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
                <button
                    type="submit"
                    class="rounded-md bg-[#0284c7] px-4 py-2 text-sm font-medium text-white hover:bg-[#0369a1]"
                >
                    Search
                </button>
                <button
                    v-if="filters.q || filters.letter"
                    type="button"
                    class="rounded-md px-3 py-2 text-sm font-medium text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]"
                    @click="
                        searchTerm = '';
                        reloadWith({ q: undefined, letter: undefined });
                    "
                >
                    Reset
                </button>
                <label
                    v-if="can('database.agents.delete')"
                    class="ml-2 flex items-center gap-1.5 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    <input
                        type="checkbox"
                        class="h-4 w-4"
                        :checked="showingDeleted"
                        @change="
                            reloadWith({
                                trashed: showingDeleted ? undefined : '1',
                            })
                        "
                    />
                    Show deleted
                </label>
            </form>

            <div class="flex items-center gap-2">
                <Link
                    v-if="agents.prev_page_url"
                    :href="agents.prev_page_url"
                    preserve-state
                    preserve-scroll
                    class="text-sm text-[#0284c7] hover:underline"
                    >&laquo; Prev</Link
                >
                <span v-else class="text-sm text-[#A1A09A]">&laquo; Prev</span>
                <Link
                    v-if="agents.next_page_url"
                    :href="agents.next_page_url"
                    preserve-state
                    preserve-scroll
                    class="text-sm text-[#0284c7] hover:underline"
                    >Next &raquo;</Link
                >
                <span v-else class="text-sm text-[#A1A09A]">Next &raquo;</span>

                <button
                    type="button"
                    title="Column settings"
                    :class="iconButtonClass"
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
                <a
                    :href="downloadHref"
                    title="Download agent list"
                    :class="iconButtonClass"
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
                            d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"
                        />
                    </svg>
                </a>
                <button
                    type="button"
                    class="rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-medium text-white hover:bg-[#d92a02]"
                    @click="openForm()"
                >
                    Add
                </button>
            </div>
        </div>

        <div class="mb-3 flex flex-wrap justify-end gap-0.5">
            <button
                v-for="letter in letters"
                :key="letter"
                type="button"
                class="min-w-7 rounded px-2 py-1 text-sm"
                :class="
                    filters.letter === letter
                        ? 'bg-[#0284c7] text-white'
                        : 'text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]'
                "
                @click="reloadWith({ letter })"
            >
                {{ letter }}
            </button>
            <button
                type="button"
                class="rounded px-3 py-1 text-sm"
                :class="
                    !filters.letter
                        ? 'bg-[#0284c7] text-white'
                        : 'text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]'
                "
                @click="reloadWith({ letter: undefined })"
            >
                ALL
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
                            v-if="showingDeleted"
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Deleted
                        </th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                >
                    <tr
                        v-for="agent in agents.data"
                        :key="agent.id"
                        class="hover:bg-[#fafaf9] dark:hover:bg-[#1D1D1B]"
                        :class="
                            agent.deleted_at ? 'opacity-70' : 'cursor-pointer'
                        "
                        @click="agent.deleted_at ? undefined : openForm(agent)"
                    >
                        <td
                            v-for="column in visibleColumns"
                            :key="column.key"
                            class="px-4 py-3 text-sm whitespace-nowrap text-[#706f6c] dark:text-[#A1A09A]"
                            :class="{
                                'font-medium text-[#0284c7]':
                                    column.key === 'agency_name',
                            }"
                        >
                            {{ cellValue(agent, column.key) }}
                        </td>
                        <td
                            v-if="showingDeleted"
                            class="px-4 py-3 text-sm whitespace-nowrap text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <span>{{
                                propertyTime.dateTime(agent.deleted_at)
                            }}</span>
                            <button
                                type="button"
                                class="ml-3 text-sm font-medium text-[#0284c7] hover:underline"
                                @click.stop="restoreAgent(agent)"
                            >
                                Restore
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!agents.data.length">
                        <td
                            :colspan="
                                visibleColumns.length + (showingDeleted ? 1 : 0)
                            "
                            class="px-4 py-8 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{
                                showingDeleted
                                    ? 'No deleted agents.'
                                    : 'No agents found.'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
            {{ agents.total }} agent{{ agents.total === 1 ? '' : 's' }}
        </p>

        <TableColumnSettingsModal
            :show="columnSettingsOpen"
            :columns="columns"
            :model-value="columnVisibility"
            @close="columnSettingsOpen = false"
            @save="onColumnsSaved"
        />

        <div
            v-if="formOpen"
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/30 p-4 pt-16"
            @click.self="closeForm"
        >
            <form
                class="w-full max-w-3xl rounded-lg border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
                @submit.prevent="submit"
            >
                <div
                    class="flex items-center justify-between bg-[#1b1b18] px-6 py-3"
                >
                    <h2 class="font-display text-base font-semibold text-white">
                        Add/Update Agent
                    </h2>
                    <button
                        type="button"
                        class="text-white/80 hover:text-white"
                        aria-label="Close"
                        @click="closeForm"
                    >
                        ✕
                    </button>
                </div>

                <div class="flex flex-col gap-4 px-6 py-5">
                    <div class="w-48">
                        <label for="agent_source" :class="labelClass"
                            >Source</label
                        >
                        <select
                            id="agent_source"
                            v-model="form.source"
                            :class="inputClass"
                        >
                            <option
                                v-for="source in sources"
                                :key="source.value"
                                :value="source.value"
                            >
                                {{ source.label }}
                            </option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="agent_agency" :class="labelClass"
                                >Agency*</label
                            >
                            <input
                                id="agent_agency"
                                v-model="form.agency_name"
                                type="text"
                                list="agent-agency-options"
                                autocomplete="off"
                                placeholder="Search by Agency Name"
                                :class="inputClass"
                                @change="onAgencyChosen"
                            />
                            <datalist id="agent-agency-options">
                                <option
                                    v-for="agency in agencies"
                                    :key="agency.id"
                                    :value="agency.name"
                                />
                            </datalist>
                            <p
                                v-if="form.errors.agency_name"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.agency_name }}
                            </p>
                            <p
                                v-else-if="
                                    form.agency_name.trim() && !matchedAgency
                                "
                                class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                A new agency will be created.
                            </p>
                        </div>
                        <div>
                            <label for="agent_rate_code" :class="labelClass"
                                >Rate Code</label
                            >
                            <select
                                id="agent_rate_code"
                                v-model="form.agent_rate_code_id"
                                :class="inputClass"
                            >
                                <option value="">--Select--</option>
                                <option
                                    v-for="rateCode in rateCodeOptions"
                                    :key="rateCode.id"
                                    :value="rateCode.id"
                                >
                                    {{ rateCode.name }}
                                </option>
                            </select>
                            <p
                                v-if="form.errors.agent_rate_code_id"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.agent_rate_code_id }}
                            </p>
                            <p
                                v-else-if="rateCodes.length === 0"
                                class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                Add rate codes under
                                <Link
                                    :href="
                                        admin.agentSettings.edit(property.id)
                                            .url
                                    "
                                    class="text-[#0284c7] hover:underline"
                                    >Settings → Agent settings</Link
                                >.
                            </p>
                        </div>
                    </div>

                    <div>
                        <label for="agent_contact_name" :class="labelClass"
                            >Contact Name*</label
                        >
                        <input
                            id="agent_contact_name"
                            v-model="form.contact_name"
                            type="text"
                            :class="inputClass"
                        />
                        <p
                            v-if="form.errors.contact_name"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ form.errors.contact_name }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="agent_phone" :class="labelClass"
                                >Phone</label
                            >
                            <div class="flex gap-2">
                                <PhoneCountryCodeSelect
                                    v-model="form.phone_country_code"
                                    :countries="countries"
                                />
                                <input
                                    id="agent_phone"
                                    v-model="form.phone"
                                    type="tel"
                                    inputmode="numeric"
                                    placeholder="81234 56789"
                                    :class="inputClass"
                                />
                            </div>
                            <p
                                v-if="form.errors.phone"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.phone }}
                            </p>
                        </div>
                        <div class="flex flex-col gap-2">
                            <div
                                v-for="(email, index) in form.emails"
                                :key="index"
                            >
                                <label
                                    v-if="index === 0"
                                    :for="`agent_email_${index}`"
                                    :class="labelClass"
                                    >E-mail</label
                                >
                                <div class="flex items-center gap-2">
                                    <input
                                        :id="`agent_email_${index}`"
                                        v-model="form.emails[index]"
                                        type="email"
                                        :class="inputClass"
                                    />
                                    <button
                                        v-if="index === 0"
                                        type="button"
                                        class="text-lg leading-none text-[#0284c7]"
                                        title="Add another e-mail"
                                        @click="form.emails.push('')"
                                    >
                                        ⊕
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        class="text-sm text-[#706f6c] hover:text-red-600"
                                        :aria-label="`Remove ${email || 'e-mail'}`"
                                        @click="form.emails.splice(index, 1)"
                                    >
                                        ✕
                                    </button>
                                </div>
                                <p
                                    v-if="emailError(index)"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ emailError(index) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="agent_address" :class="labelClass"
                                >Address</label
                            >
                            <input
                                id="agent_address"
                                v-model="form.address"
                                type="text"
                                :class="inputClass"
                            />
                        </div>
                        <div>
                            <label for="agent_city" :class="labelClass"
                                >City</label
                            >
                            <input
                                id="agent_city"
                                v-model="form.city"
                                type="text"
                                :class="inputClass"
                            />
                        </div>
                    </div>

                    <div
                        class="border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center justify-between py-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                            @click="historyOpen = !historyOpen"
                        >
                            Agent History
                            <span
                                class="text-[#0284c7] transition-transform"
                                :class="{ 'rotate-180': historyOpen }"
                                >⌄</span
                            >
                        </button>
                        <div
                            v-if="historyOpen"
                            class="pb-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <template v-if="editing">
                                <p>Agency code: {{ editing.agency_code }}</p>
                                <p>
                                    Added:
                                    {{
                                        propertyTime.dateTime(
                                            editing.created_at,
                                        )
                                    }}
                                    by {{ editing.created_by ?? 'System' }}
                                </p>
                                <p>
                                    Last modified:
                                    {{
                                        propertyTime.dateTime(
                                            editing.updated_at,
                                        )
                                    }}
                                    by {{ editing.updated_by ?? 'System' }}
                                </p>
                            </template>
                            <p v-else>No history yet — this agent is new.</p>
                        </div>
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
                        @click="closeForm"
                    >
                        Cancel
                    </button>
                    <button
                        v-if="editing && can('database.agents.delete')"
                        type="button"
                        class="ml-auto text-sm font-medium text-red-600 hover:underline"
                        @click="destroyAgent"
                    >
                        Delete agent
                    </button>
                </div>
            </form>
        </div>
    </template>
</template>
