<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineOptions({ layout: AdminLayout });

type Connection = {
    id: number;
    channex_property_id: string;
    is_active: boolean;
    sync_days: number;
    has_own_key: boolean;
    webhook_registered: boolean;
    last_pushed_at: string | null;
    last_pulled_at: string | null;
    last_error: string | null;
};

type Category = {
    id: number;
    name: string;
    max_adults: number;
    meal_plans: string[];
};

type Revision = {
    id: number;
    status: string;
    ota_name: string | null;
    ota_reservation_code: string | null;
    arrival: string | null;
    departure: string | null;
    amount: string | number | null;
    currency: string | null;
    booking: { id: number; booking_ref_id: string } | null;
    processed_at: string | null;
    error: string | null;
    received_at: string | null;
};

const props = defineProps<{
    property: { id: number; name: string };
    apiKeyConfigured: boolean;
    connection: Connection | null;
    remote: {
        properties: { id: string; title: string; currency: string | null }[];
        room_types: {
            id: string;
            title: string;
            count_of_rooms: number;
            occ_adults: number;
        }[];
        rate_plans: {
            id: string;
            title: string;
            room_type_id: string | null;
            currency: string | null;
        }[];
    };
    remoteError: string | null;
    categories: Category[];
    roomMappings: { room_category_id: number; channex_room_type_id: string }[];
    rateMappings: {
        room_category_id: number;
        meal_plan: string;
        adults: number;
        channex_rate_plan_id: string;
    }[];
    revisions: Revision[];
    webhookUrlIsPublic: boolean;
}>();

const propertyTime = usePropertyTime();

const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]';
const buttonClass =
    'rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm font-medium text-[#1b1b18] hover:bg-gray-50 disabled:opacity-50 dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1b1b18] cursor-pointer';
const primaryClass =
    'rounded-md bg-[#1b1b18] px-4 py-1.5 text-sm font-semibold text-white hover:bg-black disabled:opacity-50 dark:bg-[#eeeeec] dark:text-[#1C1C1A] cursor-pointer';

// ── Connect ──
const connectForm = useForm({
    mode: props.remote.properties.length ? 'existing' : 'create',
    channex_property_id: props.remote.properties[0]?.id ?? '',
    api_key: '',
});

function connect(): void {
    connectForm.post(admin.channelManager.connect(props.property.id).url, {
        preserveScroll: true,
    });
}

// ── Settings ──
const settingsForm = useForm({
    is_active: props.connection?.is_active ?? true,
    sync_days: props.connection?.sync_days ?? 365,
    api_key: '',
});

function saveSettings(): void {
    settingsForm.put(admin.channelManager.update(props.property.id).url, {
        preserveScroll: true,
        onSuccess: () => settingsForm.reset('api_key'),
    });
}

// ── Mappings ──
const rateRows = computed(() =>
    props.categories.flatMap((category) =>
        category.meal_plans.map((mealPlan) => ({ category, mealPlan })),
    ),
);

function rateKey(categoryId: number, mealPlan: string): string {
    return `${categoryId}:${mealPlan}`;
}

const mappingForm = useForm({
    rooms: Object.fromEntries(
        props.categories.map((category) => [
            category.id,
            props.roomMappings.find(
                (row) => row.room_category_id === category.id,
            )?.channex_room_type_id ?? '',
        ]),
    ) as Record<number, string>,
    rates: Object.fromEntries(
        rateRows.value.map(({ category, mealPlan }) => {
            const mapped = props.rateMappings.find(
                (row) =>
                    row.room_category_id === category.id &&
                    row.meal_plan === mealPlan,
            );

            return [
                rateKey(category.id, mealPlan),
                {
                    plan: mapped?.channex_rate_plan_id ?? '',
                    adults: mapped?.adults ?? Math.min(2, category.max_adults),
                },
            ];
        }),
    ) as Record<string, { plan: string; adults: number }>,
});

/** Rate plans of the category's mapped room type first; every plan stays choosable. */
function ratePlansFor(categoryId: number) {
    const roomTypeId = mappingForm.rooms[categoryId];

    return [...props.remote.rate_plans].sort(
        (a, b) =>
            Number(b.room_type_id === roomTypeId) -
            Number(a.room_type_id === roomTypeId),
    );
}

const occupancyLabels = ['Single', 'Double', 'Triple', 'Quad'];

function saveMappings(): void {
    mappingForm
        .transform((data) => ({
            rooms: props.categories.map((category) => ({
                room_category_id: category.id,
                channex_room_type_id: data.rooms[category.id] || null,
            })),
            rates: rateRows.value.map(({ category, mealPlan }) => {
                const row = data.rates[rateKey(category.id, mealPlan)];

                return {
                    room_category_id: category.id,
                    meal_plan: mealPlan,
                    adults: Number(row.adults),
                    channex_rate_plan_id: row.plan || null,
                };
            }),
        }))
        .put(admin.channelManager.mappings(props.property.id).url, {
            preserveScroll: true,
        });
}

const working = ref<string | null>(null);

function run(action: 'createRoomsAndRates' | 'sync' | 'pull'): void {
    working.value = action;
    router.post(
        admin.channelManager[action](props.property.id).url,
        {},
        { preserveScroll: true, onFinish: () => (working.value = null) },
    );
}

const disconnectOpen = ref(false);

function disconnect(): void {
    router.delete(admin.channelManager.destroy(props.property.id).url, {
        preserveScroll: true,
        onFinish: () => (disconnectOpen.value = false),
    });
}

const statusClass: Record<string, string> = {
    new: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    modified: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    cancelled: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
};
</script>

<template>
    <Head :title="`Channel Manager: ${property.name}`" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1
                class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Channel Manager
            </h1>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Sell {{ property.name }} on Booking.com, Airbnb, Expedia and
                other OTAs through Channex: availability and rates go out, OTA
                bookings come in.
            </p>
        </div>
        <span
            v-if="connection"
            class="rounded-full px-3 py-1 text-xs font-semibold"
            :class="
                connection.is_active
                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                    : 'bg-gray-100 text-gray-600 dark:bg-[#262625] dark:text-gray-400'
            "
        >
            {{
                connection.is_active
                    ? 'Connected · syncing'
                    : 'Connected · paused'
            }}
        </span>
    </div>

    <p
        v-if="remoteError"
        class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
    >
        {{ remoteError }}
    </p>

    <!-- Not connected -->
    <section
        v-if="!connection"
        class="max-w-2xl rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
    >
        <h2
            class="mb-3 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Connect to Channex
        </h2>
        <form class="flex flex-col gap-3 text-sm" @submit.prevent="connect">
            <label class="flex items-center gap-2">
                <input
                    v-model="connectForm.mode"
                    type="radio"
                    value="existing"
                    :disabled="!remote.properties.length"
                />
                Use an existing Channex property
            </label>
            <select
                v-if="connectForm.mode === 'existing'"
                v-model="connectForm.channex_property_id"
                :class="inputClass"
                class="ml-6"
            >
                <option
                    v-for="remoteProperty in remote.properties"
                    :key="remoteProperty.id"
                    :value="remoteProperty.id"
                >
                    {{ remoteProperty.title
                    }}{{
                        remoteProperty.currency
                            ? ` (${remoteProperty.currency})`
                            : ''
                    }}
                </option>
            </select>
            <label class="flex items-center gap-2">
                <input v-model="connectForm.mode" type="radio" value="create" />
                Create a new Channex property from {{ property.name }}'s details
            </label>
            <label
                class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                Channex API key
                {{
                    apiKeyConfigured
                        ? '(optional — the account-wide key is used when blank)'
                        : ''
                }}
                <input
                    v-model="connectForm.api_key"
                    type="password"
                    autocomplete="off"
                    :required="!apiKeyConfigured"
                    :class="inputClass"
                />
            </label>
            <p
                v-for="(message, key) in connectForm.errors"
                :key="key"
                class="text-xs text-[#f53003]"
            >
                {{ message }}
            </p>
            <div>
                <button
                    type="submit"
                    :disabled="connectForm.processing"
                    :class="primaryClass"
                >
                    {{ connectForm.processing ? 'Connecting…' : 'Connect' }}
                </button>
            </div>
        </form>
    </section>

    <template v-else>
        <!-- Status & actions -->
        <section
            class="mb-6 rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
        >
            <div class="mb-4 grid gap-2 text-sm sm:grid-cols-2">
                <p>
                    <span class="text-[#706f6c] dark:text-[#A1A09A]"
                        >Channex property:</span
                    >
                    <span class="font-mono text-xs">{{
                        connection.channex_property_id
                    }}</span>
                </p>
                <p>
                    <span class="text-[#706f6c] dark:text-[#A1A09A]"
                        >Last availability/rate push:</span
                    >
                    {{
                        connection.last_pushed_at
                            ? propertyTime.dateTime(connection.last_pushed_at)
                            : 'Never'
                    }}
                </p>
                <p>
                    <span class="text-[#706f6c] dark:text-[#A1A09A]"
                        >Last booking pull:</span
                    >
                    {{
                        connection.last_pulled_at
                            ? propertyTime.dateTime(connection.last_pulled_at)
                            : 'Never'
                    }}
                </p>
                <p>
                    <span class="text-[#706f6c] dark:text-[#A1A09A]"
                        >Instant bookings (webhook):</span
                    >
                    {{
                        connection.webhook_registered
                            ? 'On'
                            : webhookUrlIsPublic
                              ? 'Not registered'
                              : 'Off on this local site — bookings are pulled every minute'
                    }}
                </p>
            </div>
            <p
                v-if="connection.last_error"
                class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300"
            >
                Last error: {{ connection.last_error }}
            </p>
            <div class="flex flex-wrap gap-2">
                <a
                    :href="admin.channelManager.channels(property.id).url"
                    target="_blank"
                    rel="noopener"
                    :class="primaryClass"
                    >Connect OTAs in Channex ↗</a
                >
                <button
                    type="button"
                    :class="buttonClass"
                    :disabled="working !== null"
                    @click="run('sync')"
                >
                    {{ working === 'sync' ? 'Queuing…' : 'Full sync now' }}
                </button>
                <button
                    type="button"
                    :class="buttonClass"
                    :disabled="working !== null"
                    @click="run('pull')"
                >
                    {{
                        working === 'pull' ? 'Fetching…' : 'Fetch bookings now'
                    }}
                </button>
                <button
                    type="button"
                    class="ml-auto cursor-pointer rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950"
                    @click="disconnectOpen = true"
                >
                    Disconnect
                </button>
            </div>
        </section>

        <!-- Settings -->
        <section
            class="mb-6 max-w-2xl rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
        >
            <h2
                class="mb-3 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Settings
            </h2>
            <form
                class="grid gap-3 text-sm sm:grid-cols-2"
                @submit.prevent="saveSettings"
            >
                <label class="flex items-center gap-2 sm:col-span-2">
                    <input v-model="settingsForm.is_active" type="checkbox" />
                    Sync with Channex (off pauses pushes and booking pulls)
                </label>
                <label
                    class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Days ahead to sync
                    <input
                        v-model.number="settingsForm.sync_days"
                        type="number"
                        min="30"
                        max="730"
                        :class="inputClass"
                    />
                </label>
                <label
                    class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    {{
                        connection.has_own_key
                            ? "Replace this property's API key"
                            : 'Own API key (optional)'
                    }}
                    <input
                        v-model="settingsForm.api_key"
                        type="password"
                        autocomplete="off"
                        placeholder="Leave blank to keep"
                        :class="inputClass"
                    />
                </label>
                <p
                    v-for="(message, key) in settingsForm.errors"
                    :key="key"
                    class="text-xs text-[#f53003] sm:col-span-2"
                >
                    {{ message }}
                </p>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="settingsForm.processing"
                        :class="primaryClass"
                    >
                        Save settings
                    </button>
                </div>
            </form>
        </section>

        <!-- Mappings -->
        <section
            class="mb-6 rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
        >
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2
                    class="text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Room and rate mapping
                </h2>
                <button
                    type="button"
                    :class="buttonClass"
                    :disabled="working !== null"
                    @click="run('createRoomsAndRates')"
                >
                    {{
                        working === 'createRoomsAndRates'
                            ? 'Creating…'
                            : 'Create missing room types & rate plans in Channex'
                    }}
                </button>
            </div>
            <p class="mb-4 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                Each room category's free rooms are sent to its Channex room
                type. Each rate row sends the OTA rates from Rate Setup (Direct
                when the OTA rate is empty) for the chosen occupancy. OTA
                bookings land in the mapped category with the mapped meal plan.
            </p>

            <form @submit.prevent="saveMappings">
                <h3
                    class="mb-2 text-xs font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Room categories
                </h3>
                <div class="mb-5 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="text-left text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                <th class="py-1.5 pr-3 font-medium">
                                    Room category
                                </th>
                                <th class="py-1.5 font-medium">
                                    Channex room type
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="category in categories"
                                :key="category.id"
                                class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                            >
                                <td class="py-2 pr-3">{{ category.name }}</td>
                                <td class="py-2">
                                    <select
                                        v-model="mappingForm.rooms[category.id]"
                                        :class="inputClass"
                                        class="w-full max-w-sm"
                                    >
                                        <option value="">
                                            Not sold on OTAs
                                        </option>
                                        <option
                                            v-for="roomType in remote.room_types"
                                            :key="roomType.id"
                                            :value="roomType.id"
                                        >
                                            {{ roomType.title }} ({{
                                                roomType.count_of_rooms
                                            }}
                                            rooms,
                                            {{ roomType.occ_adults }} adults)
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h3
                    class="mb-2 text-xs font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Rates
                </h3>
                <div class="mb-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="text-left text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                <th class="py-1.5 pr-3 font-medium">
                                    Room category · meal plan
                                </th>
                                <th class="py-1.5 pr-3 font-medium">
                                    Rate for
                                </th>
                                <th class="py-1.5 font-medium">
                                    Channex rate plan
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="{ category, mealPlan } in rateRows"
                                :key="rateKey(category.id, mealPlan)"
                                class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                            >
                                <td class="py-2 pr-3">
                                    {{ category.name }} · {{ mealPlan }}
                                </td>
                                <td class="py-2 pr-3">
                                    <select
                                        v-model.number="
                                            mappingForm.rates[
                                                rateKey(category.id, mealPlan)
                                            ].adults
                                        "
                                        :class="inputClass"
                                    >
                                        <option
                                            v-for="adults in Math.min(
                                                4,
                                                category.max_adults,
                                            )"
                                            :key="adults"
                                            :value="adults"
                                        >
                                            {{ occupancyLabels[adults - 1] }}
                                        </option>
                                    </select>
                                </td>
                                <td class="py-2">
                                    <select
                                        v-model="
                                            mappingForm.rates[
                                                rateKey(category.id, mealPlan)
                                            ].plan
                                        "
                                        :class="inputClass"
                                        class="w-full max-w-sm"
                                    >
                                        <option value="">
                                            Not sold on OTAs
                                        </option>
                                        <option
                                            v-for="plan in ratePlansFor(
                                                category.id,
                                            )"
                                            :key="plan.id"
                                            :value="plan.id"
                                        >
                                            {{ plan.title
                                            }}{{
                                                plan.currency
                                                    ? ` (${plan.currency})`
                                                    : ''
                                            }}
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-for="(message, key) in mappingForm.errors"
                    :key="key"
                    class="text-xs text-[#f53003]"
                >
                    {{ message }}
                </p>
                <button
                    type="submit"
                    :disabled="mappingForm.processing"
                    :class="primaryClass"
                >
                    {{
                        mappingForm.processing
                            ? 'Saving…'
                            : 'Save mapping & sync'
                    }}
                </button>
            </form>
        </section>

        <!-- Booking log -->
        <section
            class="rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
        >
            <h2
                class="mb-3 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                OTA bookings received
            </h2>
            <p
                v-if="!revisions.length"
                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
            >
                None yet.
            </p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="text-left text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <th class="py-1.5 pr-3 font-medium">Received</th>
                            <th class="py-1.5 pr-3 font-medium">OTA</th>
                            <th class="py-1.5 pr-3 font-medium">Change</th>
                            <th class="py-1.5 pr-3 font-medium">Stay</th>
                            <th class="py-1.5 pr-3 text-right font-medium">
                                Amount
                            </th>
                            <th class="py-1.5 font-medium">Booking</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="revision in revisions"
                            :key="revision.id"
                            class="border-t border-[#e3e3e0] align-top dark:border-[#3E3E3A]"
                        >
                            <td class="py-2 pr-3 whitespace-nowrap">
                                {{
                                    propertyTime.dateTime(revision.received_at)
                                }}
                            </td>
                            <td class="py-2 pr-3">
                                {{ revision.ota_name ?? '—'
                                }}<span
                                    class="block font-mono text-xs text-[#706f6c]"
                                    >{{ revision.ota_reservation_code }}</span
                                >
                            </td>
                            <td class="py-2 pr-3">
                                <span
                                    class="rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase"
                                    :class="
                                        statusClass[revision.status] ??
                                        'bg-gray-100 text-gray-600'
                                    "
                                    >{{ revision.status }}</span
                                >
                            </td>
                            <td class="py-2 pr-3 whitespace-nowrap">
                                {{ revision.arrival }} →
                                {{ revision.departure }}
                            </td>
                            <td class="py-2 pr-3 text-right whitespace-nowrap">
                                {{ revision.amount }} {{ revision.currency }}
                            </td>
                            <td class="py-2">
                                <Link
                                    v-if="revision.booking"
                                    :href="
                                        admin.bookings.show({
                                            property: property.id,
                                            booking: revision.booking.id,
                                        }).url
                                    "
                                    class="text-sky-700 hover:underline"
                                >
                                    {{ revision.booking.booking_ref_id }}
                                </Link>
                                <span
                                    v-else-if="!revision.error"
                                    class="text-[#706f6c]"
                                    >—</span
                                >
                                <span
                                    v-if="revision.error"
                                    class="block text-xs text-[#f53003]"
                                    >Not applied: {{ revision.error }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Modal
            :show="disconnectOpen"
            title="Disconnect from Channex?"
            @close="disconnectOpen = false"
        >
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Availability and rates stop going to Channex, and OTA bookings
                stop coming in. The mapping is removed; OTA bookings already
                received stay. The Channex property itself is not deleted.
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <button
                    type="button"
                    :class="buttonClass"
                    @click="disconnectOpen = false"
                >
                    Keep connected
                </button>
                <button
                    type="button"
                    class="cursor-pointer rounded-md bg-red-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-red-700"
                    @click="disconnect"
                >
                    Disconnect
                </button>
            </div>
        </Modal>
    </template>
</template>
