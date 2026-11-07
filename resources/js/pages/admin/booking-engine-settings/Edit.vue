<script setup lang="ts">
import BookingEngineController from '@/actions/App/Http/Controllers/BookingEngineController';
import Modal from '@/components/Modal.vue';
import QuillEditor from '@/components/QuillEditor.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import type { MetaField, MetaFieldValue, RoomCategory } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    property: { id: number; name: string; ulid: string };
    metaFields: MetaField[];
    metaFieldValues: MetaFieldValue[];
    roomCategories: Pick<RoomCategory, 'id' | 'name' | 'display_order'>[];
    configuredPaymentGateways?: {
        id: string;
        name: string;
        description?: string;
        is_active: boolean;
    }[];
}>();

function fieldFor(key: string) {
    return props.metaFields.find((field) => field.key === key);
}

const metaFieldValues: Record<number, { enabled: boolean; data: string }> = {};
props.metaFields.forEach((field) => {
    metaFieldValues[field.id] = { enabled: false, data: '' };
});
props.metaFieldValues.forEach((value) => {
    metaFieldValues[value.meta_fields_key_id] = {
        enabled: value.enabled,
        data: value.data ?? '',
    };
});

const form = useForm({
    meta_fields: metaFieldValues,
});

function rawDataFor(key: string): string {
    const field = fieldFor(key);

    return field ? (form.meta_fields[field.id]?.data ?? '') : '';
}

function parseOrDefault<T>(key: string, defaults: T): T {
    const raw = rawDataFor(key);

    if (!raw) {
        return defaults;
    }

    try {
        return { ...defaults, ...(JSON.parse(raw) as Partial<T>) };
    } catch {
        return defaults;
    }
}

function bindSettings<T>(key: string, state: { value: T }): void {
    const field = fieldFor(key);

    if (field) {
        form.meta_fields[field.id].enabled = true;
    }

    watch(
        state,
        (value) => {
            if (field) {
                form.meta_fields[field.id].data = JSON.stringify(value);
            }
        },
        { deep: true, immediate: true },
    );
}

function submit(): void {
    form.put(admin.bookingEngineSettings.update(props.property).url, {
        preserveScroll: true,
        preserveState: true,
    });
}

const activityLogsOpen = ref(false);

type PaymentGatewaySetting = {
    id: string;
    name: string;
    description?: string;
    enabled: boolean;
    key?: string;
    secret?: string;
    is_active?: boolean;
    mode?: 'sandbox' | 'production';
    commission_percent?: number | null;
    commission_fixed?: number | null;
    commission_tax_percent?: number | null;
};

function isGatewayConfigured(gateway: PaymentGatewaySetting): boolean {
    return Boolean(
        gateway.key &&
        gateway.key.trim() !== '' &&
        gateway.secret &&
        gateway.secret.trim() !== '',
    );
}

const gatewayModalOpen = ref(false);
const editingGateway = ref<PaymentGatewaySetting | null>(null);
const modalForm = ref({
    key: '',
    secret: '',
    is_active: true,
    mode: 'sandbox' as 'sandbox' | 'production',
    commission_percent: null as number | null,
    commission_fixed: null as number | null,
    commission_tax_percent: null as number | null,
});
const showSecret = ref(false);

function openGatewaySettings(gateway: PaymentGatewaySetting): void {
    editingGateway.value = gateway;
    modalForm.value = {
        key: gateway.key ?? '',
        secret: gateway.secret ?? '',
        is_active: gateway.is_active ?? true,
        mode: (gateway.mode ?? 'sandbox') as 'sandbox' | 'production',
        commission_percent: gateway.commission_percent ?? null,
        commission_fixed: gateway.commission_fixed ?? null,
        commission_tax_percent: gateway.commission_tax_percent ?? null,
    };
    showSecret.value = false;
    gatewayModalOpen.value = true;
}

function saveGatewayModal(): void {
    if (editingGateway.value) {
        editingGateway.value.key = modalForm.value.key.trim();
        editingGateway.value.secret = modalForm.value.secret.trim();
        editingGateway.value.is_active = modalForm.value.is_active;
        editingGateway.value.mode = modalForm.value.mode;
        editingGateway.value.commission_percent =
            modalForm.value.commission_percent;
        editingGateway.value.commission_fixed =
            modalForm.value.commission_fixed;
        editingGateway.value.commission_tax_percent =
            modalForm.value.commission_tax_percent;

        if (
            !isGatewayConfigured(editingGateway.value) ||
            editingGateway.value.is_active === false
        ) {
            editingGateway.value.enabled = false;
        }
    }
    gatewayModalOpen.value = false;
}

type BookingEngineSettings = {
    enable_booking_engine: boolean;
    enable_ota_display: boolean;
    enable_pay_at_hotel: boolean;
    pay_at_hotel_percentage: number | null;
    enable_google_ari: boolean;
    enable_trip_advisor: boolean;
    enable_terms_and_condition: boolean;
    enable_data_privacy_policy: boolean;
    enable_rates_at_calendar: boolean;
    enable_estimated_arrival_time: boolean;
    enable_special_request: boolean;
    enable_nationality: boolean;
    enable_purpose_to_visit: boolean;
    enable_marketing_consent: boolean;
    enable_deals_and_promo: boolean;
    show_agent_login: boolean;
    booking_summary_cart_expansion: boolean;
    package_details_visible_with_room: boolean;
    show_amenities: boolean;
    show_bed_types: boolean;
    hide_tax_breakup: boolean;
    max_rooms: number | null;
    default_adult_search_count: number | null;
    category_visibility: Record<number, boolean>;
    category_order: number[];
    allowed_payment_gateways: PaymentGatewaySetting[];
    allow_partial_payment: boolean;
    partial_payment_percentage: number | null;
    accepted_card_types: { id: number; name: string; enabled: boolean }[];
    rate_display_mode: string;
    credit_card_notes: string;
    custom_no_availability_message: string;
    landing_page_message: string;
    data_privacy_policy_text: string;
};

type BooleanSettingKey = {
    [K in keyof BookingEngineSettings]: BookingEngineSettings[K] extends boolean
        ? K
        : never;
}[keyof BookingEngineSettings];

function defaultAcceptedCardTypes(): {
    id: number;
    name: string;
    enabled: boolean;
}[] {
    return [
        { id: 1, name: 'Visa', enabled: true },
        { id: 2, name: 'Master', enabled: true },
        { id: 3, name: 'Maestro', enabled: true },
        { id: 4, name: 'American Express', enabled: true },
        { id: 5, name: 'Union Pay', enabled: true },
        { id: 6, name: 'Net Banking', enabled: true },
    ];
}

function defaultAllowedPaymentGateways(): PaymentGatewaySetting[] {
    const list = props.configuredPaymentGateways ?? [
        {
            id: 'razorpay',
            name: 'Razorpay',
            description: 'Credit/Debit Cards, UPI, Netbanking, Wallets',
            is_active: true,
        },
        {
            id: 'stripe',
            name: 'Stripe',
            description:
                'International & Domestic Cards, Apple Pay, Google Pay',
            is_active: true,
        },
        {
            id: 'paypal',
            name: 'PayPal',
            description: 'PayPal Express Checkout & Credit Cards',
            is_active: true,
        },
        {
            id: 'ccavenue',
            name: 'CCAvenue',
            description:
                'Multi-currency payment gateway for Indian & International guests',
            is_active: true,
        },
        {
            id: 'cashfree',
            name: 'Cashfree Payments',
            description: 'Instant UPI, Cards & Netbanking checkout',
            is_active: true,
        },
    ];

    return list.map((gw: any) => ({
        id: gw.id,
        name: gw.name,
        description: gw.description ?? '',
        enabled: Boolean(gw.key && gw.secret && (gw.enabled ?? false)),
        key: gw.key ?? '',
        secret: gw.secret ?? '',
        is_active: gw.is_active ?? true,
        mode: gw.mode ?? 'sandbox',
    }));
}

const initialSettings = parseOrDefault<BookingEngineSettings>(
    'booking_engine_settings',
    {
        enable_booking_engine: true,
        enable_ota_display: false,
        enable_pay_at_hotel: false,
        pay_at_hotel_percentage: 50,
        enable_google_ari: false,
        enable_trip_advisor: false,
        enable_terms_and_condition: false,
        enable_data_privacy_policy: false,
        enable_rates_at_calendar: true,
        enable_estimated_arrival_time: false,
        enable_special_request: true,
        enable_nationality: false,
        enable_purpose_to_visit: false,
        enable_marketing_consent: false,
        enable_deals_and_promo: true,
        show_agent_login: false,
        booking_summary_cart_expansion: false,
        package_details_visible_with_room: false,
        show_amenities: true,
        show_bed_types: true,
        hide_tax_breakup: false,
        max_rooms: 10,
        default_adult_search_count: 1,
        category_visibility: {},
        category_order: [],
        allowed_payment_gateways: defaultAllowedPaymentGateways(),
        allow_partial_payment: false,
        partial_payment_percentage: 50,
        accepted_card_types: defaultAcceptedCardTypes(),
        rate_display_mode: 'gross_rate',
        credit_card_notes: '',
        custom_no_availability_message: '',
        landing_page_message: '',
        data_privacy_policy_text: '',
    },
);

if (
    !initialSettings.allowed_payment_gateways ||
    initialSettings.allowed_payment_gateways.length === 0
) {
    initialSettings.allowed_payment_gateways = defaultAllowedPaymentGateways();
} else {
    const existingIds = new Set(
        initialSettings.allowed_payment_gateways.map((g) => g.id),
    );
    for (const d of defaultAllowedPaymentGateways()) {
        if (!existingIds.has(d.id)) {
            initialSettings.allowed_payment_gateways.push(d);
        }
    }
}

initialSettings.allowed_payment_gateways.forEach((gw) => {
    if (gw.key === undefined) gw.key = '';
    if (gw.secret === undefined) gw.secret = '';
    if (gw.is_active === undefined) gw.is_active = true;
    if (gw.enabled === undefined) gw.enabled = false;
    if (gw.mode === undefined) gw.mode = 'sandbox';
});

if (initialSettings.allow_partial_payment === undefined) {
    initialSettings.allow_partial_payment = false;
}
if (
    initialSettings.partial_payment_percentage === undefined ||
    initialSettings.partial_payment_percentage === null
) {
    initialSettings.partial_payment_percentage = 50;
}

const bookingEngineSettings = ref<BookingEngineSettings>(initialSettings);
bindSettings('booking_engine_settings', bookingEngineSettings);

props.roomCategories.forEach((category) => {
    if (!(category.id in bookingEngineSettings.value.category_visibility)) {
        bookingEngineSettings.value.category_visibility[category.id] = true;
    }

    if (!bookingEngineSettings.value.category_order.includes(category.id)) {
        bookingEngineSettings.value.category_order.push(category.id);
    }
});

const orderedCategories = computed(() => {
    const byId = new Map(
        props.roomCategories.map((category) => [category.id, category]),
    );

    return bookingEngineSettings.value.category_order
        .map((id) => byId.get(id))
        .filter(
            (category): category is (typeof props.roomCategories)[number] =>
                category !== undefined,
        );
});

const draggedCategoryId = ref<number | null>(null);

function onCategoryDragStart(id: number): void {
    draggedCategoryId.value = id;
}

function onCategoryDrop(targetId: number): void {
    const order = bookingEngineSettings.value.category_order;
    const fromIndex =
        draggedCategoryId.value === null
            ? -1
            : order.indexOf(draggedCategoryId.value);
    const toIndex = order.indexOf(targetId);

    if (fromIndex === -1 || toIndex === -1 || fromIndex === toIndex) {
        draggedCategoryId.value = null;
        return;
    }

    const [moved] = order.splice(fromIndex, 1);
    order.splice(toIndex, 0, moved);
    draggedCategoryId.value = null;
}

const draggedGatewayIndex = ref<number | null>(null);

function onGatewayDragStart(index: number): void {
    draggedGatewayIndex.value = index;
}

function onGatewayDrop(index: number): void {
    if (
        draggedGatewayIndex.value === null ||
        draggedGatewayIndex.value === index
    ) {
        draggedGatewayIndex.value = null;
        return;
    }

    const list = bookingEngineSettings.value.allowed_payment_gateways;
    const [moved] = list.splice(draggedGatewayIndex.value, 1);
    list.splice(index, 0, moved);
    draggedGatewayIndex.value = null;
}

const draggedCardIndex = ref<number | null>(null);

function onCardDragStart(index: number): void {
    draggedCardIndex.value = index;
}

function onCardDrop(index: number): void {
    if (draggedCardIndex.value === null || draggedCardIndex.value === index) {
        draggedCardIndex.value = null;
        return;
    }

    const list = bookingEngineSettings.value.accepted_card_types;
    const [moved] = list.splice(draggedCardIndex.value, 1);
    list.splice(index, 0, moved);
    draggedCardIndex.value = null;
}

const simpleToggleFields: { key: BooleanSettingKey; label: string }[] = [
    { key: 'enable_booking_engine', label: 'Enable Booking Engine' },
    { key: 'enable_ota_display', label: 'Enable OTA Display' },
    { key: 'enable_pay_at_hotel', label: 'Enable Pay at Hotel Booking' },
    { key: 'enable_google_ari', label: 'Enable Google ARI' },
    { key: 'enable_trip_advisor', label: 'Enable Trip Advisor' },
    { key: 'enable_terms_and_condition', label: 'Enable terms and condition' },
    { key: 'enable_data_privacy_policy', label: 'Enable Data Privacy Policy' },
    { key: 'enable_rates_at_calendar', label: 'Enable rates at calendar' },
    {
        key: 'enable_estimated_arrival_time',
        label: 'Enable Estimated Arrival Time',
    },
    { key: 'enable_special_request', label: 'Enable Special Request' },
    { key: 'enable_nationality', label: 'Enable Nationality' },
    { key: 'enable_purpose_to_visit', label: 'Enable Purpose To Visit' },
    { key: 'enable_marketing_consent', label: 'Enable Marketing Consent' },
    { key: 'enable_deals_and_promo', label: 'Enable Deals and Promo' },
    { key: 'show_agent_login', label: 'Show Agent Login on Booking Engine' },
    {
        key: 'booking_summary_cart_expansion',
        label: 'Booking Summary Cart Expansion',
    },
    {
        key: 'package_details_visible_with_room',
        label: 'Package Details Visible with Room',
    },
    { key: 'show_amenities', label: 'Show Amenities' },
    { key: 'show_bed_types', label: 'Show Bed Types' },
];

const rateDisplayModeOptions = [
    { value: 'gross_rate', label: 'Gross Rate' },
    { value: 'net_rate', label: 'Net Rate' },
];

// The site's origin is read once mounted: there is no window while rendering on the server.
const siteOrigin = ref('');
onMounted(() => (siteOrigin.value = window.location.origin));
const bookingEngineUrl = computed(
    () =>
        `${siteOrigin.value}${BookingEngineController.web(props.property.ulid).url}`,
);
const bookingEngineUrlCopied = ref(false);

async function copyBookingEngineUrl(): Promise<void> {
    try {
        await navigator.clipboard.writeText(bookingEngineUrl.value);
        bookingEngineUrlCopied.value = true;
        setTimeout(() => (bookingEngineUrlCopied.value = false), 1500);
    } catch {
        // Clipboard access denied — nothing further to do.
    }
}
</script>

<template>
    <Head :title="`Booking Engine: ${property.name}`" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Booking Engine
        </h1>
        <div class="flex items-center gap-3">
            <button
                type="button"
                class="text-sm font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                @click="activityLogsOpen = !activityLogsOpen"
            >
                Activity Logs
            </button>
            <Link
                :href="admin.bookingEngineSettings.index().url"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
            >
                Close
            </Link>
        </div>
    </div>

    <div
        v-if="activityLogsOpen"
        class="mb-6 flex flex-col gap-2 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
    >
        <h3
            class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
        >
            Activity Logs
        </h3>
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            No activity yet.
        </p>
    </div>

    <div
        class="flex max-w-3xl flex-col divide-y divide-[#e3e3e0] rounded-lg border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
    >
        <template v-for="item in simpleToggleFields" :key="item.key">
            <div class="flex items-center justify-between px-4 py-3">
                <span
                    class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >{{ item.label }}</span
                >
                <button
                    type="button"
                    class="relative inline-flex h-7 w-16 items-center rounded-full transition-colors"
                    :class="
                        bookingEngineSettings[item.key]
                            ? 'bg-green-600'
                            : 'bg-red-500'
                    "
                    @click="
                        (bookingEngineSettings[item.key] as boolean) =
                            !bookingEngineSettings[item.key]
                    "
                >
                    <span
                        v-if="bookingEngineSettings[item.key]"
                        class="absolute left-2 text-xs font-bold text-white"
                        >ON</span
                    >
                    <span
                        v-else
                        class="absolute right-2 text-xs font-bold text-white"
                        >OFF</span
                    >
                    <span
                        class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform"
                        :class="
                            bookingEngineSettings[item.key]
                                ? 'translate-x-[38px]'
                                : 'translate-x-1'
                        "
                    />
                </button>
            </div>

            <div
                v-if="
                    item.key === 'enable_booking_engine' &&
                    bookingEngineSettings.enable_booking_engine
                "
                class="flex items-center justify-between gap-3 bg-[#fafaf9] px-4 py-3 dark:bg-[#1D1D1B]"
            >
                <div>
                    <span
                        class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Booking Engine URL</span
                    >
                    <p
                        class="max-w-md truncate text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{ bookingEngineUrl }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm font-medium text-[#1b1b18] hover:bg-white dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#161615]"
                        @click="copyBookingEngineUrl"
                    >
                        {{ bookingEngineUrlCopied ? 'Copied!' : 'Copy' }}
                    </button>
                    <a
                        :href="bookingEngineUrl"
                        target="_blank"
                        rel="noopener"
                        class="rounded-md border border-black bg-[#1b1b18] px-3 py-1.5 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    >
                        Go to Booking Engine
                    </a>
                </div>
            </div>

            <div
                v-if="
                    item.key === 'enable_pay_at_hotel' &&
                    bookingEngineSettings.enable_pay_at_hotel
                "
                class="flex flex-col gap-1 px-4 py-3"
            >
                <label class="flex items-center justify-between gap-3">
                    <span
                        class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Booking Amount % for Pay@hotel</span
                    >
                    <input
                        v-model.number="
                            bookingEngineSettings.pay_at_hotel_percentage
                        "
                        type="number"
                        min="0"
                        max="100"
                        class="block w-32 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Customer will be charged Booking % ({{
                        bookingEngineSettings.pay_at_hotel_percentage ?? 0
                    }}) + Additional Charges () + Service Tax % () as Pay@hotel
                    Booking Amount
                </p>
            </div>
        </template>

        <label class="flex items-center justify-between gap-3 px-4 py-3">
            <span class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Hide Tax Breakup</span
            >
            <input
                v-model="bookingEngineSettings.hide_tax_breakup"
                type="checkbox"
                class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
            />
        </label>

        <label class="flex items-center justify-between gap-3 px-4 py-3">
            <span class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Max Rooms</span
            >
            <input
                v-model.number="bookingEngineSettings.max_rooms"
                type="number"
                min="1"
                class="block w-32 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            />
        </label>

        <label class="flex items-center justify-between gap-3 px-4 py-3">
            <span class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Default Adult Search Count</span
            >
            <input
                v-model.number="
                    bookingEngineSettings.default_adult_search_count
                "
                type="number"
                min="1"
                class="block w-32 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            />
        </label>

        <!-- Category -->
        <div class="flex flex-col gap-3 px-4 py-3">
            <h3
                class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Category
            </h3>
            <div
                class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
            >
                <table
                    class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
                >
                    <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                        <tr>
                            <th
                                class="w-8 px-2 py-2 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                ↕
                            </th>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Display Order
                            </th>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Name
                            </th>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Available on BE
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                    >
                        <tr
                            v-for="(category, index) in orderedCategories"
                            :key="category.id"
                            draggable="true"
                            class="cursor-move"
                            :class="{
                                'opacity-40': draggedCategoryId === category.id,
                            }"
                            @dragstart="onCategoryDragStart(category.id)"
                            @dragover.prevent
                            @drop="onCategoryDrop(category.id)"
                        >
                            <td
                                class="px-2 py-2 text-[#a1a09a] dark:text-[#706f6c]"
                            >
                                ↕
                            </td>
                            <td
                                class="px-4 py-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ index + 1 }}
                            </td>
                            <td
                                class="px-4 py-2 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ category.name }}
                            </td>
                            <td class="px-4 py-2">
                                <input
                                    v-model="
                                        bookingEngineSettings
                                            .category_visibility[category.id]
                                    "
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                />
                            </td>
                        </tr>
                        <tr v-if="!orderedCategories.length">
                            <td
                                colspan="4"
                                class="px-4 py-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                No room categories found for this property.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Allowed Payment Gateways -->
        <div class="flex flex-col gap-3 px-4 py-3">
            <div>
                <h3
                    class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Allowed Payment Gateways
                </h3>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Active configured payment gateways available on the booking
                    engine for online payments.
                </p>
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
                                class="w-8 px-2 py-2 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                ↕
                            </th>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Display Order
                            </th>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Gateway Name
                            </th>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Status
                            </th>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Show on Booking Engine
                            </th>
                            <th
                                class="w-16 px-4 py-2 text-center text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Settings
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                    >
                        <tr
                            v-for="(
                                gateway, index
                            ) in bookingEngineSettings.allowed_payment_gateways"
                            :key="gateway.id"
                            draggable="true"
                            class="cursor-move"
                            :class="{
                                'opacity-40': draggedGatewayIndex === index,
                            }"
                            @dragstart="onGatewayDragStart(index)"
                            @dragover.prevent
                            @drop="onGatewayDrop(index)"
                        >
                            <td
                                class="px-2 py-2 text-[#a1a09a] dark:text-[#706f6c]"
                            >
                                ↕
                            </td>
                            <td
                                class="px-4 py-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ index + 1 }}
                            </td>
                            <td
                                class="px-4 py-2 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                <div>{{ gateway.name }}</div>
                                <div
                                    v-if="gateway.description"
                                    class="text-xs font-normal text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    {{ gateway.description }}
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                <template v-if="isGatewayConfigured(gateway)">
                                    <span
                                        v-if="gateway.is_active !== false"
                                        class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
                                    >
                                        <span
                                            class="h-1.5 w-1.5 rounded-full bg-emerald-500"
                                        ></span>
                                        Active / Configured
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400"
                                    >
                                        <span
                                            class="h-1.5 w-1.5 rounded-full bg-amber-500"
                                        ></span>
                                        Inactive / Configured
                                    </span>
                                </template>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full bg-zinc-400"
                                    ></span>
                                    Not Configured
                                </span>
                            </td>
                            <td class="px-4 py-2">
                                <input
                                    v-model="gateway.enabled"
                                    :disabled="
                                        !isGatewayConfigured(gateway) ||
                                        gateway.is_active === false
                                    "
                                    :title="
                                        !isGatewayConfigured(gateway)
                                            ? 'Configure Key and Secret first'
                                            : gateway.is_active === false
                                              ? 'Activate gateway in settings first'
                                              : 'Show on Booking Engine'
                                    "
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] disabled:cursor-not-allowed disabled:opacity-40 dark:border-[#3E3E3A]"
                                />
                            </td>
                            <td class="px-4 py-2 text-center">
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-md p-1.5 text-[#706f6c] transition hover:bg-black/5 hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:bg-white/10 dark:hover:text-[#EDEDEC]"
                                    :title="`Configure ${gateway.name}`"
                                    @click="openGatewaySettings(gateway)"
                                >
                                    <svg
                                        class="h-4 w-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="1.5"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"
                                        />
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                        />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                        <tr
                            v-if="
                                !bookingEngineSettings.allowed_payment_gateways ||
                                !bookingEngineSettings.allowed_payment_gateways
                                    .length
                            "
                        >
                            <td
                                colspan="6"
                                class="px-4 py-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                No payment gateways configured.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Allow Partial Payment -->
        <div
            class="flex flex-col gap-1 border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <label
                class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3"
            >
                <div>
                    <span
                        class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Allow Partial Payment</span
                    >
                    <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        Allow guests to pay a partial amount during online
                        booking and settle the remaining balance at hotel
                        check-in.
                    </p>
                </div>
                <input
                    v-model="bookingEngineSettings.allow_partial_payment"
                    type="checkbox"
                    class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                />
            </label>

            <div
                v-if="bookingEngineSettings.allow_partial_payment"
                class="flex flex-col gap-1 border-t border-[#f5f5f4] bg-[#fafaf9] px-4 py-3 dark:border-[#262624] dark:bg-[#121211]"
            >
                <label class="flex items-center justify-between gap-3">
                    <span
                        class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Partial Payment Percentage (%)</span
                    >
                    <div class="flex items-center gap-2">
                        <input
                            v-model.number="
                                bookingEngineSettings.partial_payment_percentage
                            "
                            type="number"
                            min="1"
                            max="99"
                            step="1"
                            class="block w-28 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <span
                            class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                            >%</span
                        >
                    </div>
                </label>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Guest pays
                    {{
                        bookingEngineSettings.partial_payment_percentage ?? 50
                    }}% now online; remaining
                    {{
                        100 -
                        (bookingEngineSettings.partial_payment_percentage ??
                            50)
                    }}% will be due upon arrival at the property.
                </p>
            </div>
        </div>

        <!-- Accepted Card Types -->
        <div class="flex flex-col gap-3 px-4 py-3">
            <h3
                class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Accepted Card Types
            </h3>
            <div
                class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
            >
                <table
                    class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
                >
                    <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                        <tr>
                            <th
                                class="w-8 px-2 py-2 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                ↕
                            </th>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Display Order
                            </th>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Name
                            </th>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Show on Booking Engine
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                    >
                        <tr
                            v-for="(
                                card, index
                            ) in bookingEngineSettings.accepted_card_types"
                            :key="card.id"
                            draggable="true"
                            class="cursor-move"
                            :class="{
                                'opacity-40': draggedCardIndex === index,
                            }"
                            @dragstart="onCardDragStart(index)"
                            @dragover.prevent
                            @drop="onCardDrop(index)"
                        >
                            <td
                                class="px-2 py-2 text-[#a1a09a] dark:text-[#706f6c]"
                            >
                                ↕
                            </td>
                            <td
                                class="px-4 py-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ index + 1 }}
                            </td>
                            <td
                                class="px-4 py-2 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ card.name }}
                            </td>
                            <td class="px-4 py-2">
                                <input
                                    v-model="card.enabled"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <label class="flex items-center justify-between gap-3 px-4 py-3">
            <span class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Rate display mode</span
            >
            <select
                v-model="bookingEngineSettings.rate_display_mode"
                class="block w-48 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            >
                <option
                    v-for="option in rateDisplayModeOptions"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
        </label>

        <div class="flex flex-col gap-2 px-4 py-3">
            <label
                class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Credit Card Notes</label
            >
            <QuillEditor v-model="bookingEngineSettings.credit_card_notes" />
        </div>

        <div class="flex flex-col gap-2 px-4 py-3">
            <label
                class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Custom No Availability Message</label
            >
            <QuillEditor
                v-model="bookingEngineSettings.custom_no_availability_message"
            />
        </div>

        <div class="flex flex-col gap-2 px-4 py-3">
            <label
                class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Landing page message</label
            >
            <QuillEditor v-model="bookingEngineSettings.landing_page_message" />
        </div>

        <div class="flex flex-col gap-2 px-4 py-3">
            <label
                class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Data Privacy Policy</label
            >
            <QuillEditor
                v-model="bookingEngineSettings.data_privacy_policy_text"
            />
        </div>
    </div>

    <div class="mt-6 flex items-center gap-3">
        <button
            type="button"
            :disabled="form.processing"
            class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
            @click="submit"
        >
            {{ form.processing ? 'Saving...' : 'Save' }}
        </button>
        <Link
            :href="admin.bookingEngineSettings.index()"
            class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
        >
            Cancel
        </Link>
    </div>

    <!-- Payment Gateway Settings Modal -->
    <Modal
        :show="gatewayModalOpen"
        :title="`Configure ${editingGateway?.name ?? 'Payment Gateway'}`"
        @close="gatewayModalOpen = false"
    >
        <div class="flex flex-col gap-4">
            <!-- Status Toggle -->
            <div
                class="flex items-center justify-between rounded-lg border border-[#e3e3e0] bg-[#fafaf9] p-3 dark:border-[#3E3E3A] dark:bg-[#1D1D1B]"
            >
                <div>
                    <div
                        class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Gateway Status
                    </div>
                    <div class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        {{
                            modalForm.is_active
                                ? 'Active & ready for transactions'
                                : 'Inactive / Disabled'
                        }}
                    </div>
                </div>
                <button
                    type="button"
                    class="relative inline-flex h-7 w-16 shrink-0 cursor-pointer items-center rounded-full transition-colors focus:outline-none"
                    :class="modalForm.is_active ? 'bg-green-600' : 'bg-red-500'"
                    @click="modalForm.is_active = !modalForm.is_active"
                >
                    <span
                        v-if="modalForm.is_active"
                        class="absolute left-2 text-xs font-bold text-white"
                        >ON</span
                    >
                    <span
                        v-else
                        class="absolute right-2 text-xs font-bold text-white"
                        >OFF</span
                    >
                    <span
                        class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform"
                        :class="
                            modalForm.is_active
                                ? 'translate-x-[38px]'
                                : 'translate-x-1'
                        "
                    />
                </button>
            </div>

            <!-- Key ID / Client ID input -->
            <div class="flex flex-col gap-1.5">
                <label
                    class="text-xs font-semibold tracking-wider text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    {{
                        editingGateway?.id === 'cashfree'
                            ? 'App ID / Client ID'
                            : editingGateway?.id === 'razorpay'
                              ? 'Key ID'
                              : 'API Key / Client ID'
                    }}
                    <span class="text-red-500">*</span>
                </label>
                <input
                    v-model="modalForm.key"
                    type="text"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] placeholder:text-[#a1a09a] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    :placeholder="
                        editingGateway?.id === 'razorpay'
                            ? 'e.g. rzp_test_... or rzp_live_...'
                            : editingGateway?.id === 'cashfree'
                              ? 'e.g. TEST10... or CF...'
                              : 'Enter API Key'
                    "
                />
                <p
                    v-if="
                        editingGateway?.id === 'razorpay' &&
                        modalForm.key.trim()
                    "
                    class="text-[11px] font-medium"
                    :class="
                        modalForm.key.startsWith('rzp_test_')
                            ? 'text-blue-600 dark:text-blue-400'
                            : modalForm.key.startsWith('rzp_live_')
                              ? 'text-emerald-600 dark:text-emerald-400'
                              : 'text-[#706f6c] dark:text-[#A1A09A]'
                    "
                >
                    {{
                        modalForm.key.startsWith('rzp_test_')
                            ? '✓ Detected Razorpay Test / Sandbox Key'
                            : modalForm.key.startsWith('rzp_live_')
                              ? '✓ Detected Razorpay Live / Production Key'
                              : 'Public identifier provided by Razorpay.'
                    }}
                </p>
                <p
                    v-else
                    class="text-[11px] text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Public/Client identifier provided by
                    {{ editingGateway?.name }}.
                </p>
            </div>

            <!-- Environment / Mode (Cashfree) -->
            <div
                v-if="editingGateway?.id === 'cashfree'"
                class="flex flex-col gap-1.5"
            >
                <label
                    class="text-xs font-semibold tracking-wider text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Environment / Mode
                </label>
                <select
                    v-model="modalForm.mode"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                >
                    <option value="sandbox">Sandbox (Test Mode)</option>
                    <option value="production">Production (Live Mode)</option>
                </select>
                <p class="text-[11px] text-[#706f6c] dark:text-[#A1A09A]">
                    Choose Sandbox if using Cashfree test credentials, or
                    Production for live payments.
                </p>
            </div>

            <!-- Secret Key input -->
            <div class="flex flex-col gap-1.5">
                <label
                    class="text-xs font-semibold tracking-wider text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    {{
                        editingGateway?.id === 'razorpay'
                            ? 'Key Secret'
                            : editingGateway?.id === 'cashfree'
                              ? 'Secret Key'
                              : 'API Secret / Secret Key'
                    }}
                    <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <input
                        v-model="modalForm.secret"
                        :type="showSecret ? 'text' : 'password'"
                        class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 pr-10 text-sm text-[#1b1b18] placeholder:text-[#a1a09a] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        placeholder="Enter Secret Key"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 right-0 flex items-center pr-3 text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                        @click="showSecret = !showSecret"
                        :title="showSecret ? 'Hide secret' : 'Show secret'"
                    >
                        <svg
                            v-if="showSecret"
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"
                            />
                        </svg>
                        <svg
                            v-else
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                            />
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                            />
                        </svg>
                    </button>
                </div>
                <p class="text-[11px] text-[#706f6c] dark:text-[#A1A09A]">
                    Confidential private key used to sign requests and verify
                    signatures.
                </p>
            </div>

            <!-- Gateway commission: recorded on each online receipt (the Daily Cashier Report's PG Commission). -->
            <div class="flex flex-col gap-1.5">
                <span
                    class="text-xs font-semibold tracking-wider text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >Gateway commission</span
                >
                <div class="grid grid-cols-3 gap-2">
                    <label
                        class="flex flex-col gap-1 text-[11px] text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        % of payment
                        <input
                            v-model.number="modalForm.commission_percent"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </label>
                    <label
                        class="flex flex-col gap-1 text-[11px] text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Fixed per payment
                        <input
                            v-model.number="modalForm.commission_fixed"
                            type="number"
                            min="0"
                            step="0.01"
                            class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </label>
                    <label
                        class="flex flex-col gap-1 text-[11px] text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Tax on fee %
                        <input
                            v-model.number="modalForm.commission_tax_percent"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </label>
                </div>
                <p class="text-[11px] text-[#706f6c] dark:text-[#A1A09A]">
                    Used when the gateway doesn't report its fee for a payment
                    (live Razorpay payments report their own).
                </p>
            </div>

            <!-- Status note -->
            <div
                v-if="modalForm.key.trim() && modalForm.secret.trim()"
                class="flex items-center gap-2 rounded-md bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
            >
                <svg
                    class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M5 13l4 4L19 7"
                    />
                </svg>
                <span
                    >Both credentials provided. Gateway will be marked as
                    <strong>Configured</strong>.</span
                >
            </div>
            <div
                v-else
                class="flex items-center gap-2 rounded-md bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400"
            >
                <svg
                    class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                    />
                </svg>
                <span
                    >Both Key and Secret are required to mark this gateway as
                    Configured.</span
                >
            </div>

            <!-- Footer buttons -->
            <div
                class="mt-2 flex items-center justify-end gap-2 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
            >
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-3.5 py-1.5 text-sm font-medium text-[#1b1b18] hover:bg-[#fafaf9] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    @click="gatewayModalOpen = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md border border-black bg-[#1b1b18] px-4 py-1.5 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    @click="saveGatewayModal"
                >
                    Apply
                </button>
            </div>
        </div>
    </Modal>
</template>
