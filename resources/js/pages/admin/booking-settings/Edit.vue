<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useUrlTab } from '@/lib/useUrlTab';
import admin from '@/routes/admin';
import type { MetaField, MetaFieldValue } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    property: { id: number; name: string };
    metaFields: MetaField[];
    metaFieldValues: MetaFieldValue[];
    /** Payment Settings: every selectable payment mode with its saved (or default) switches. */
    paymentModes: PaymentModeRow[];
}>();

type PaymentModeRow = {
    mode: string;
    enabled: boolean;
    payment: boolean;
    refund: boolean;
};

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

function markEnabled(key: string): void {
    const field = fieldFor(key);

    if (field) {
        form.meta_fields[field.id].enabled = true;
    }
}

function bindSettings<T>(key: string, state: { value: T }): void {
    markEnabled(key);

    watch(
        state,
        (value) => {
            const field = fieldFor(key);

            if (field) {
                form.meta_fields[field.id].data = JSON.stringify(value);
            }
        },
        { deep: true, immediate: true },
    );
}

// Reservation settings
type ReservationSettings = {
    reservation_prefix: string;
    max_reservation_number: number;
    infant_count: number;
    infant_display_text: string;
    min_infant_age: number;
    max_infant_age: number;
    min_chargeable_child_age: number;
    max_chargeable_child_age: number;
    days_between_checkin_checkout: number;
    consider_dirty_room_for_parking_lot: boolean;
    soft_block_for_do_not_move: boolean;
    allow_zero_rate_bookings: boolean;
    move_checkin_checkout_booking: boolean;
};

const reservationSettings = ref<ReservationSettings>(
    parseOrDefault<ReservationSettings>('booking_settings_reservation', {
        reservation_prefix: '',
        max_reservation_number: 0,
        infant_count: 0,
        infant_display_text: 'Infant',
        min_infant_age: 0,
        max_infant_age: 5,
        min_chargeable_child_age: 6,
        max_chargeable_child_age: 12,
        days_between_checkin_checkout: 0,
        consider_dirty_room_for_parking_lot: false,
        soft_block_for_do_not_move: false,
        allow_zero_rate_bookings: false,
        move_checkin_checkout_booking: false,
    }),
);
bindSettings('booking_settings_reservation', reservationSettings);

// Post master settings
type PostMasterSettings = {
    max_open_pm_reservations: number;
    pm_open_total_gross_amount: number;
    max_number_of_rooms: number;
    max_number_of_nights: number;
    max_number_of_room_nights: number;
    max_reservation_gross_amount: number;
};

const postMasterSettings = ref<PostMasterSettings>(
    parseOrDefault<PostMasterSettings>('booking_settings_post_master', {
        max_open_pm_reservations: 0,
        pm_open_total_gross_amount: 0,
        max_number_of_rooms: 0,
        max_number_of_nights: 0,
        max_number_of_room_nights: 0,
        max_reservation_gross_amount: 0,
    }),
);
bindSettings('booking_settings_post_master', postMasterSettings);

// Document settings
const documentTypeNames = [
    'Others',
    'Aadhaar Card',
    'Driving License',
    'Passport',
    'Pan Card',
    'Voter ID',
    'User DP',
    'Letter Of Authorization',
    'Signature',
    'Purchase Order',
    'Business Travel Authorization',
    'Certificate of Availability of Fund',
    'Contract',
    'Memorandum of Agreement',
    'Signed Guest Folio',
    'Sales Voucher Form',
    'Sales Head Authorization Voucher',
    'DNI',
    'Carnet De Ext.',
];

type DocumentTypeRow = {
    name: string;
    status: boolean;
    folio_document: boolean;
    require_expiry_date: boolean;
    require_document_number: boolean;
};

type DocumentSettings = {
    documents: DocumentTypeRow[];
};

function defaultDocumentRow(name: string): DocumentTypeRow {
    const folioDocumentDefaults = [
        'Others',
        'Aadhaar Card',
        'Driving License',
        'Passport',
        'Pan Card',
        'Voter ID',
        'User DP',
    ];
    const statusDefaults = [
        ...folioDocumentDefaults,
        'Letter Of Authorization',
        'Signature',
        'Purchase Order',
        'Business Travel Authorization',
        'Certificate of Availability of Fund',
        'Contract',
        'Memorandum of Agreement',
    ];

    return {
        name,
        status: statusDefaults.includes(name),
        folio_document: folioDocumentDefaults.includes(name),
        require_expiry_date: false,
        require_document_number: false,
    };
}

function initialDocumentSettings(): DocumentSettings {
    const raw = rawDataFor('booking_settings_document');
    const defaults: DocumentSettings = {
        documents: documentTypeNames.map(defaultDocumentRow),
    };

    if (!raw) {
        return defaults;
    }

    try {
        const parsed = JSON.parse(raw) as DocumentSettings;

        if (parsed && Array.isArray(parsed.documents)) {
            const customRows = parsed.documents.filter(
                (row) => row?.name && !documentTypeNames.includes(row.name),
            );

            return {
                documents: [
                    ...documentTypeNames.map((name) => {
                        const match = parsed.documents.find(
                            (row) => row.name === name,
                        );

                        return match ?? defaultDocumentRow(name);
                    }),
                    ...customRows,
                ],
            };
        }
    } catch {
        // fall through to the defaults below
    }

    return defaults;
}

const documentSettings = ref<DocumentSettings>(initialDocumentSettings());
bindSettings('booking_settings_document', documentSettings);

const newDocumentTypeName = ref('');
const newDocumentTypeError = ref('');

/** Custom types behave like built-in ones: enabled as folio documents on the booking form's Upload Documents dialog. */
function addDocumentType(): void {
    const name = newDocumentTypeName.value.trim();

    if (!name) {
        newDocumentTypeError.value = 'Enter a document type name.';

        return;
    }

    if (
        documentSettings.value.documents.some(
            (row) => row.name.toLowerCase() === name.toLowerCase(),
        )
    ) {
        newDocumentTypeError.value = `"${name}" already exists.`;

        return;
    }

    documentSettings.value.documents.push({
        name,
        status: true,
        folio_document: true,
        require_expiry_date: false,
        require_document_number: false,
    });
    newDocumentTypeName.value = '';
    newDocumentTypeError.value = '';
}

function removeDocumentType(name: string): void {
    documentSettings.value.documents = documentSettings.value.documents.filter(
        (row) => row.name !== name,
    );
}

// Printer settings
type PrinterSettings = {
    enable_auto_print: boolean;
    printer_name: string;
    paper_size: 'a4' | 'thermal_80mm' | 'thermal_58mm';
};

const printerSettings = ref<PrinterSettings>(
    parseOrDefault<PrinterSettings>('booking_settings_printer', {
        enable_auto_print: false,
        printer_name: '',
        paper_size: 'a4',
    }),
);
bindSettings('booking_settings_printer', printerSettings);

// Auto-release settings
type AutoReleaseReminder = {
    days: number;
};

type AutoReleaseSettings = {
    channel: string;
    percentage_amount_for_pre_payment: number;
    release_date_type: string;
    duration_days: number;
    reminders: AutoReleaseReminder[];
};

const autoReleaseSettings = ref<AutoReleaseSettings>(
    parseOrDefault<AutoReleaseSettings>('booking_settings_auto_release', {
        channel: '',
        percentage_amount_for_pre_payment: 0,
        release_date_type: '',
        duration_days: 0,
        reminders: [],
    }),
);
bindSettings('booking_settings_auto_release', autoReleaseSettings);

function addAutoReleaseReminder(): void {
    autoReleaseSettings.value.reminders.push({ days: 0 });
}

function removeAutoReleaseReminder(index: number): void {
    autoReleaseSettings.value.reminders.splice(index, 1);
}

// Housekeeping settings
const housekeepingPermissionOptions = [
    'No Permission Required',
    'All users who can mark room as Clean',
    'All users who can mark room as Dirty',
    'All users who can mark room as to be inspected',
    'All users who can mark room as inspected',
    'All users who can mark room as Under Maintenance',
];

type HousekeepingStatusRow = {
    color: string;
    display_order: number;
    name: string;
    abbreviation: string;
    description: string;
    permission_required: string;
    default_on_check_in: boolean;
    default_on_check_out: boolean;
    default_on_room_change: boolean;
    available_for_parking_lot: boolean;
    allow_check_in: boolean;
    hard_stop: boolean;
};

type HousekeepingSettings = {
    statuses: HousekeepingStatusRow[];
};

const housekeepingSettings = ref<HousekeepingSettings>(
    parseOrDefault<HousekeepingSettings>('booking_settings_housekeeping', {
        statuses: [
            {
                color: '#e3e3e0',
                display_order: 1,
                name: 'Clean',
                abbreviation: '',
                description: '',
                permission_required: 'No Permission Required',
                default_on_check_in: false,
                default_on_check_out: false,
                default_on_room_change: false,
                available_for_parking_lot: true,
                allow_check_in: true,
                hard_stop: false,
            },
            {
                color: '#f53003',
                display_order: 2,
                name: 'Dirty',
                abbreviation: '',
                description: '',
                permission_required: 'No Permission Required',
                default_on_check_in: false,
                default_on_check_out: true,
                default_on_room_change: false,
                available_for_parking_lot: true,
                allow_check_in: false,
                hard_stop: false,
            },
        ],
    }),
);
bindSettings('booking_settings_housekeeping', housekeepingSettings);

const housekeepingModalOpen = ref(false);

function emptyHousekeepingStatus(): HousekeepingStatusRow {
    return {
        color: '#e3e3e0',
        display_order: housekeepingSettings.value.statuses.length + 1,
        name: '',
        abbreviation: '',
        description: '',
        permission_required: 'No Permission Required',
        default_on_check_in: false,
        default_on_check_out: false,
        default_on_room_change: false,
        available_for_parking_lot: false,
        allow_check_in: false,
        hard_stop: false,
    };
}

const newHousekeepingStatus = ref<HousekeepingStatusRow>(
    emptyHousekeepingStatus(),
);

function openHousekeepingModal(): void {
    newHousekeepingStatus.value = emptyHousekeepingStatus();
    housekeepingModalOpen.value = true;
}

function saveHousekeepingStatus(): void {
    housekeepingSettings.value.statuses.push(newHousekeepingStatus.value);
    housekeepingModalOpen.value = false;
}

function removeHousekeepingStatus(index: number): void {
    housekeepingSettings.value.statuses.splice(index, 1);
}

// Custom fields
type CustomFieldContainer = {
    name: string;
    display_name: string;
    display_order: number;
    active: boolean;
    is_selection_mandatory: boolean;
    is_visible_on_invoice: boolean;
    segmentation_items: string[];
};

type CustomFieldSettings = {
    containers: CustomFieldContainer[];
};

function emptyCustomFieldContainer(index: number): CustomFieldContainer {
    return {
        name: `Container ${index}`,
        display_name: '',
        display_order: index,
        active: false,
        is_selection_mandatory: false,
        is_visible_on_invoice: false,
        segmentation_items: [],
    };
}

const customFieldSettings = ref<CustomFieldSettings>(
    parseOrDefault<CustomFieldSettings>('booking_settings_custom_fields', {
        containers: [],
    }),
);
bindSettings('booking_settings_custom_fields', customFieldSettings);

function addCustomFieldContainer(): void {
    customFieldSettings.value.containers.push(
        emptyCustomFieldContainer(
            customFieldSettings.value.containers.length + 1,
        ),
    );
}

function removeCustomFieldContainer(index: number): void {
    customFieldSettings.value.containers.splice(index, 1);
}

function addSegmentationItem(container: CustomFieldContainer): void {
    container.segmentation_items.push('');
}

function removeSegmentationItem(
    container: CustomFieldContainer,
    index: number,
): void {
    container.segmentation_items.splice(index, 1);
}

// Payment settings: which modes are used, and whether each is offered for payments and refunds.
const paymentModeSettings = ref<{ modes: PaymentModeRow[] }>({
    modes: props.paymentModes.map((row) => ({ ...row })),
});
bindSettings('booking_settings_payment_modes', paymentModeSettings);

function setAllPaymentModes(
    column: 'enabled' | 'payment' | 'refund',
    value: boolean,
): void {
    paymentModeSettings.value.modes.forEach((row) => (row[column] = value));
}

const bookingSettingsTabs = [
    'reservation',
    'post-master',
    'document',
    'printer',
    'auto-release',
    'housekeeping',
    'custom-fields',
    'payments',
] as const;

const activeTab = useUrlTab(bookingSettingsTabs, 'reservation');

function submit(): void {
    form.put(admin.bookingSettings.update(props.property).url, {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
    <Head :title="`Booking Settings: ${property.name}`" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Booking Settings: {{ property.name }}
        </h1>
        <Link
            :href="admin.bookingSettings.index().url"
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
        >
            Close
        </Link>
    </div>

    <div
        class="mb-6 flex flex-wrap gap-1 border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <button
            v-for="tab in [
                { key: 'reservation', label: 'Reservation Settings' },
                { key: 'post-master', label: 'Post Master Settings' },
                { key: 'document', label: 'Document Settings' },
                { key: 'printer', label: 'Printer Setting' },
                { key: 'auto-release', label: 'Auto-release' },
                { key: 'housekeeping', label: 'Housekeeping' },
                { key: 'custom-fields', label: 'Custom Fields' },
                { key: 'payments', label: 'Payment Settings' },
            ]"
            :key="tab.key"
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === tab.key
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = tab.key as typeof activeTab"
        >
            {{ tab.label }}
        </button>
    </div>

    <form class="flex flex-col gap-6" @submit.prevent="submit">
        <div
            v-show="activeTab === 'payments'"
            class="max-w-2xl"
            data-test="payment-settings"
        >
            <p class="mb-3 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Enabled modes with <strong>Payment</strong> ticked are offered
                when taking a payment; those with
                <strong>Refunds</strong> ticked when paying a refund. Gateway,
                OTA and e-collect receipts are recorded automatically and aren't
                listed.
            </p>
            <div
                class="overflow-hidden rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
            >
                <table class="w-full text-sm">
                    <thead
                        class="bg-[#fafaf9] text-left text-xs text-[#706f6c] dark:bg-[#1D1D1B] dark:text-[#A1A09A]"
                    >
                        <tr>
                            <th class="px-4 py-2 font-medium">
                                Payment method
                            </th>
                            <th
                                v-for="column in [
                                    'enabled',
                                    'payment',
                                    'refund',
                                ] as const"
                                :key="column"
                                class="w-28 px-4 py-2 text-center font-medium"
                            >
                                <div>
                                    {{
                                        column === 'enabled'
                                            ? 'Enabled'
                                            : column === 'payment'
                                              ? 'Payment'
                                              : 'Refunds'
                                    }}
                                </div>
                                <div
                                    class="mt-0.5 space-x-1 text-[10px] font-normal"
                                >
                                    <button
                                        type="button"
                                        class="underline"
                                        @click="
                                            setAllPaymentModes(column, true)
                                        "
                                    >
                                        All
                                    </button>
                                    <button
                                        type="button"
                                        class="underline"
                                        @click="
                                            setAllPaymentModes(column, false)
                                        "
                                    >
                                        None
                                    </button>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in paymentModeSettings.modes"
                            :key="row.mode"
                            class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                            :class="{ 'opacity-50': !row.enabled }"
                        >
                            <td
                                class="px-4 py-2 text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ row.mode }}
                            </td>
                            <td class="px-4 py-2 text-center">
                                <input
                                    v-model="row.enabled"
                                    type="checkbox"
                                    :aria-label="`${row.mode} enabled`"
                                    :data-test="`mode-enabled-${row.mode}`"
                                />
                            </td>
                            <td class="px-4 py-2 text-center">
                                <input
                                    v-model="row.payment"
                                    type="checkbox"
                                    :disabled="!row.enabled"
                                    :aria-label="`${row.mode} for payments`"
                                />
                            </td>
                            <td class="px-4 py-2 text-center">
                                <input
                                    v-model="row.refund"
                                    type="checkbox"
                                    :disabled="!row.enabled"
                                    :aria-label="`${row.mode} for refunds`"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div
            v-show="activeTab === 'reservation'"
            class="grid max-w-2xl grid-cols-1 gap-4 sm:grid-cols-2"
        >
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Reservation Prefix</label
                >
                <input
                    v-model="reservationSettings.reservation_prefix"
                    type="text"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Max Reservation Number</label
                >
                <input
                    v-model.number="reservationSettings.max_reservation_number"
                    type="number"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Infant count</label
                >
                <input
                    v-model.number="reservationSettings.infant_count"
                    type="number"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Infant display text</label
                >
                <input
                    v-model="reservationSettings.infant_display_text"
                    type="text"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Minimum age of infant</label
                >
                <input
                    v-model.number="reservationSettings.min_infant_age"
                    type="number"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Maximum age of infant</label
                >
                <input
                    v-model.number="reservationSettings.max_infant_age"
                    type="number"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Minimum age of chargeable child</label
                >
                <input
                    v-model.number="
                        reservationSettings.min_chargeable_child_age
                    "
                    type="number"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Maximum age of chargeable child</label
                >
                <input
                    v-model.number="
                        reservationSettings.max_chargeable_child_age
                    "
                    type="number"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Days between Check in and Check out</label
                >
                <input
                    v-model.number="
                        reservationSettings.days_between_checkin_checkout
                    "
                    type="number"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <div class="col-span-full flex flex-col gap-2">
                <label
                    class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="
                            reservationSettings.consider_dirty_room_for_parking_lot
                        "
                        type="checkbox"
                    />
                    Consider Dirty Room For Parking Lot
                </label>
                <label
                    class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="reservationSettings.soft_block_for_do_not_move"
                        type="checkbox"
                    />
                    Soft block for do not move
                </label>
                <label
                    class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="reservationSettings.allow_zero_rate_bookings"
                        type="checkbox"
                    />
                    Allow Zero-Rate Bookings
                </label>
                <label
                    class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="
                            reservationSettings.move_checkin_checkout_booking
                        "
                        type="checkbox"
                    />
                    Move check-in/check-out booking
                </label>
            </div>
        </div>

        <div
            v-show="activeTab === 'post-master'"
            class="flex max-w-2xl flex-col gap-6"
        >
            <div>
                <h2
                    class="font-display mb-3 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Property Level Settings
                </h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Max Number of Open PM Reservations</label
                        >
                        <input
                            v-model.number="
                                postMasterSettings.max_open_pm_reservations
                            "
                            type="number"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >PM Open Total Gross Amount</label
                        >
                        <input
                            v-model.number="
                                postMasterSettings.pm_open_total_gross_amount
                            "
                            type="number"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                </div>
            </div>

            <div>
                <h2
                    class="font-display mb-3 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Per Reservation Level Settings
                </h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Max Number of Rooms</label
                        >
                        <input
                            v-model.number="
                                postMasterSettings.max_number_of_rooms
                            "
                            type="number"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Max Number of Nights</label
                        >
                        <input
                            v-model.number="
                                postMasterSettings.max_number_of_nights
                            "
                            type="number"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Max Number of Room Nights</label
                        >
                        <input
                            v-model.number="
                                postMasterSettings.max_number_of_room_nights
                            "
                            type="number"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Max Reservation Gross Amount</label
                        >
                        <input
                            v-model.number="
                                postMasterSettings.max_reservation_gross_amount
                            "
                            type="number"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div
            v-show="activeTab === 'document'"
            class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <table
                class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
            >
                <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                    <tr>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Name
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Status
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Folio Document
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Require Expiry Date
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Require document Number
                        </th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                >
                    <tr
                        v-for="row in documentSettings.documents"
                        :key="row.name"
                    >
                        <td
                            class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ row.name }}
                        </td>
                        <td class="px-4 py-3">
                            <input v-model="row.status" type="checkbox" />
                        </td>
                        <td class="px-4 py-3">
                            <input
                                v-model="row.folio_document"
                                type="checkbox"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <input
                                v-model="row.require_expiry_date"
                                type="checkbox"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <input
                                v-model="row.require_document_number"
                                type="checkbox"
                            />
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button
                                v-if="!documentTypeNames.includes(row.name)"
                                type="button"
                                class="text-sm font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                                @click="removeDocumentType(row.name)"
                            >
                                Remove
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div
                class="flex flex-wrap items-start gap-2 border-t border-[#e3e3e0] bg-white px-4 py-3 dark:border-[#3E3E3A] dark:bg-[#161615]"
            >
                <div class="flex flex-col gap-1">
                    <input
                        v-model="newDocumentTypeName"
                        type="text"
                        placeholder="New document type"
                        class="block w-64 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        @keydown.enter.prevent="addDocumentType"
                    />
                    <span
                        v-if="newDocumentTypeError"
                        class="text-xs text-[#f53003] dark:text-[#FF4433]"
                        >{{ newDocumentTypeError }}</span
                    >
                </div>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    @click="addDocumentType"
                >
                    Add document type
                </button>
            </div>
        </div>

        <div
            v-show="activeTab === 'printer'"
            class="flex max-w-md flex-col gap-4"
        >
            <label
                class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                <input
                    v-model="printerSettings.enable_auto_print"
                    type="checkbox"
                />
                Enable auto-print
            </label>
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Printer name</label
                >
                <input
                    v-model="printerSettings.printer_name"
                    type="text"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Paper size</label
                >
                <select
                    v-model="printerSettings.paper_size"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                >
                    <option value="a4">A4</option>
                    <option value="thermal_80mm">Thermal 80mm</option>
                    <option value="thermal_58mm">Thermal 58mm</option>
                </select>
            </div>
        </div>

        <div
            v-show="activeTab === 'auto-release'"
            class="flex max-w-2xl flex-col gap-6"
        >
            <div>
                <h2
                    class="font-display mb-3 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Define Settings
                </h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Channel</label
                        >
                        <input
                            v-model="autoReleaseSettings.channel"
                            type="text"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Percentage Amount for Pre Payment</label
                        >
                        <input
                            v-model.number="
                                autoReleaseSettings.percentage_amount_for_pre_payment
                            "
                            type="number"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                </div>
            </div>

            <div>
                <h2
                    class="font-display mb-3 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Release Date
                </h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Release Date Type</label
                        >
                        <input
                            v-model="autoReleaseSettings.release_date_type"
                            type="text"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Duration Days</label
                        >
                        <input
                            v-model.number="autoReleaseSettings.duration_days"
                            type="number"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                </div>
            </div>

            <div>
                <h2
                    class="font-display mb-3 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Set Reminder
                </h2>
                <div class="flex flex-col gap-2">
                    <div
                        v-for="(
                            reminder, index
                        ) in autoReleaseSettings.reminders"
                        :key="index"
                        class="flex items-center gap-3"
                    >
                        <input
                            v-model.number="reminder.days"
                            type="number"
                            placeholder="Days"
                            class="block w-32 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >days before release</span
                        >
                        <button
                            type="button"
                            class="text-sm font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                            @click="removeAutoReleaseReminder(index)"
                        >
                            Remove
                        </button>
                    </div>
                    <button
                        type="button"
                        class="w-fit text-sm font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                        @click="addAutoReleaseReminder"
                    >
                        Add Reminder
                    </button>
                </div>
            </div>
        </div>

        <div v-show="activeTab === 'housekeeping'" class="flex flex-col gap-4">
            <div
                class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
            >
                <table
                    class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
                >
                    <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                        <tr>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Color
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Display Order
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Name
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Abbreviation
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Check-in Default
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Check-out Default
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Room Change Default
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Allow Check-in
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Check-In Hard Stop
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Available For Parking Lot
                            </th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                    >
                        <tr
                            v-for="(
                                status, index
                            ) in housekeepingSettings.statuses"
                            :key="index"
                        >
                            <td class="px-4 py-3">
                                <span
                                    class="inline-block h-5 w-5 rounded border border-[#e3e3e0] dark:border-[#3E3E3A]"
                                    :style="{ backgroundColor: status.color }"
                                />
                            </td>
                            <td
                                class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ status.display_order }}
                            </td>
                            <td
                                class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ status.name }}
                            </td>
                            <td
                                class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ status.abbreviation || '—' }}
                            </td>
                            <td
                                class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ status.default_on_check_in ? 'Yes' : 'No' }}
                            </td>
                            <td
                                class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ status.default_on_check_out ? 'Yes' : 'No' }}
                            </td>
                            <td
                                class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{
                                    status.default_on_room_change ? 'Yes' : 'No'
                                }}
                            </td>
                            <td
                                class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ status.allow_check_in ? 'Yes' : 'No' }}
                            </td>
                            <td
                                class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ status.hard_stop ? 'Yes' : 'No' }}
                            </td>
                            <td
                                class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{
                                    status.available_for_parking_lot
                                        ? 'Yes'
                                        : 'No'
                                }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    type="button"
                                    class="text-sm font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                                    @click="removeHousekeepingStatus(index)"
                                >
                                    Remove
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button
                type="button"
                class="inline-flex w-fit items-center rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="openHousekeepingModal"
            >
                Add
            </button>

            <Modal
                :show="housekeepingModalOpen"
                title="Housekeeping Status Details"
                @close="housekeepingModalOpen = false"
            >
                <div class="flex flex-col gap-4">
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Name</label
                        >
                        <input
                            v-model="newHousekeepingStatus.name"
                            type="text"
                            placeholder="Please enter the name"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Color</label
                        >
                        <input
                            v-model="newHousekeepingStatus.color"
                            type="color"
                            class="block h-10 w-16 rounded-md border border-[#e3e3e0] dark:border-[#3E3E3A]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Abbreviation</label
                        >
                        <input
                            v-model="newHousekeepingStatus.abbreviation"
                            type="text"
                            placeholder="Please enter the abbreviation"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Description</label
                        >
                        <textarea
                            v-model="newHousekeepingStatus.description"
                            rows="3"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Permissions Required By Users To Update Status Of
                            This HK Status
                        </label>
                        <select
                            v-model="newHousekeepingStatus.permission_required"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option
                                v-for="option in housekeepingPermissionOptions"
                                :key="option"
                                :value="option"
                            >
                                {{ option }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <p
                            class="mb-1 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Mark As Default On Following Actions
                        </p>
                        <div class="flex flex-wrap gap-4">
                            <label
                                class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                <input
                                    v-model="
                                        newHousekeepingStatus.default_on_check_in
                                    "
                                    type="checkbox"
                                />
                                Check-In
                            </label>
                            <label
                                class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                <input
                                    v-model="
                                        newHousekeepingStatus.default_on_check_out
                                    "
                                    type="checkbox"
                                />
                                Check-Out
                            </label>
                            <label
                                class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                <input
                                    v-model="
                                        newHousekeepingStatus.default_on_room_change
                                    "
                                    type="checkbox"
                                />
                                Room Change
                            </label>
                        </div>
                    </div>
                    <div>
                        <p
                            class="mb-1 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Additional Details
                        </p>
                        <div class="flex flex-wrap gap-4">
                            <label
                                class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                <input
                                    v-model="
                                        newHousekeepingStatus.available_for_parking_lot
                                    "
                                    type="checkbox"
                                />
                                Available For Parking Lot
                            </label>
                            <label
                                class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                <input
                                    v-model="
                                        newHousekeepingStatus.allow_check_in
                                    "
                                    type="checkbox"
                                />
                                Allow Check-In
                            </label>
                            <label
                                class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                <input
                                    v-model="newHousekeepingStatus.hard_stop"
                                    type="checkbox"
                                />
                                Hard Stop
                            </label>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                            @click="saveHousekeepingStatus"
                        >
                            Save
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                            @click="housekeepingModalOpen = false"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </Modal>
        </div>

        <div v-show="activeTab === 'custom-fields'" class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
                <h2
                    class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Custom Fields
                </h2>
                <button
                    type="button"
                    class="text-sm font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                    @click="addCustomFieldContainer"
                >
                    Add Containers
                </button>
            </div>

            <div
                v-for="(container, index) in customFieldSettings.containers"
                :key="index"
                class="rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
            >
                <div
                    class="mb-4 flex items-center justify-between border-b border-[#e3e3e0] pb-3 dark:border-[#3E3E3A]"
                >
                    <input
                        v-model="container.name"
                        type="text"
                        class="block w-64 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                    <button
                        type="button"
                        class="text-lg font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                        @click="removeCustomFieldContainer(index)"
                    >
                        ✕
                    </button>
                </div>

                <div class="flex flex-col gap-4">
                    <div class="flex items-center justify-between gap-4">
                        <label
                            class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Display Name:</label
                        >
                        <input
                            v-model="container.display_name"
                            type="text"
                            class="block w-80 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <label
                            class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Display Order:</label
                        >
                        <input
                            v-model.number="container.display_order"
                            type="number"
                            class="block w-80 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <label
                        class="flex items-center justify-between gap-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Active:
                        <input v-model="container.active" type="checkbox" />
                    </label>
                    <label
                        class="flex items-center justify-between gap-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Is Selection Mandatory:
                        <input
                            v-model="container.is_selection_mandatory"
                            type="checkbox"
                        />
                    </label>
                    <label
                        class="flex items-center justify-between gap-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Is Visible On Invoice:
                        <input
                            v-model="container.is_visible_on_invoice"
                            type="checkbox"
                        />
                    </label>

                    <div class="flex flex-col gap-2">
                        <div
                            v-for="(
                                item, itemIndex
                            ) in container.segmentation_items"
                            :key="itemIndex"
                            class="flex items-center gap-3"
                        >
                            <input
                                v-model="
                                    container.segmentation_items[itemIndex]
                                "
                                type="text"
                                class="block w-80 rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <button
                                type="button"
                                class="text-sm font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                                @click="
                                    removeSegmentationItem(container, itemIndex)
                                "
                            >
                                Remove
                            </button>
                        </div>
                        <button
                            type="button"
                            class="w-fit text-sm font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                            @click="addSegmentationItem(container)"
                        >
                            Add Segmentation Items
                        </button>
                    </div>
                </div>
            </div>

            <p
                v-if="!customFieldSettings.containers.length"
                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
            >
                No containers added yet.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
            >
                {{ form.processing ? 'Saving...' : 'Save changes' }}
            </button>
            <Link
                :href="admin.bookingSettings.index()"
                class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
            >
                Cancel
            </Link>
        </div>
    </form>
</template>
