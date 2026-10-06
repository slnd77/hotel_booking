<script setup lang="ts">
import PhoneCountryCodeSelect, {
    type PhoneCountryOption,
} from '@/components/PhoneCountryCodeSelect.vue';
import admin from '@/routes/admin';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';

export type AgentOption = {
    id: number;
    contact_name: string;
    agency_name: string | null;
    agency_code: string | null;
    email: string | null;
    phone: string | null;
};

const props = defineProps<{
    property: { id: number; country_id?: number | null };
    modelValue: AgentOption | null;
    agencies: { id: number; name: string; agent_rate_code_id: number | null }[];
    rateCodes: { id: number; name: string }[];
    countries: PhoneCountryOption[];
    error?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [agent: AgentOption | null];
}>();

const root = ref<HTMLElement | null>(null);
// The Teleport to <body> mounts in the browser only, so SSR and hydration render the same placeholder.
const isClientMounted = ref(false);
onMounted(() => (isClientMounted.value = true));
const open = ref(false);
const query = ref('');
const loading = ref(false);
const results = ref<AgentOption[]>([]);
let requestId = 0;
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

function label(agent: AgentOption): string {
    return [agent.contact_name, agent.agency_name].filter(Boolean).join(' — ');
}

async function search(term: string): Promise<void> {
    const currentRequestId = ++requestId;
    loading.value = true;

    try {
        const response = await fetch(
            admin.databases.agents.search(props.property.id, {
                query: { q: term },
            }).url,
            {
                headers: { Accept: 'application/json' },
            },
        );
        const data = (await response.json()) as { agents: AgentOption[] };

        if (currentRequestId === requestId) {
            results.value = data.agents;
        }
    } finally {
        if (currentRequestId === requestId) {
            loading.value = false;
        }
    }
}

watch(query, (value) => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    debounceTimer = setTimeout(() => search(value.trim()), 250);
});

function openList(): void {
    open.value = true;

    if (results.value.length === 0) {
        search(query.value.trim());
    }
}

function choose(agent: AgentOption): void {
    emit('update:modelValue', agent);
    query.value = '';
    open.value = false;
}

function clear(): void {
    emit('update:modelValue', null);
    query.value = '';
}

function onDocumentClick(event: MouseEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('mousedown', onDocumentClick));
onBeforeUnmount(() =>
    document.removeEventListener('mousedown', onDocumentClick),
);

/* Add a new agent without leaving the booking */

const adding = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string>>({});

const defaultDialCode = computed(() => {
    const country = props.countries.find(
        (item) => item.id === props.property.country_id,
    );

    return country ? `+${country.phonecode}` : '+91';
});

const newAgent = reactive({
    agency_name: '',
    agent_rate_code_id: '' as number | '',
    contact_name: '',
    phone_country_code: '+91',
    phone: '',
    email: '',
});

/** What was typed goes where it fits: digits into Phone, anything else into Contact Name. */
function startAdding(): void {
    const typed = query.value.trim();
    const isPhone = /^[0-9 +]+$/.test(typed);

    Object.assign(newAgent, {
        agency_name: '',
        agent_rate_code_id: '',
        contact_name: isPhone ? '' : typed,
        phone_country_code: defaultDialCode.value,
        phone: isPhone ? typed.replace(/[^0-9]/g, '') : '',
        email: '',
    });
    errors.value = {};
    open.value = false;
    adding.value = true;
}

function onAgencyChosen(): void {
    const agency = props.agencies.find(
        (item) =>
            item.name.toLowerCase() ===
            newAgent.agency_name.trim().toLowerCase(),
    );

    if (agency) {
        newAgent.agent_rate_code_id = agency.agent_rate_code_id ?? '';
    }
}

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function saveNewAgent(): Promise<void> {
    saving.value = true;
    errors.value = {};

    try {
        const response = await fetch(
            admin.databases.agents.store(props.property.id).url,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    source: 'agent',
                    agency_name: newAgent.agency_name,
                    agent_rate_code_id:
                        newAgent.agent_rate_code_id === ''
                            ? null
                            : newAgent.agent_rate_code_id,
                    contact_name: newAgent.contact_name,
                    phone_country_code: newAgent.phone_country_code,
                    phone: newAgent.phone,
                    emails: [newAgent.email],
                }),
            },
        );

        if (response.status === 422) {
            const data = (await response.json()) as {
                errors: Record<string, string[]>;
            };
            errors.value = Object.fromEntries(
                Object.entries(data.errors).map(([key, messages]) => [
                    key === 'emails.0' ? 'email' : key,
                    messages[0],
                ]),
            );

            return;
        }

        if (!response.ok) {
            errors.value = {
                contact_name: 'The agent could not be saved. Please try again.',
            };

            return;
        }

        const data = (await response.json()) as { agent: AgentOption };
        results.value = [];
        choose(data.agent);
        adding.value = false;
    } finally {
        saving.value = false;
    }
}

const inputClass =
    'block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]';
const labelClass =
    'mb-1 block text-xs font-medium text-[#1b1b18] dark:text-[#EDEDEC]';
</script>

<template>
    <div ref="root" class="relative">
        <div v-if="modelValue && !open" class="flex items-center gap-2">
            <button
                type="button"
                :class="[inputClass, 'truncate text-left']"
                :title="label(modelValue)"
                @click="openList"
            >
                {{ label(modelValue) }}
            </button>
            <button
                type="button"
                class="px-1 text-sm text-[#706f6c] hover:text-red-600"
                aria-label="Clear agent"
                @click="clear"
            >
                ✕
            </button>
        </div>
        <input
            v-else
            v-model="query"
            type="text"
            autocomplete="off"
            placeholder="Search by agency, contact, phone or e-mail"
            :class="inputClass"
            @focus="openList"
        />
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>

        <div
            v-if="open"
            class="absolute top-full left-0 z-30 mt-1 max-h-72 w-[32rem] max-w-[90vw] overflow-y-auto rounded-md border border-[#e3e3e0] bg-white shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <button
                type="button"
                class="block w-full px-3 py-2 text-left text-sm text-[#0284c7] hover:bg-[#f5f5f4] dark:hover:bg-[#1D1D1B]"
                @click="startAdding"
            >
                + Add {{ query.trim() ? `${query.trim()}…` : 'new agent' }}
            </button>
            <table class="w-full text-left text-xs">
                <thead
                    class="bg-[#f5f5f4] text-[#706f6c] dark:bg-[#1D1D1B] dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="px-3 py-1.5 font-semibold">Code</th>
                        <th class="px-3 py-1.5 font-semibold">Agency</th>
                        <th class="px-3 py-1.5 font-semibold">Contact</th>
                        <th class="px-3 py-1.5 font-semibold">Email</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="agent in results"
                        :key="agent.id"
                        class="cursor-pointer text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        @click="choose(agent)"
                    >
                        <td class="px-3 py-1.5">{{ agent.agency_code }}</td>
                        <td class="px-3 py-1.5">{{ agent.agency_name }}</td>
                        <td class="px-3 py-1.5">
                            {{ agent.contact_name
                            }}<span
                                v-if="agent.phone"
                                class="block text-[#706f6c]"
                                >{{ agent.phone }}</span
                            >
                        </td>
                        <td class="px-3 py-1.5">{{ agent.email }}</td>
                    </tr>
                    <tr v-if="!loading && results.length === 0">
                        <td
                            colspan="4"
                            class="px-3 py-3 text-center text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            No agents found.
                        </td>
                    </tr>
                    <tr v-if="loading">
                        <td
                            colspan="4"
                            class="px-3 py-3 text-center text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Searching…
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Teleported so its form isn't nested inside the booking form. -->
        <Teleport v-if="isClientMounted" to="body">
            <div
                v-if="adding"
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/30 p-4 pt-16"
                @click.self="adding = false"
            >
                <form
                    class="w-full max-w-2xl rounded-lg border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
                    @submit.prevent="saveNewAgent"
                >
                    <div
                        class="flex items-center justify-between bg-[#1b1b18] px-6 py-3"
                    >
                        <h2
                            class="font-display text-base font-semibold text-white"
                        >
                            Add Agent
                        </h2>
                        <button
                            type="button"
                            class="text-white/80 hover:text-white"
                            aria-label="Close"
                            @click="adding = false"
                        >
                            ✕
                        </button>
                    </div>

                    <div class="flex flex-col gap-4 px-6 py-5">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label
                                    for="new_agent_agency"
                                    :class="labelClass"
                                    >Agency*</label
                                >
                                <input
                                    id="new_agent_agency"
                                    v-model="newAgent.agency_name"
                                    type="text"
                                    list="new-agent-agency-options"
                                    autocomplete="off"
                                    placeholder="Search by Agency Name"
                                    :class="inputClass"
                                    @change="onAgencyChosen"
                                />
                                <datalist id="new-agent-agency-options">
                                    <option
                                        v-for="agency in agencies"
                                        :key="agency.id"
                                        :value="agency.name"
                                    />
                                </datalist>
                                <p
                                    v-if="errors.agency_name"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ errors.agency_name }}
                                </p>
                            </div>
                            <div>
                                <label
                                    for="new_agent_rate_code"
                                    :class="labelClass"
                                    >Rate Code</label
                                >
                                <select
                                    id="new_agent_rate_code"
                                    v-model="newAgent.agent_rate_code_id"
                                    :class="inputClass"
                                >
                                    <option value="">--Select--</option>
                                    <option
                                        v-for="rateCode in rateCodes"
                                        :key="rateCode.id"
                                        :value="rateCode.id"
                                    >
                                        {{ rateCode.name }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="new_agent_contact" :class="labelClass"
                                >Contact Name*</label
                            >
                            <input
                                id="new_agent_contact"
                                v-model="newAgent.contact_name"
                                type="text"
                                :class="inputClass"
                            />
                            <p
                                v-if="errors.contact_name"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ errors.contact_name }}
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="new_agent_phone" :class="labelClass"
                                    >Phone</label
                                >
                                <div class="flex gap-2">
                                    <PhoneCountryCodeSelect
                                        v-model="newAgent.phone_country_code"
                                        :countries="countries"
                                    />
                                    <input
                                        id="new_agent_phone"
                                        v-model="newAgent.phone"
                                        type="tel"
                                        inputmode="numeric"
                                        :class="inputClass"
                                    />
                                </div>
                                <p
                                    v-if="errors.phone"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ errors.phone }}
                                </p>
                            </div>
                            <div>
                                <label for="new_agent_email" :class="labelClass"
                                    >E-mail</label
                                >
                                <input
                                    id="new_agent_email"
                                    v-model="newAgent.email"
                                    type="email"
                                    :class="inputClass"
                                />
                                <p
                                    v-if="errors.email"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ errors.email }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-4 px-6 pb-5">
                        <button
                            type="submit"
                            :disabled="saving"
                            class="rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-semibold text-white hover:bg-[#d92a02] disabled:opacity-60"
                        >
                            {{ saving ? 'Saving…' : 'SAVE' }}
                        </button>
                        <button
                            type="button"
                            class="text-sm font-medium text-[#0284c7] hover:underline"
                            @click="adding = false"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </div>
</template>
