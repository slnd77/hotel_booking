<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import type { MetaField, MetaFieldValue } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { defineComponent, h, ref, watch, type PropType } from 'vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    property: { id: number; name: string };
    metaFields: MetaField[];
    metaFieldValues: MetaFieldValue[];
    /** Preview URLs of the uploaded images (null when none). */
    images: { invoice_logo: string | null; digital_signature: string | null };
}>();

// ── Invoice Logo / Digital Signature: uploaded straight away (they print on invoices) ──
type ImageType = 'invoice_logo' | 'digital_signature';

const imageUploading = ref<ImageType | null>(null);
const imageError = ref<Partial<Record<ImageType, string>>>({});

function uploadImage(type: ImageType, event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (!file) {
        return;
    }

    imageError.value = { ...imageError.value, [type]: undefined };
    imageUploading.value = type;
    router.post(
        admin.invoiceSettings.images.store(props.property.id).url,
        { type, image: file },
        {
            forceFormData: true,
            preserveScroll: true,
            // Unsaved edits to the rest of the page stay in the form.
            preserveState: true,
            onError: (errors) =>
                (imageError.value = {
                    ...imageError.value,
                    [type]:
                        errors.image ??
                        errors.type ??
                        'The image could not be uploaded.',
                }),
            onFinish: () => (imageUploading.value = null),
        },
    );
}

function removeImage(type: ImageType): void {
    router.delete(
        admin.invoiceSettings.images.destroy({
            property: props.property.id,
            type,
        }).url,
        { preserveScroll: true, preserveState: true },
    );
}

/** The picker for one image: a preview with Replace / Remove, or an empty drop area. */
const ImagePicker = defineComponent({
    props: {
        type: { type: String as PropType<ImageType>, required: true },
        label: { type: String, required: true },
    },
    setup(pickerProps) {
        return () => {
            const url = props.images[pickerProps.type];
            const busy = imageUploading.value === pickerProps.type;
            const input = h('input', {
                type: 'file',
                accept: 'image/png,image/jpeg,image/webp',
                class: 'hidden',
                onChange: (event: Event) =>
                    uploadImage(pickerProps.type, event),
            });

            return h('div', { class: 'flex w-full max-w-sm flex-col gap-1' }, [
                url
                    ? h(
                          'div',
                          {
                              class: 'flex items-center gap-3 rounded-md border border-[#e3e3e0] p-2 dark:border-[#3E3E3A]',
                          },
                          [
                              h('img', {
                                  src: url,
                                  alt: pickerProps.label,
                                  class: 'max-h-16 max-w-40 object-contain',
                              }),
                              h(
                                  'div',
                                  {
                                      class: 'ml-auto flex flex-col items-end gap-1 text-xs',
                                  },
                                  [
                                      h(
                                          'label',
                                          {
                                              class: 'cursor-pointer font-medium text-[#1a73e8] hover:underline',
                                          },
                                          [
                                              busy ? 'Uploading…' : 'Replace',
                                              input,
                                          ],
                                      ),
                                      h(
                                          'button',
                                          {
                                              type: 'button',
                                              class: 'font-medium text-[#f53003] hover:underline',
                                              onClick: () =>
                                                  removeImage(pickerProps.type),
                                          },
                                          'Remove',
                                      ),
                                  ],
                              ),
                          ],
                      )
                    : h(
                          'label',
                          {
                              class: 'flex cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed border-[#e3e3e0] bg-[#f5f5f4] px-3 py-6 text-sm text-[#706f6c] hover:border-[#f53003] dark:border-[#3E3E3A] dark:bg-[#1D1D1B] dark:text-[#A1A09A]',
                          },
                          [busy ? 'Uploading…' : '🖼 Select Image', input],
                      ),
                h(
                    'p',
                    { class: 'text-xs text-[#706f6c] dark:text-[#A1A09A]' },
                    'PNG, JPG or WebP, up to 2 MB. Prints on invoices.',
                ),
                imageError.value[pickerProps.type]
                    ? h(
                          'p',
                          { class: 'text-xs text-red-600', role: 'alert' },
                          imageError.value[pickerProps.type],
                      )
                    : null,
            ]);
        };
    },
});

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
    form.put(admin.invoiceSettings.update(props.property).url, {
        preserveScroll: true,
        preserveState: true,
    });
}

const activityLogsOpen = ref(false);

// Tracker Numbers
type TrackerFieldKey =
    | 'prefix'
    | 'in_prefix'
    | 'postfix_digits'
    | 'postfix'
    | 'additional_postfix'
    | 'auto_reset';
type TrackerKey =
    | 'invoice'
    | 'receipt'
    | 'proforma'
    | 'refund'
    | 'cancellation'
    | 'credit_note';

type TrackerFields = {
    prefix: string;
    in_prefix: string;
    postfix_digits: number | null;
    postfix: string;
    additional_postfix: string;
    auto_reset: boolean;
};

type TrackerSettings = Record<TrackerKey, TrackerFields>;

function emptyTracker(): TrackerFields {
    return {
        prefix: '',
        in_prefix: '',
        postfix_digits: null,
        postfix: '',
        additional_postfix: '',
        auto_reset: false,
    };
}

const trackerSections: {
    key: TrackerKey;
    title: string;
    fields: TrackerFieldKey[];
}[] = [
    {
        key: 'invoice',
        title: 'Invoice Tracker Number',
        fields: [
            'prefix',
            'in_prefix',
            'postfix_digits',
            'postfix',
            'auto_reset',
        ],
    },
    {
        key: 'receipt',
        title: 'Receipt Tracker Number',
        fields: ['prefix', 'in_prefix', 'postfix', 'additional_postfix'],
    },
    {
        key: 'proforma',
        title: 'Proforma Invoice Tracker Number',
        fields: ['prefix', 'in_prefix', 'postfix'],
    },
    {
        key: 'refund',
        title: 'Refund Tracker Number',
        fields: ['prefix', 'in_prefix', 'postfix'],
    },
    {
        key: 'cancellation',
        title: 'Cancellation Invoice Tracker',
        fields: ['prefix', 'in_prefix', 'postfix_digits', 'postfix'],
    },
    {
        key: 'credit_note',
        title: 'Credit Note Tracker Number',
        fields: ['prefix', 'in_prefix', 'postfix'],
    },
];

const trackerFieldLabels: Record<TrackerFieldKey, string> = {
    prefix: 'Pre-fix No.',
    in_prefix: 'In Prefix No.',
    postfix_digits: 'Post Fix with Digits',
    postfix: 'Post-fix No.',
    additional_postfix: 'Add. Post-fix No.',
    auto_reset: '',
};

const trackerSettings = ref<TrackerSettings>(
    parseOrDefault<TrackerSettings>('invoice_settings_trackers', {
        invoice: emptyTracker(),
        receipt: emptyTracker(),
        proforma: emptyTracker(),
        refund: emptyTracker(),
        cancellation: emptyTracker(),
        credit_note: emptyTracker(),
    }),
);
bindSettings('invoice_settings_trackers', trackerSettings);

trackerSections.forEach((section) => {
    trackerSettings.value[section.key] ??= emptyTracker();
});

const editingTracker = ref<TrackerKey | null>(null);
const trackerForm = ref<TrackerFields>(emptyTracker());

function openEditTracker(key: TrackerKey): void {
    editingTracker.value = key;
    trackerForm.value = { ...trackerSettings.value[key] };
}

function saveTracker(): void {
    if (editingTracker.value) {
        trackerSettings.value[editingTracker.value] = { ...trackerForm.value };
    }

    editingTracker.value = null;
}

// General settings (statutory IDs, display/print settings, billing method, OR templates)
type StatutoryId = { id: number; display_name: string; display_value: string };
type ToggleField = { key: string; label: string; enabled: boolean };
type TextToggleField = {
    key: string;
    label: string;
    value: string;
    enabled: boolean;
};

type GeneralSettings = {
    statutory_ids: StatutoryId[];
    co_registration_label: string;
    print_headers: TextToggleField[];
    print_footer_toggles_a: ToggleField[];
    print_footer_watermarks: TextToggleField[];
    print_footer_toggles_b: ToggleField[];
    enable_address: 'permanent' | 'corporate';
    received_at: 'default_property' | 'selected_property';
    checkin_checkout_preference:
        | 'user_marking_checkout'
        | 'property_checkout_time';
    parent_company: string;
    invoice_template: string;
    invoice_billing_method: string;
    validate_invoice_before_checkout: boolean;
    guest_liability_enabled: boolean;
    guest_liability_text: string;
    billing_method_invoice_type: string;
    or_start_range: string;
    or_end_range: string;
    bir_permit_display_name: string;
    bir_permit_no: string;
    bir_permit_date: string;
    owned_by: string;
    invoice_start_range: string;
    invoice_end_range: string;
    invoice_bir_permit_display_name: string;
    invoice_bir_permit_no: string;
    invoice_bir_permit_date: string;
    invoice_owned_by: string;
};

function defaultPrintHeaders(): TextToggleField[] {
    return [
        {
            key: 'reservation',
            label: 'Reservation',
            value: 'Reservation',
            enabled: true,
        },
        {
            key: 'bill_summary',
            label: 'Bill Summary',
            value: 'Bill Summary',
            enabled: true,
        },
        {
            key: 'room_proforma_invoice',
            label: 'Room Proforma Invoice',
            value: 'Room Proforma Invoice',
            enabled: true,
        },
        {
            key: 'room_invoice',
            label: 'Room Invoice',
            value: 'Room Invoice',
            enabled: true,
        },
        {
            key: 'extras_invoice',
            label: 'Extras Invoice',
            value: 'Order Invoice',
            enabled: true,
        },
        {
            key: 'extras_proforma_invoice',
            label: 'Extras Proforma Invoice',
            value: 'Order Proforma Invoice',
            enabled: true,
        },
        {
            key: 'account_label',
            label: 'Account Label',
            value: '',
            enabled: true,
        },
        {
            key: 'head_office_address',
            label: 'Head Office Address',
            value: '',
            enabled: true,
        },
        {
            key: 'order_invoice',
            label: 'Order Invoice',
            value: 'Order Invoice',
            enabled: true,
        },
        {
            key: 'day_trip_invoice',
            label: 'Day Trip Invoice',
            value: 'Day Outing Invoice',
            enabled: true,
        },
    ];
}

function defaultPrintFooterTogglesA(): ToggleField[] {
    return [
        {
            key: 'enable_disclaimer',
            label: 'Enable Disclaimer',
            enabled: false,
        },
        {
            key: 'checkin_checkout_time',
            label: 'Check In/Out Time',
            enabled: true,
        },
        {
            key: 'invoice_generation_date',
            label: 'Invoice Generation Date',
            enabled: true,
        },
        { key: 'place_of_supply', label: 'Place Of Supply', enabled: true },
        {
            key: 'tnc_guest_agent_company',
            label: 'Enable T&C in Guest/Agent/Company bill summary',
            enabled: false,
        },
        {
            key: 'cancellation_policy_guest_agent_company',
            label: 'Enable Cancellation Policy in Guest/Agent/Company bill summary',
            enabled: false,
        },
        {
            key: 'tnc_room_proforma',
            label: 'Enable T&C in Room Proforma Invoice',
            enabled: false,
        },
        {
            key: 'cancellation_policy_room_proforma',
            label: 'Enable Cancellation Policy in Room Proforma Invoice',
            enabled: false,
        },
        {
            key: 'tnc_reservation_guest',
            label: 'Enable T&C in Reservation Guest',
            enabled: false,
        },
        {
            key: 'cancellation_policy_reservation_guest',
            label: 'Enable Cancellation Policy in Reservation Guest',
            enabled: false,
        },
        {
            key: 'tnc_reservation_internal',
            label: 'Enable T&C in Reservation Internal',
            enabled: false,
        },
        {
            key: 'cancellation_policy_reservation_internal',
            label: 'Enable Cancellation Policy in Reservation Internal',
            enabled: false,
        },
    ];
}

function defaultPrintFooterWatermarks(): TextToggleField[] {
    return [
        {
            key: 'watermark_pos_proforma',
            label: 'Enable watermark in POS Proforma Invoice',
            value: 'PROFORMA INVOICE',
            enabled: true,
        },
        {
            key: 'watermark_room_proforma',
            label: 'Enable watermark in Room Proforma Invoice',
            value: 'PROFORMA INVOICE',
            enabled: true,
        },
        {
            key: 'watermark_extra_proforma',
            label: 'Enable watermark in Extra Proforma Invoice',
            value: 'PROFORMA INVOICE',
            enabled: true,
        },
        {
            key: 'watermark_bill_summary',
            label: 'Enable watermark in Bill Summary',
            value: '',
            enabled: false,
        },
        {
            key: 'watermark_cancelled_or',
            label: 'Enable watermark in Cancelled OR',
            value: '',
            enabled: false,
        },
    ];
}

function defaultPrintFooterTogglesB(): ToggleField[] {
    return [
        {
            key: 'show_total_outlet_amount',
            label: 'Show Total Outlet Amount in Bill Summary',
            enabled: false,
        },
        {
            key: 'show_footer_signature',
            label: 'Show Footer Signature',
            enabled: false,
        },
        {
            key: 'show_sales_executive',
            label: 'Show Sales Executive on Footer',
            enabled: false,
        },
    ];
}

const generalSettings = ref<GeneralSettings>(
    parseOrDefault<GeneralSettings>('invoice_settings_general', {
        statutory_ids: [],
        co_registration_label: 'GSTIN',
        print_headers: defaultPrintHeaders(),
        print_footer_toggles_a: defaultPrintFooterTogglesA(),
        print_footer_watermarks: defaultPrintFooterWatermarks(),
        print_footer_toggles_b: defaultPrintFooterTogglesB(),
        enable_address: 'permanent',
        received_at: 'default_property',
        checkin_checkout_preference: 'user_marking_checkout',
        parent_company: '',
        invoice_template: 'summarized_view',
        invoice_billing_method: 'consolidate_at_billing_preference',
        validate_invoice_before_checkout: true,
        guest_liability_enabled: false,
        guest_liability_text: '',
        billing_method_invoice_type: 'individual_invoices',
        or_start_range: '',
        or_end_range: '',
        bir_permit_display_name: 'Permit to Use (PTU) No.',
        bir_permit_no: '',
        bir_permit_date: '',
        owned_by: '',
        invoice_start_range: '',
        invoice_end_range: '',
        invoice_bir_permit_display_name: 'Permit to Use (PTU) No.',
        invoice_bir_permit_no: '',
        invoice_bir_permit_date: '',
        invoice_owned_by: '',
    }),
);
bindSettings('invoice_settings_general', generalSettings);

const invoiceTemplateOptions = [
    { value: 'summarized_view', label: 'Summarized View' },
    { value: 'detailed_view', label: 'Detailed View' },
];

const invoiceBillingMethodOptions = [
    {
        value: 'consolidate_at_billing_preference',
        label: 'Consolidate at Billing Preference',
    },
    { value: 'individual_invoices', label: 'Individual Invoices' },
];

const billingMethodInvoiceTypeOptions = [
    {
        value: 'individual_invoices',
        label: 'Individual invoices for room and outlets',
    },
    { value: 'consolidated_invoice', label: 'Consolidated invoice' },
];

function addStatutoryId(): void {
    const nextId =
        generalSettings.value.statutory_ids.reduce(
            (max, item) => Math.max(max, item.id),
            0,
        ) + 1;
    generalSettings.value.statutory_ids.push({
        id: nextId,
        display_name: '',
        display_value: '',
    });
}

function removeStatutoryId(item: StatutoryId): void {
    generalSettings.value.statutory_ids =
        generalSettings.value.statutory_ids.filter((row) => row.id !== item.id);
}
</script>

<template>
    <Head :title="`Invoice Settings: ${property.name}`" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Invoice Setting
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
                :href="admin.invoiceSettings.index().url"
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

    <div class="flex max-w-2xl flex-col gap-4">
        <!-- Tracker number cards -->
        <div
            v-for="section in trackerSections"
            :key="section.key"
            class="rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
        >
            <div class="mb-3 flex items-center justify-between">
                <h3
                    class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ section.title }}
                </h3>
                <button
                    type="button"
                    class="text-sm font-medium text-[#f53003] hover:underline dark:text-[#FF4433]"
                    @click="openEditTracker(section.key)"
                >
                    ✎ Edit
                </button>
            </div>
            <dl class="flex flex-col gap-2">
                <template v-for="fieldKey in section.fields" :key="fieldKey">
                    <div
                        v-if="fieldKey === 'auto_reset'"
                        class="flex items-center gap-2"
                    >
                        <input
                            :checked="trackerSettings[section.key].auto_reset"
                            type="checkbox"
                            disabled
                            class="h-4 w-4 rounded border-[#e3e3e0] dark:border-[#3E3E3A]"
                        />
                    </div>
                    <div v-else class="grid grid-cols-[160px_1fr] gap-2">
                        <dt class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                            {{ trackerFieldLabels[fieldKey] }}
                        </dt>
                        <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                            {{ trackerSettings[section.key][fieldKey] || '—' }}
                        </dd>
                    </div>
                </template>
            </dl>
        </div>

        <Modal
            :show="editingTracker !== null"
            :title="
                trackerSections.find((s) => s.key === editingTracker)?.title
            "
            @close="editingTracker = null"
        >
            <div v-if="editingTracker" class="flex flex-col gap-4">
                <template
                    v-for="fieldKey in trackerSections.find(
                        (s) => s.key === editingTracker,
                    )!.fields"
                    :key="fieldKey"
                >
                    <label
                        v-if="fieldKey === 'auto_reset'"
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="trackerForm.auto_reset"
                            type="checkbox"
                            class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                        />
                        Auto reset
                    </label>
                    <div v-else>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >{{ trackerFieldLabels[fieldKey] }}</label
                        >
                        <input
                            v-if="fieldKey === 'postfix_digits'"
                            v-model.number="trackerForm.postfix_digits"
                            type="number"
                            min="0"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <input
                            v-else
                            v-model="trackerForm[fieldKey]"
                            type="text"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                </template>
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="saveTracker"
                    >
                        Save
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="editingTracker = null"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Statutory IDs -->
        <div
            class="flex flex-col gap-3 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
        >
            <div class="flex items-center justify-between">
                <h3
                    class="flex items-center gap-1 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Statutory IDs
                    <span
                        title="Additional statutory identifiers shown on invoices, e.g. GSTIN, PAN."
                        class="cursor-help"
                        >ⓘ</span
                    >
                </h3>
                <button
                    type="button"
                    class="text-sm font-medium text-[#f53003] hover:underline dark:text-[#FF4433]"
                    @click="addStatutoryId"
                >
                    + Add
                </button>
            </div>
            <div
                v-for="item in generalSettings.statutory_ids"
                :key="item.id"
                class="flex flex-wrap items-center gap-3 rounded-md bg-[#f5f5f4] p-3 dark:bg-[#1D1D1B]"
            >
                <label
                    class="flex items-center gap-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Display Name
                    <input
                        v-model="item.display_name"
                        type="text"
                        class="block w-40 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>
                <label
                    class="flex items-center gap-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Display Value
                    <input
                        v-model="item.display_value"
                        type="text"
                        class="block w-40 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>
                <button
                    type="button"
                    class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                    @click="removeStatutoryId(item)"
                >
                    ✕
                </button>
            </div>
        </div>

        <!-- Display names -->
        <div
            class="flex flex-col gap-3 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
        >
            <h3
                class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Display names
            </h3>
            <label
                class="grid grid-cols-[160px_1fr] items-center gap-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
            >
                Co. Registraion No.
                <input
                    v-model="generalSettings.co_registration_label"
                    type="text"
                    class="block w-48 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </label>
        </div>

        <!-- Print Headers -->
        <div
            class="flex flex-col gap-3 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
        >
            <h3
                class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Print Headers
            </h3>
            <div
                v-for="item in generalSettings.print_headers"
                :key="item.key"
                class="grid grid-cols-[160px_1fr_28px] items-center gap-3"
            >
                <label class="text-sm text-[#706f6c] dark:text-[#A1A09A]">{{
                    item.label
                }}</label>
                <input
                    v-model="item.value"
                    type="text"
                    class="block w-full max-w-sm rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
                <input
                    v-model="item.enabled"
                    type="checkbox"
                    class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                />
            </div>
        </div>

        <!-- Print Footers -->
        <div
            class="flex flex-col gap-3 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
        >
            <h3
                class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Print Footers
            </h3>

            <label
                v-for="item in generalSettings.print_footer_toggles_a"
                :key="item.key"
                class="grid grid-cols-[1fr_28px] items-center gap-3"
            >
                <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]">{{
                    item.label
                }}</span>
                <input
                    v-model="item.enabled"
                    type="checkbox"
                    class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                />
            </label>

            <div
                v-for="item in generalSettings.print_footer_watermarks"
                :key="item.key"
                class="grid grid-cols-[220px_1fr_28px_20px] items-center gap-3"
            >
                <label class="text-sm text-[#706f6c] dark:text-[#A1A09A]">{{
                    item.label
                }}</label>
                <input
                    v-model="item.value"
                    type="text"
                    class="block w-full max-w-sm rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
                <input
                    v-model="item.enabled"
                    type="checkbox"
                    class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                />
                <span
                    title="Watermark shown on this document type."
                    class="cursor-help text-[#706f6c] dark:text-[#A1A09A]"
                    >ⓘ</span
                >
            </div>

            <label
                v-for="item in generalSettings.print_footer_toggles_b"
                :key="item.key"
                class="grid grid-cols-[1fr_28px] items-center gap-3"
            >
                <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]">{{
                    item.label
                }}</span>
                <input
                    v-model="item.enabled"
                    type="checkbox"
                    class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                />
            </label>

            <div class="grid grid-cols-[160px_1fr] items-center gap-3">
                <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >Enable Address</span
                >
                <div class="flex items-center gap-4">
                    <label
                        class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="generalSettings.enable_address"
                            type="radio"
                            value="permanent"
                            class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                        />
                        Permanent
                    </label>
                    <label
                        class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="generalSettings.enable_address"
                            type="radio"
                            value="corporate"
                            class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                        />
                        Corporate
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-[160px_1fr] items-center gap-3">
                <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >Received At</span
                >
                <div class="flex items-center gap-4">
                    <label
                        class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="generalSettings.received_at"
                            type="radio"
                            value="default_property"
                            class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                        />
                        Default Property
                    </label>
                    <label
                        class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="generalSettings.received_at"
                            type="radio"
                            value="selected_property"
                            class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                        />
                        Selected Property
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-[160px_1fr] items-center gap-3">
                <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >Checkin/Checkout Preference</span
                >
                <div class="flex items-center gap-4">
                    <label
                        class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="
                                generalSettings.checkin_checkout_preference
                            "
                            type="radio"
                            value="user_marking_checkout"
                            class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                        />
                        User Marking Checkout
                    </label>
                    <label
                        class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="
                                generalSettings.checkin_checkout_preference
                            "
                            type="radio"
                            value="property_checkout_time"
                            class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                        />
                        Property Check Out Time
                    </label>
                </div>
            </div>

            <label class="grid grid-cols-[160px_1fr] items-center gap-3">
                <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >Parent Company</span
                >
                <input
                    v-model="generalSettings.parent_company"
                    type="text"
                    class="block w-full max-w-sm rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </label>

            <label class="grid grid-cols-[160px_1fr] items-center gap-3">
                <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >Invoice Template</span
                >
                <select
                    v-model="generalSettings.invoice_template"
                    class="block w-64 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                >
                    <option
                        v-for="option in invoiceTemplateOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </label>

            <div class="grid grid-cols-[160px_1fr_20px] items-center gap-3">
                <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >Invoice Billing Methods</span
                >
                <select
                    v-model="generalSettings.invoice_billing_method"
                    class="block w-64 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                >
                    <option
                        v-for="option in invoiceBillingMethodOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
                <span
                    title="How invoices are grouped for billing."
                    class="cursor-help text-[#706f6c] dark:text-[#A1A09A]"
                    >ⓘ</span
                >
            </div>

            <div class="grid grid-cols-[1fr_28px_20px] items-center gap-3">
                <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >Validate Invoice Before Checkout</span
                >
                <input
                    v-model="generalSettings.validate_invoice_before_checkout"
                    type="checkbox"
                    class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                />
                <span
                    title="Requires invoices to be validated before the guest can check out."
                    class="cursor-help text-[#706f6c] dark:text-[#A1A09A]"
                    >ⓘ</span
                >
            </div>

            <div class="grid grid-cols-[160px_1fr] items-center gap-3">
                <span
                    class="flex items-center gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Digital Signature
                    <span
                        title="Upload a digital signature image to print on invoices."
                        >ⓘ</span
                    >
                </span>
                <ImagePicker
                    type="digital_signature"
                    label="Digital signature"
                />
            </div>

            <div class="grid grid-cols-[160px_1fr] items-start gap-3">
                <label
                    class="flex items-center gap-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    <input
                        v-model="generalSettings.guest_liability_enabled"
                        type="checkbox"
                        class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                    />
                    Guest Liability
                </label>
                <textarea
                    v-model="generalSettings.guest_liability_text"
                    rows="3"
                    class="block w-full max-w-sm rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>

            <div class="grid grid-cols-[160px_1fr] items-center gap-3">
                <span
                    class="flex items-center gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Invoice Logo
                    <span title="Upload a logo image to print on invoices."
                        >ⓘ</span
                    >
                </span>
                <ImagePicker type="invoice_logo" label="Invoice logo" />
            </div>
        </div>

        <!-- Billing Method -->
        <div
            class="flex flex-col gap-3 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
        >
            <h3
                class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Billing Method
            </h3>
            <label class="grid grid-cols-[160px_1fr] items-center gap-3">
                <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >Invoice Type</span
                >
                <select
                    v-model="generalSettings.billing_method_invoice_type"
                    class="block w-64 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                >
                    <option
                        v-for="option in billingMethodInvoiceTypeOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </label>
        </div>

        <!-- Invoice / OR Template Setting -->
        <div
            class="flex flex-col gap-3 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
        >
            <h3
                class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Invoice / OR Template Setting
            </h3>

            <label
                v-for="field in [
                    { model: 'or_start_range', label: 'OR Start Range' },
                    { model: 'or_end_range', label: 'OR End Range' },
                    {
                        model: 'bir_permit_display_name',
                        label: 'BIR Permit Display Name',
                    },
                    { model: 'bir_permit_no', label: 'BIR Permit No' },
                    { model: 'bir_permit_date', label: 'BIR Permit Date' },
                    { model: 'owned_by', label: 'Owned By:' },
                    {
                        model: 'invoice_start_range',
                        label: 'Invoice Start Range',
                    },
                    { model: 'invoice_end_range', label: 'Invoice End Range' },
                    {
                        model: 'invoice_bir_permit_display_name',
                        label: 'Invoice BIR Permit Display Name',
                    },
                    {
                        model: 'invoice_bir_permit_no',
                        label: 'Invoice BIR Permit No',
                    },
                    {
                        model: 'invoice_bir_permit_date',
                        label: 'Invoice BIR Permit Date',
                    },
                    { model: 'invoice_owned_by', label: 'Invoice Owned By:' },
                ] as const"
                :key="field.model"
                class="grid grid-cols-[220px_1fr] items-center gap-3"
            >
                <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]">{{
                    field.label
                }}</span>
                <input
                    v-model="generalSettings[field.model]"
                    type="text"
                    class="block w-full max-w-sm rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </label>
        </div>

        <div
            class="flex items-center gap-3 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
        >
            <button
                type="button"
                :disabled="form.processing"
                class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                @click="submit"
            >
                {{ form.processing ? 'Saving...' : 'Save' }}
            </button>
            <Link
                :href="admin.invoiceSettings.index()"
                class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
            >
                Cancel
            </Link>
        </div>
    </div>
</template>
