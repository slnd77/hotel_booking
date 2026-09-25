<script setup lang="ts">
import ActivityLogsModal, {
    type ActivityLogRow,
} from '@/components/ActivityLogsModal.vue';
import HolidayCalendar from '@/components/HolidayCalendar.vue';
import Modal from '@/components/Modal.vue';
import PropertyAddonsForm, {
    type PropertyAddon,
} from '@/components/PropertyAddonsForm.vue';
import PropertyBrandingForm, {
    type PropertyBrandingSettings,
} from '@/components/PropertyBrandingForm.vue';
import PropertyStayTimeChargesForm, {
    type StayTimeChargeSettings,
} from '@/components/PropertyStayTimeChargesForm.vue';
import PropertyWebCheckinForm, {
    type WebCheckinSettings,
} from '@/components/PropertyWebCheckinForm.vue';
import QuillEditor from '@/components/QuillEditor.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { Holiday } from '@/lib/holidays';
import { useLocationCascade } from '@/lib/useLocationCascade';
import { useTimeZoneOptions } from '@/lib/usePropertyTime';
import { useUrlTab } from '@/lib/useUrlTab';
import admin from '@/routes/admin';
import type {
    AmenityCategoryGroup,
    CancellationRule,
    CancellationRuleTier,
    FormFieldOption,
    MealPlanRow,
    MetaField,
    Option,
    Property,
    PropertyGroup,
    Room,
    RoomCategory,
    RoomCategoryAmenityLink,
} from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch, type Ref } from 'vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    property: Property;
    propertyGroups: PropertyGroup[];
    countries: Option[];
    currencies: Option[];
    languages: Option[];
    supportedThirdPartyAnalytics: Option[];
    supportedThirdPartyAdTrackings: Option[];
    supportedMetasearchPlatforms: Option[];
    starRatingOptions: FormFieldOption[];
    propertySegmentOptions: FormFieldOption[];
    propertyTypeOptions: FormFieldOption[];
    taxTypeOptions: FormFieldOption[];
    mealPlanCatalog: MealPlanRow[];
    bedTypeCatalog: FormFieldOption[];
    bookingTypeCatalog: FormFieldOption[];
    metaFields: MetaField[];
    propertyAmenityCategories: AmenityCategoryGroup[];
    roomCategoryAmenityCategories: AmenityCategoryGroup[];
    roomCategoryAmenities: RoomCategoryAmenityLink[];
    roomCategoryLogs: {
        id: number;
        action: string;
        user: string | null;
        date: string | null;
    }[];
    upsellCategoryIds: number[];
    branding: PropertyBrandingSettings;
    brandingLogs: ActivityLogRow[];
    addons: PropertyAddon[];
    addonPricing: { value: string; label: string }[];
    webCheckin: WebCheckinSettings;
    stayTimeCharges: StayTimeChargeSettings;
    standardStayTimes: { check_in: string; check_out: string };
    policyLogs: ActivityLogRow[];
    /** Today in the property's timezone (Y-m-d). */
    propertyToday: string;
}>();

const policyMetaFieldKeys = [
    'checkin_checkout_time',
    'weekend_holidays',
    'card_types',
    'checkin_checkout_policy',
    'payment_policy',
    'house_rules',
] as const;

const policyRichTextFields = [
    { key: 'checkin_checkout_policy', label: 'Check-In/Check-Out Policy' },
    { key: 'payment_policy', label: 'Payment Policy' },
    { key: 'house_rules', label: 'House Rules' },
] as const;

const WEEKDAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const CARD_TYPES = [
    'Visa',
    'Mastercard',
    'American Express',
    'RuPay',
    'Diners Club',
    'Discover',
    'Maestro',
    'JCB',
];

const termsInclusionMetaFieldKeys = [
    'complementary_inclusions',
    'terms_and_conditions_while_booking',
    'terms_and_conditions_while_check_in',
] as const;

const termsInclusionMetaFields = computed(() => {
    const byKey = new Map(props.metaFields.map((field) => [field.key, field]));
    return termsInclusionMetaFieldKeys
        .map((key) => byKey.get(key))
        .filter((field): field is MetaField => field !== undefined);
});
const mealPlanField = computed(() =>
    props.metaFields.find((field) => field.key === 'meal_plans'),
);
const paymentTermsField = computed(() =>
    props.metaFields.find((field) => field.key === 'payment_terms'),
);
const grcSettingsField = computed(() =>
    props.metaFields.find((field) => field.key === 'GRC_settings'),
);

function policyField(key: string) {
    return props.metaFields.find((field) => field.key === key);
}

const address = useLocationCascade();
const corporateAddress = useLocationCascade();

const analyticsValues: Record<number, string> = {};
(props.property.third_party_analytics ?? []).forEach((provider) => {
    analyticsValues[provider.id] = provider.pivot?.tracking_id ?? '';
});

const adTrackingValues: Record<number, string> = {};
(props.property.third_party_ad_tracking ?? []).forEach((provider) => {
    adTrackingValues[provider.id] = provider.pivot?.tracking_id ?? '';
});

const metasearchPlatformValues: Record<number, string> = {};
(props.property.metasearch_platforms ?? []).forEach((platform) => {
    metasearchPlatformValues[platform.id] = platform.pivot?.listing_id ?? '';
});

const metaFieldValues: Record<number, { enabled: boolean; data: string }> = {};
props.metaFields.forEach((field) => {
    metaFieldValues[field.id] = { enabled: false, data: '' };
});
(props.property.meta_field_values ?? []).forEach((value) => {
    metaFieldValues[value.meta_fields_key_id] = {
        enabled: value.enabled,
        data: value.data ?? '',
    };
});

const timeZones = useTimeZoneOptions(props.property.timezone);

const form = useForm({
    name: props.property.name,
    property_legal_name: props.property.property_legal_name,
    property_group_id: props.property.property_group_id,
    abbreviation: props.property.abbreviation,
    property_type: props.property.property_type,

    third_party_analytics_enabled: props.property.third_party_analytics_enabled,
    third_party_ad_tracking_enabled:
        props.property.third_party_ad_tracking_enabled,
    third_party_analytics: analyticsValues,
    third_party_ad_tracking: adTrackingValues,
    metasearch_platforms: metasearchPlatformValues,
    meta_fields: metaFieldValues,

    property_contact_number: props.property.property_contact_number ?? '',
    outlet_sac_code_enabled: props.property.outlet_sac_code_enabled,

    address_line_1: props.property.address_line_1,
    address_line_2: props.property.address_line_2 ?? '',
    postal_code: props.property.postal_code,
    country_id: props.property.country_id as number | string,
    state_id: props.property.state_id as number | string,
    city_id: props.property.city_id as number | string,

    corporate_address_permanent_address_same:
        props.property.corporate_address_permanent_address_same,
    corporate_address_line_1: props.property.corporate_address_line_1,
    corporate_address_line_2: props.property.corporate_address_line_2 ?? '',
    corporate_postal_code: props.property.corporate_postal_code,
    corporate_country_id: props.property.corporate_country_id as
        | number
        | string,
    corporate_state_id: props.property.corporate_state_id as number | string,
    corporate_city_id: props.property.corporate_city_id as number | string,

    currency_id: props.property.currency_id,
    timezone: props.property.timezone ?? 'Asia/Kolkata',
    ignore_decimal_places_in_bill_settlement:
        props.property.ignore_decimal_places_in_bill_settlement,
    checkout_tolerance_value: props.property.checkout_tolerance_value,

    language: props.property.language,

    location_map_url: props.property.location_map_url ?? '',
    latitude: props.property.latitude ?? '',
    longitude: props.property.longitude ?? '',
    property_segment: props.property.property_segment,

    star_rating: props.property.star_rating,
    tax_type: props.property.tax_type,
    metasearch_engines_listings_enabled:
        props.property.metasearch_engines_listings_enabled,

    'customise_name_for+room_invoice':
        props.property['customise_name_for+room_invoice'] ?? '',
});

onMounted(async () => {
    await address.loadStates(form.country_id);
    await address.loadCities(form.state_id);
    await corporateAddress.loadStates(form.corporate_country_id);
    await corporateAddress.loadCities(form.corporate_state_id);
});

watch(
    () => form.country_id,
    (countryId) => {
        form.state_id = '';
        form.city_id = '';
        address.loadStates(countryId);
    },
);

watch(
    () => form.state_id,
    (stateId) => {
        form.city_id = '';
        address.loadCities(stateId);
    },
);

watch(
    () => form.corporate_country_id,
    (countryId) => {
        form.corporate_state_id = '';
        form.corporate_city_id = '';
        corporateAddress.loadStates(countryId);
    },
);

watch(
    () => form.corporate_state_id,
    (stateId) => {
        form.corporate_city_id = '';
        corporateAddress.loadCities(stateId);
    },
);

watch(
    () => form.corporate_address_permanent_address_same,
    (same) => {
        if (!same) {
            return;
        }

        form.corporate_address_line_1 = form.address_line_1;
        form.corporate_address_line_2 = form.address_line_2;
        form.corporate_postal_code = form.postal_code;
        form.corporate_country_id = form.country_id;
        form.corporate_state_id = form.state_id;
        form.corporate_city_id = form.city_id;
    },
);

function submit(): void {
    form.put(admin.properties.update(props.property).url, {
        preserveScroll: true,
        preserveState: true,
    });
}

function metaFieldDataError(fieldId: number): string | undefined {
    return (form.errors as Record<string, string>)[
        `meta_fields.${fieldId}.data`
    ];
}

const propertyEditTabs = [
    'details',
    'terms-inclusions',
    'cancellation-rules',
    'meal-plans',
    'rooms-setup',
    'property-amenities',
    'branding',
    'policies',
    'payment-terms',
    'grc-settings',
    'add-ons',
    'web-check-in',
    'early-late-charges',
] as const;

const activeTab = useUrlTab(propertyEditTabs, 'details');

const policyLogsOpen = ref(false);

function emptyRuleTiers(): CancellationRuleTier[] {
    return [{ day_from_checkin: 9999999, penalty_percent: 0 }];
}

const cancellationRulePanelOpen = ref(false);
const editingCancellationRule = ref<CancellationRule | null>(null);

const cancellationRuleForm = useForm({
    start_time_stamp: '',
    end_time_stamp: '',
    rules: emptyRuleTiers(),
});

function addRuleTier(): void {
    const lastIndex = cancellationRuleForm.rules.length - 1;
    cancellationRuleForm.rules.splice(Math.max(0, lastIndex), 0, {
        day_from_checkin: 0,
        penalty_percent: 0,
    });
}

function removeRuleTier(index: number): void {
    if (index < cancellationRuleForm.rules.length - 1) {
        cancellationRuleForm.rules.splice(index, 1);
    }
}

function openAddCancellationRule(): void {
    editingCancellationRule.value = null;
    cancellationRuleForm.clearErrors();
    cancellationRuleForm.start_time_stamp = '';
    cancellationRuleForm.end_time_stamp = '';
    cancellationRuleForm.rules = emptyRuleTiers();
    cancellationRulePanelOpen.value = true;
}

function openEditCancellationRule(rule: CancellationRule): void {
    editingCancellationRule.value = rule;
    cancellationRuleForm.clearErrors();
    cancellationRuleForm.start_time_stamp = rule.start_time_stamp.slice(0, 16);
    cancellationRuleForm.end_time_stamp = rule.end_time_stamp.slice(0, 16);
    const tiers = (rule.rules ?? []).map((tier) => ({ ...tier }));
    if (tiers.length === 0) {
        tiers.push({ day_from_checkin: 9999999, penalty_percent: 0 });
    } else {
        tiers[tiers.length - 1].day_from_checkin = 9999999;
    }
    cancellationRuleForm.rules = tiers;
    cancellationRulePanelOpen.value = true;
}

/**
 * A cancellation rule's "2026-09-01 20:10:00" as "01/09/2026 20:10", read from the string itself:
 * the same text on the server and in the browser, whatever locale each formats dates in.
 */
function ruleTimestamp(value: string): string {
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);

    return match
        ? `${match[3]}/${match[2]}/${match[1]} ${match[4]}:${match[5]}`
        : value;
}

function closeCancellationRulePanel(): void {
    cancellationRulePanelOpen.value = false;
    editingCancellationRule.value = null;
}

const cancellationDateOverlapError = computed<string | null>(() => {
    const start = cancellationRuleForm.start_time_stamp;
    const end = cancellationRuleForm.end_time_stamp;
    if (!start || !end) return null;

    const startTime = new Date(start).getTime();
    const endTime = new Date(end).getTime();

    if (isNaN(startTime) || isNaN(endTime) || startTime >= endTime) {
        return null;
    }

    const rules = props.property.cancellation_rules ?? [];
    const currentId = editingCancellationRule.value?.id;

    for (let i = 0; i < rules.length; i++) {
        const rule = rules[i];
        if (currentId && rule.id === currentId) {
            continue;
        }

        const ruleStart = new Date(rule.start_time_stamp).getTime();
        const ruleEnd = new Date(rule.end_time_stamp).getTime();

        if (startTime <= ruleEnd && endTime >= ruleStart) {
            return `The selected dates overlap with existing Rule #${i + 1} (${ruleTimestamp(rule.start_time_stamp)} to ${ruleTimestamp(rule.end_time_stamp)}).`;
        }
    }

    return null;
});

function submitCancellationRule(): void {
    if (cancellationDateOverlapError.value) {
        cancellationRuleForm.setError(
            'start_time_stamp',
            cancellationDateOverlapError.value,
        );
        return;
    }

    if (cancellationRuleForm.rules.length > 0) {
        cancellationRuleForm.rules[
            cancellationRuleForm.rules.length - 1
        ].day_from_checkin = 9999999;
    }

    if (editingCancellationRule.value) {
        cancellationRuleForm.put(
            admin.properties.cancellationRules.update({
                property: props.property,
                cancellationRule: editingCancellationRule.value,
            }).url,
            { onSuccess: () => closeCancellationRulePanel() },
        );
    } else {
        cancellationRuleForm.post(
            admin.properties.cancellationRules.store(props.property).url,
            {
                onSuccess: () => closeCancellationRulePanel(),
            },
        );
    }
}

function initialMealPlanRows(): MealPlanRow[] {
    const field = mealPlanField.value;
    const rawData = field ? form.meta_fields[field.id]?.data : '';

    if (rawData) {
        try {
            const parsed = JSON.parse(rawData) as MealPlanRow[];

            if (Array.isArray(parsed) && parsed.length) {
                return parsed;
            }
        } catch {
            // fall through to the default catalog below
        }
    }

    return props.mealPlanCatalog.map((row) => ({ ...row }));
}

const mealPlanRows = ref<MealPlanRow[]>(initialMealPlanRows());

if (mealPlanField.value) {
    form.meta_fields[mealPlanField.value.id].enabled = true;
}

policyMetaFieldKeys.forEach((key) => {
    const field = policyField(key);

    if (field) {
        form.meta_fields[field.id].enabled = true;
    }
});

if (paymentTermsField.value) {
    form.meta_fields[paymentTermsField.value.id].enabled = true;
}

type PaymentTermEntry = {
    booking_type: string;
    enable_terms_on_invoice: boolean;
    enable_terms_on_proforma_invoice: boolean;
    enable_terms_on_guest_bill_summary: boolean;
    enable_terms_on_company_bill_summary: boolean;
    content: string;
};

function initialPaymentTermEntries(): PaymentTermEntry[] {
    const field = paymentTermsField.value;
    const rawData = field ? form.meta_fields[field.id]?.data : '';

    if (rawData) {
        try {
            const parsed = JSON.parse(rawData) as PaymentTermEntry[];

            if (Array.isArray(parsed)) {
                return parsed;
            }
        } catch {
            // fall through to an empty list below
        }
    }

    return [];
}

const paymentTermEntries = ref<PaymentTermEntry[]>(initialPaymentTermEntries());
const selectedBookingType = ref<string | null>(
    paymentTermEntries.value[0]?.booking_type ?? null,
);
const paymentTermModalOpen = ref(false);
const newBookingTypeForm = useForm({
    booking_type: '',
});

const unusedBookingTypes = computed(() =>
    props.bookingTypeCatalog.filter(
        (option) =>
            !paymentTermEntries.value.some(
                (entry) => entry.booking_type === option.label,
            ),
    ),
);

const selectedPaymentTermEntry = computed(
    () =>
        paymentTermEntries.value.find(
            (entry) => entry.booking_type === selectedBookingType.value,
        ) ?? null,
);

watch(
    paymentTermEntries,
    (entries) => {
        if (paymentTermsField.value) {
            form.meta_fields[paymentTermsField.value.id].data =
                JSON.stringify(entries);
        }
    },
    { deep: true, immediate: true },
);

function openPaymentTermModal(): void {
    newBookingTypeForm.reset();
    newBookingTypeForm.clearErrors();
    paymentTermModalOpen.value = true;
}

function addBookingType(): void {
    if (!newBookingTypeForm.booking_type) {
        return;
    }

    paymentTermEntries.value.push({
        booking_type: newBookingTypeForm.booking_type,
        enable_terms_on_invoice: false,
        enable_terms_on_proforma_invoice: false,
        enable_terms_on_guest_bill_summary: false,
        enable_terms_on_company_bill_summary: false,
        content: '',
    });
    selectedBookingType.value = newBookingTypeForm.booking_type;
    paymentTermModalOpen.value = false;
}

watch(
    mealPlanRows,
    (rows) => {
        if (mealPlanField.value) {
            form.meta_fields[mealPlanField.value.id].data =
                JSON.stringify(rows);
        }
    },
    { deep: true, immediate: true },
);

const grcFieldNames = [
    'Name',
    'Date of Birth',
    'Email',
    'Place of Birth',
    'Mobile',
    'Gender',
    'Nationality',
    'Present Address',
    'Permanent Address',
    'Reference Address',
    'ID Type',
    'ID Number',
    'ID Issued At',
    'ID Issued Date',
    'ID Expiry',
    'Passport Number',
    'Passport Issue Date',
    'Passport Issue Place',
    'Visa Number',
    'Visa Type',
    'Visa Issue Date',
    'Visa Valid Till Date',
    'Visa Issue Place',
    'Purpose of Visit',
    'Arrival Date',
    'Arrival Time',
    'Departure Date',
    'Departure Time',
    'Nights',
    'PAX',
    'Room',
    'Rate Type',
    'Booking Source',
    'Room Number',
    'Reason to Visit',
    'Arrived From',
    'Date and Time of Arrival in Country',
    'Going To',
    'Vehicle Number',
    'Notes',
    'Terms & Conditions',
    'House Rules',
];

type GrcSettings = {
    fields: { name: string; enabled: boolean }[];
    print_header: string;
};

function initialGrcSettings(): GrcSettings {
    const field = grcSettingsField.value;
    const rawData = field ? form.meta_fields[field.id]?.data : '';

    if (rawData) {
        try {
            const parsed = JSON.parse(rawData) as GrcSettings;

            if (parsed && Array.isArray(parsed.fields)) {
                return {
                    fields: grcFieldNames.map((name) => {
                        const match = parsed.fields.find(
                            (field) => field.name === name,
                        );

                        return { name, enabled: match?.enabled ?? true };
                    }),
                    print_header:
                        parsed.print_header ?? 'Guest Reservation Card',
                };
            }
        } catch {
            // fall through to the defaults below
        }
    }

    return {
        fields: grcFieldNames.map((name) => ({ name, enabled: true })),
        print_header: 'Guest Reservation Card',
    };
}

const grcSettings = ref<GrcSettings>(initialGrcSettings());

if (grcSettingsField.value) {
    form.meta_fields[grcSettingsField.value.id].enabled = true;
}

watch(
    grcSettings,
    (settings) => {
        if (grcSettingsField.value) {
            form.meta_fields[grcSettingsField.value.id].data =
                JSON.stringify(settings);
        }
    },
    { deep: true, immediate: true },
);

type CheckinCheckoutTime = {
    check_in_hour: number;
    check_in_minute: number;
    check_out_hour: number;
    check_out_minute: number;
};

type WeekendHolidays = {
    /** 0 = Monday … 6 = Sunday. */
    weekend_days: number[];
    holidays_enabled: boolean;
    holidays: Holiday[];
};

type CardTypes = { accepted: string[] };

/** A structured policy's saved JSON over its defaults (older free-text values fall back). */
function initialPolicy<T extends object>(key: string, defaults: T): T {
    const field = policyField(key);
    const rawData = field ? form.meta_fields[field.id]?.data : '';

    if (rawData) {
        try {
            const parsed = JSON.parse(rawData);

            if (
                parsed &&
                typeof parsed === 'object' &&
                !Array.isArray(parsed)
            ) {
                return { ...defaults, ...parsed };
            }
        } catch {
            // fall through to the defaults below
        }
    }

    return defaults;
}

function bindPolicy<T>(key: string, state: Ref<T>): void {
    watch(
        state,
        (value) => {
            const field = policyField(key);

            if (field) {
                form.meta_fields[field.id].data = JSON.stringify(value);
            }
        },
        { deep: true, immediate: true },
    );
}

const checkinCheckoutTime = ref<CheckinCheckoutTime>(
    initialPolicy('checkin_checkout_time', {
        check_in_hour: 12,
        check_in_minute: 0,
        check_out_hour: 11,
        check_out_minute: 0,
    }),
);
const weekendHolidays = ref<WeekendHolidays>(
    initialPolicy('weekend_holidays', {
        weekend_days: [5, 6],
        holidays_enabled: false,
        holidays: [],
    }),
);
const cardTypes = ref<CardTypes>(
    initialPolicy('card_types', { accepted: [] as string[] }),
);

bindPolicy('checkin_checkout_time', checkinCheckoutTime);
bindPolicy('weekend_holidays', weekendHolidays);
bindPolicy('card_types', cardTypes);

const roomCategoryForm = useForm({
    name: '',
});

const roomCategoryModalOpen = ref(false);

function openRoomCategoryModal(): void {
    roomCategoryForm.reset();
    roomCategoryForm.clearErrors();
    roomCategoryModalOpen.value = true;
}

function submitRoomCategory(): void {
    roomCategoryForm.post(
        admin.properties.roomCategories.store(props.property).url,
        {
            onSuccess: () => {
                roomCategoryForm.reset();
                roomCategoryModalOpen.value = false;
            },
        },
    );
}

const editingRoomCategory = ref<RoomCategory | null>(null);
const roomCategoryDetailTab = ref<
    | 'category-details'
    | 'rooms-under-category'
    | 'images'
    | 'category-amenities'
    | 'bed-type'
    | 'extra-bed-type'
>('category-details');

const currentRoomCategory = computed(() => {
    if (!editingRoomCategory.value) {
        return null;
    }

    return (
        props.property.room_categories?.find(
            (roomCategory) => roomCategory.id === editingRoomCategory.value?.id,
        ) ?? editingRoomCategory.value
    );
});

const roomCategoryDetailForm = useForm({
    name: '',
    description: '',
    abbreviation: '',
    integration_code: '',
    inclusion_details: '',
    base_occupancy: 1,
    ideal_occupancy: 1,
    max_adults: 1,
    max_children: 0,
    default_number_of_children: 0,
    total_number_of_guests: 1,
    sync_category_on_ota: true,
    exclude_from_revenue: false,
    exclude_from_occupancy: false,
    exclude_from_upgrade: false,
    sell_by_bed: false,
    meal_plans: [] as string[],
});

function openEditRoomCategory(roomCategory: RoomCategory): void {
    editingRoomCategory.value = roomCategory;
    roomCategoryDetailTab.value = 'category-details';
    roomCategoryDetailForm.clearErrors();
    roomCategoryDetailForm.name = roomCategory.name;
    roomCategoryDetailForm.description = roomCategory.description ?? '';
    roomCategoryDetailForm.abbreviation = roomCategory.abbreviation ?? '';
    roomCategoryDetailForm.integration_code =
        roomCategory.integration_code ?? '';
    roomCategoryDetailForm.inclusion_details =
        roomCategory.inclusion_details ?? '';
    roomCategoryDetailForm.base_occupancy = roomCategory.base_occupancy;
    roomCategoryDetailForm.ideal_occupancy = roomCategory.ideal_occupancy;
    roomCategoryDetailForm.max_adults = roomCategory.max_adults;
    roomCategoryDetailForm.max_children = roomCategory.max_children;
    roomCategoryDetailForm.default_number_of_children =
        roomCategory.default_number_of_children;
    roomCategoryDetailForm.total_number_of_guests =
        roomCategory.total_number_of_guests;
    roomCategoryDetailForm.sync_category_on_ota =
        roomCategory.sync_category_on_ota;
    roomCategoryDetailForm.exclude_from_revenue =
        roomCategory.exclude_from_revenue;
    roomCategoryDetailForm.exclude_from_occupancy =
        roomCategory.exclude_from_occupancy;
    roomCategoryDetailForm.exclude_from_upgrade =
        roomCategory.exclude_from_upgrade;
    roomCategoryDetailForm.sell_by_bed = roomCategory.sell_by_bed ?? false;
    roomCategoryDetailForm.meal_plans = roomCategory.meal_plans ?? [];
}

function closeRoomCategoryDetail(): void {
    editingRoomCategory.value = null;
}

function submitRoomCategoryDetail(): void {
    if (!editingRoomCategory.value) {
        return;
    }

    roomCategoryDetailForm.put(
        admin.properties.roomCategories.update({
            property: props.property,
            roomCategory: editingRoomCategory.value,
        }).url,
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => closeRoomCategoryDetail(),
        },
    );
}

function confirmRoomCategoryDelete(event: MouseEvent): void {
    if (!confirm('Delete this room category?')) {
        event.preventDefault();
    }
}

const roomForm = useForm({
    name: '',
    // Dormitory categories: how many beds the room has.
    bed_count: 6,
});

const roomModalOpen = ref(false);

function openRoomModal(): void {
    roomForm.reset();
    roomForm.clearErrors();
    roomModalOpen.value = true;
}

function submitRoom(): void {
    if (!editingRoomCategory.value) {
        return;
    }

    roomForm.post(
        admin.properties.roomCategories.rooms.store({
            property: props.property,
            roomCategory: editingRoomCategory.value,
        }).url,
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                roomForm.reset();
                roomModalOpen.value = false;
            },
        },
    );
}

const editingRoomId = ref<number | null>(null);
const editingRoomName = ref('');
const editingRoomBeds = ref(1);
const page = usePage();
const roomErrors = computed(
    () => page.props.errors as Record<string, string | undefined>,
);

function startEditRoom(room: Room): void {
    editingRoomId.value = room.id;
    editingRoomName.value = room.name;
    editingRoomBeds.value = room.bed_count ?? 1;
}

function cancelEditRoom(): void {
    editingRoomId.value = null;
    editingRoomName.value = '';
}

function saveEditRoom(roomId: number): void {
    if (!editingRoomCategory.value) {
        return;
    }

    router.put(
        admin.properties.roomCategories.rooms.update({
            property: props.property,
            roomCategory: editingRoomCategory.value,
            room: roomId,
        }).url,
        { name: editingRoomName.value, bed_count: editingRoomBeds.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => cancelEditRoom(),
        },
    );
}

function confirmRoomDelete(event: MouseEvent): void {
    if (!confirm('Delete this room?')) {
        event.preventDefault();
    }
}

/** Rooms Setup: the category table's display order and the upsell ladder, both dragged into order. */
const orderedCategories = ref<RoomCategory[]>([]);
const upsellCategories = ref<RoomCategory[]>([]);

watch(
    () => props.property.room_categories,
    (categories) => {
        orderedCategories.value = [...(categories ?? [])];
        // Highest price first, as the server orders it (the saved order, else by rate).
        const position = new Map(
            props.upsellCategoryIds.map((id, index) => [id, index]),
        );
        upsellCategories.value = [...(categories ?? [])].sort(
            (a, b) =>
                (position.get(a.id) ?? Number.MAX_SAFE_INTEGER) -
                (position.get(b.id) ?? Number.MAX_SAFE_INTEGER),
        );
    },
    { immediate: true },
);

const draggingCategory = ref<{
    list: 'display' | 'upsell';
    index: number;
} | null>(null);

function categoryList(list: 'display' | 'upsell') {
    return list === 'display' ? orderedCategories : upsellCategories;
}

function onCategoryDragStart(list: 'display' | 'upsell', index: number): void {
    draggingCategory.value = { list, index };
}

function onCategoryDragOver(list: 'display' | 'upsell', index: number): void {
    const dragging = draggingCategory.value;

    if (!dragging || dragging.list !== list || dragging.index === index) {
        return;
    }

    const categories = [...categoryList(list).value];
    const [moved] = categories.splice(dragging.index, 1);
    categories.splice(index, 0, moved);
    categoryList(list).value = categories;
    draggingCategory.value = { list, index };
}

function onCategoryDragEnd(): void {
    const dragging = draggingCategory.value;
    draggingCategory.value = null;

    if (!dragging) {
        return;
    }

    const route =
        dragging.list === 'display'
            ? admin.properties.roomCategories.reorder(props.property)
            : admin.properties.roomCategories.upsellOrder(props.property);

    router.put(
        route.url,
        {
            category_ids: categoryList(dragging.list).value.map(
                (category) => category.id,
            ),
        },
        { preserveScroll: true, preserveState: true },
    );
}

const roomCategoryLogsOpen = ref(false);

function formatLogDate(date: string | null): string {
    return date
        ? new Date(date).toLocaleString('en-GB', {
              timeZone: props.property.timezone ?? 'UTC',
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '--';
}

const orderedRooms = ref<Room[]>([]);

watch(
    currentRoomCategory,
    (roomCategory) => {
        orderedRooms.value = [...(roomCategory?.rooms ?? [])];
    },
    { immediate: true },
);

const draggingRoomIndex = ref<number | null>(null);

function onRoomDragStart(index: number): void {
    draggingRoomIndex.value = index;
}

function onRoomDragOver(index: number): void {
    if (draggingRoomIndex.value === null || draggingRoomIndex.value === index) {
        return;
    }

    const rooms = [...orderedRooms.value];
    const [moved] = rooms.splice(draggingRoomIndex.value, 1);
    rooms.splice(index, 0, moved);
    orderedRooms.value = rooms;
    draggingRoomIndex.value = index;
}

function onRoomDragEnd(): void {
    if (draggingRoomIndex.value === null || !editingRoomCategory.value) {
        draggingRoomIndex.value = null;
        return;
    }

    draggingRoomIndex.value = null;

    router.put(
        admin.properties.roomCategories.rooms.reorder({
            property: props.property,
            roomCategory: editingRoomCategory.value,
        }).url,
        { room_ids: orderedRooms.value.map((room) => room.id) },
        { preserveScroll: true, preserveState: true },
    );
}

const imageInput = ref<HTMLInputElement | null>(null);
const imagePreviewUrl = ref<string | null>(null);

const imageForm = useForm({
    image: null as File | null,
});

function selectImageFile(): void {
    imageInput.value?.click();
}

function onImageFileChange(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    imageForm.image = file;

    if (imagePreviewUrl.value) {
        URL.revokeObjectURL(imagePreviewUrl.value);
    }

    imagePreviewUrl.value = file ? URL.createObjectURL(file) : null;
}

function uploadImage(): void {
    if (!editingRoomCategory.value || !imageForm.image) {
        return;
    }

    imageForm.post(
        admin.properties.roomCategories.images.store({
            property: props.property,
            roomCategory: editingRoomCategory.value,
        }).url,
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                imageForm.reset();

                if (imagePreviewUrl.value) {
                    URL.revokeObjectURL(imagePreviewUrl.value);
                }

                imagePreviewUrl.value = null;

                if (imageInput.value) {
                    imageInput.value.value = '';
                }
            },
        },
    );
}

function toggleImageSelected(image: { id: number; selected: boolean }): void {
    if (!editingRoomCategory.value) {
        return;
    }

    router.put(
        admin.properties.roomCategories.images.update({
            property: props.property,
            roomCategory: editingRoomCategory.value,
            image: image.id,
        }).url,
        { selected: !image.selected },
        { preserveScroll: true, preserveState: true },
    );
}

function confirmImageDelete(event: MouseEvent): void {
    if (!confirm('Delete this image?')) {
        event.preventDefault();
    }
}

const amenityForm = useForm({
    amenity_ids: (props.property.amenities ?? []).map((amenity) => amenity.id),
});

function submitPropertyAmenities(): void {
    amenityForm.put(admin.properties.amenities.update(props.property).url);
}

const categoryAmenityForm = useForm({
    amenity_ids: [] as number[],
});

function loadCategoryAmenityForm(): void {
    if (!editingRoomCategory.value) {
        categoryAmenityForm.amenity_ids = [];

        return;
    }

    categoryAmenityForm.amenity_ids = props.roomCategoryAmenities
        .filter(
            (link) => link.room_category_id === editingRoomCategory.value?.id,
        )
        .map((link) => link.amenity_id);
}

watch(editingRoomCategory, loadCategoryAmenityForm, { immediate: true });

function submitCategoryAmenities(): void {
    if (!editingRoomCategory.value) {
        return;
    }

    categoryAmenityForm.put(
        admin.properties.roomCategories.amenities.update({
            property: props.property,
            roomCategory: editingRoomCategory.value,
        }).url,
        { preserveScroll: true, preserveState: true },
    );
}

type BedTypeRow = { name: string; enabled: boolean; quantity: number };

const bedTypeRows = ref<BedTypeRow[]>([]);
const extraBedTypeRows = ref<Omit<BedTypeRow, 'quantity'>[]>([]);

function loadBedTypeRows(): void {
    const existing =
        currentRoomCategory.value?.bed_types?.filter(
            (bedType) => bedType.type === 'bed',
        ) ?? [];

    bedTypeRows.value = props.bedTypeCatalog.map((option) => {
        const match = existing.find((bedType) => bedType.name === option.label);

        return {
            name: option.label,
            enabled: !!match,
            quantity: match?.quantity ?? 1,
        };
    });
}

function loadExtraBedTypeRows(): void {
    const existing =
        currentRoomCategory.value?.bed_types?.filter(
            (bedType) => bedType.type === 'extra_bed',
        ) ?? [];

    extraBedTypeRows.value = props.bedTypeCatalog.map((option) => ({
        name: option.label,
        enabled: existing.some((bedType) => bedType.name === option.label),
    }));
}

watch(
    editingRoomCategory,
    () => {
        loadBedTypeRows();
        loadExtraBedTypeRows();
    },
    { immediate: true },
);

const bedTypeForm = useForm({
    items: [] as BedTypeRow[],
});

function submitBedTypes(): void {
    if (!editingRoomCategory.value) {
        return;
    }

    bedTypeForm.items = bedTypeRows.value;
    bedTypeForm.put(
        admin.properties.roomCategories.bedTypes.update({
            property: props.property,
            roomCategory: editingRoomCategory.value,
        }).url,
        { preserveScroll: true, preserveState: true },
    );
}

const extraBedTypeForm = useForm({
    items: [] as Omit<BedTypeRow, 'quantity'>[],
});

function submitExtraBedTypes(): void {
    if (!editingRoomCategory.value) {
        return;
    }

    extraBedTypeForm.items = extraBedTypeRows.value;
    extraBedTypeForm.put(
        admin.properties.roomCategories.extraBedTypes.update({
            property: props.property,
            roomCategory: editingRoomCategory.value,
        }).url,
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <Head title="Edit property" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Edit Property: {{ property.name }}
        </h1>
        <Link
            :href="admin.properties.index().url"
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
        >
            Close
        </Link>
    </div>

    <div
        class="mb-6 flex gap-1 border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'details'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = 'details'"
        >
            Details
        </button>
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'terms-inclusions'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = 'terms-inclusions'"
        >
            Terms & Inclusions
        </button>
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'cancellation-rules'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = 'cancellation-rules'"
        >
            Cancellation Rules
        </button>
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'meal-plans'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = 'meal-plans'"
        >
            Meal Plans
        </button>
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'rooms-setup'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = 'rooms-setup'"
        >
            Rooms Setup
        </button>
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'property-amenities'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = 'property-amenities'"
        >
            Property Amenities
        </button>
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'branding'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = 'branding'"
        >
            Branding
        </button>
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'policies'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = 'policies'"
        >
            Policies
        </button>
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'payment-terms'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = 'payment-terms'"
        >
            Payment Terms
        </button>
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'grc-settings'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = 'grc-settings'"
        >
            GRC Settings
        </button>
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'add-ons'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = 'add-ons'"
        >
            Add-ons
        </button>
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'web-check-in'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = 'web-check-in'"
        >
            Web Check-in
        </button>
        <button
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === 'early-late-charges'
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            data-test="tab-early-late-charges"
            @click="activeTab = 'early-late-charges'"
        >
            Early / Late Charges
        </button>
    </div>

    <form
        v-show="
            activeTab === 'details' ||
            activeTab === 'terms-inclusions' ||
            activeTab === 'meal-plans' ||
            activeTab === 'policies' ||
            activeTab === 'payment-terms' ||
            activeTab === 'grc-settings'
        "
        class="flex flex-col gap-8"
        :class="activeTab === 'policies' ? 'max-w-6xl' : 'max-w-3xl'"
        style="overflow-anchor: none"
        novalidate
        @submit.prevent="submit"
    >
        <!-- Fields live on several tabs: list every error here so one on a hidden tab isn't missed. -->
        <div
            v-if="Object.keys(form.errors).length"
            class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
            role="alert"
        >
            <p class="font-medium">Not saved. Fix these first:</p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="(message, field) in form.errors" :key="field">
                    {{ message }}
                </li>
            </ul>
        </div>
        <div v-show="activeTab === 'details'" class="flex flex-col gap-8">
            <fieldset class="flex flex-col gap-4">
                <legend
                    class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Basic info
                </legend>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Name</label
                        >
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            autofocus
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <p
                            v-if="form.errors.name"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Legal name</label
                        >
                        <input
                            v-model="form.property_legal_name"
                            type="text"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <p
                            v-if="form.errors.property_legal_name"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.property_legal_name }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Property group</label
                        >
                        <select
                            v-model="form.property_group_id"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="" disabled>Select a group</option>
                            <option
                                v-for="group in props.propertyGroups"
                                :key="group.id"
                                :value="group.id"
                            >
                                {{ group.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.property_group_id"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.property_group_id }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Abbreviation</label
                        >
                        <input
                            v-model="form.abbreviation"
                            type="text"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <p
                            v-if="form.errors.abbreviation"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.abbreviation }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Property type</label
                        >
                        <select
                            v-model="form.property_type"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="" disabled>
                                Select a property type
                            </option>
                            <option
                                v-for="option in props.propertyTypeOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.property_type"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.property_type }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Property segment</label
                        >
                        <select
                            v-model="form.property_segment"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="" disabled>
                                Select a property segment
                            </option>
                            <option
                                v-for="option in props.propertySegmentOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.property_segment"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.property_segment }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Star rating</label
                        >
                        <select
                            v-model="form.star_rating"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="" disabled>
                                Select a star rating
                            </option>
                            <option
                                v-for="option in props.starRatingOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.star_rating"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.star_rating }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Tax type</label
                        >
                        <select
                            v-model="form.tax_type"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option
                                v-for="option in props.taxTypeOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.tax_type"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.tax_type }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Contact number</label
                        >
                        <input
                            v-model="form.property_contact_number"
                            type="text"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <p
                            v-if="form.errors.property_contact_number"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.property_contact_number }}
                        </p>
                    </div>
                </div>

                <label
                    class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="form.outlet_sac_code_enabled"
                        type="checkbox"
                    />
                    Enable Outlet SAC code
                </label>
                <label
                    class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="form.metasearch_engines_listings_enabled"
                        type="checkbox"
                    />
                    Enable Meta search engine listings
                </label>
                <div
                    v-if="form.metasearch_engines_listings_enabled"
                    class="ml-6 flex flex-col gap-3 border-l border-[#e3e3e0] pl-4 dark:border-[#3E3E3A]"
                >
                    <div
                        v-for="platform in props.supportedMetasearchPlatforms"
                        :key="platform.id"
                    >
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >{{ platform.name }} listing ID</label
                        >
                        <input
                            v-model="form.metasearch_platforms[platform.id]"
                            type="text"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                </div>
            </fieldset>

            <fieldset class="flex flex-col gap-4">
                <legend
                    class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Third-party integrations
                </legend>

                <label
                    class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="form.third_party_analytics_enabled"
                        type="checkbox"
                    />
                    Enable third-party analytics
                </label>
                <div
                    v-if="form.third_party_analytics_enabled"
                    class="ml-6 flex flex-col gap-3 border-l border-[#e3e3e0] pl-4 dark:border-[#3E3E3A]"
                >
                    <div
                        v-for="provider in props.supportedThirdPartyAnalytics"
                        :key="provider.id"
                    >
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >{{ provider.name }} tracking ID</label
                        >
                        <input
                            v-model="form.third_party_analytics[provider.id]"
                            type="text"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                </div>

                <label
                    class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="form.third_party_ad_tracking_enabled"
                        type="checkbox"
                    />
                    Enable third-party ad tracking
                </label>
                <div
                    v-if="form.third_party_ad_tracking_enabled"
                    class="ml-6 flex flex-col gap-3 border-l border-[#e3e3e0] pl-4 dark:border-[#3E3E3A]"
                >
                    <div
                        v-for="provider in props.supportedThirdPartyAdTrackings"
                        :key="provider.id"
                    >
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >{{ provider.name }} tracking ID</label
                        >
                        <input
                            v-model="form.third_party_ad_tracking[provider.id]"
                            type="text"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                </div>
            </fieldset>

            <fieldset class="flex flex-col gap-4">
                <legend
                    class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Address
                </legend>

                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Address line 1</label
                        >
                        <input
                            v-model="form.address_line_1"
                            type="text"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <p
                            v-if="form.errors.address_line_1"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.address_line_1 }}
                        </p>
                    </div>
                    <div class="col-span-2">
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Address line 2</label
                        >
                        <input
                            v-model="form.address_line_2"
                            type="text"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Postal code</label
                        >
                        <input
                            v-model="form.postal_code"
                            type="text"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <p
                            v-if="form.errors.postal_code"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.postal_code }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Country</label
                        >
                        <select
                            v-model="form.country_id"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="" disabled>Select a country</option>
                            <option
                                v-for="country in props.countries"
                                :key="country.id"
                                :value="country.id"
                            >
                                {{ country.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.country_id"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.country_id }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >State</label
                        >
                        <select
                            v-model="form.state_id"
                            required
                            :disabled="!form.country_id"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="" disabled>Select a state</option>
                            <option
                                v-for="state in address.states.value"
                                :key="state.id"
                                :value="state.id"
                            >
                                {{ state.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.state_id"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.state_id }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >City</label
                        >
                        <select
                            v-model="form.city_id"
                            required
                            :disabled="!form.state_id"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="" disabled>Select a city</option>
                            <option
                                v-for="city in address.cities.value"
                                :key="city.id"
                                :value="city.id"
                            >
                                {{ city.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.city_id"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.city_id }}
                        </p>
                    </div>
                </div>
            </fieldset>

            <fieldset class="flex flex-col gap-4">
                <legend
                    class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Corporate address
                </legend>

                <label
                    class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="form.corporate_address_permanent_address_same"
                        type="checkbox"
                    />
                    Same as permanent address
                </label>

                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Address line 1</label
                        >
                        <input
                            v-model="form.corporate_address_line_1"
                            type="text"
                            required
                            :readonly="
                                form.corporate_address_permanent_address_same
                            "
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <p
                            v-if="form.errors.corporate_address_line_1"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.corporate_address_line_1 }}
                        </p>
                    </div>
                    <div class="col-span-2">
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Address line 2</label
                        >
                        <input
                            v-model="form.corporate_address_line_2"
                            type="text"
                            :readonly="
                                form.corporate_address_permanent_address_same
                            "
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Postal code</label
                        >
                        <input
                            v-model="form.corporate_postal_code"
                            type="text"
                            required
                            :readonly="
                                form.corporate_address_permanent_address_same
                            "
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <p
                            v-if="form.errors.corporate_postal_code"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.corporate_postal_code }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Country</label
                        >
                        <select
                            v-model="form.corporate_country_id"
                            required
                            :disabled="
                                form.corporate_address_permanent_address_same
                            "
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="" disabled>Select a country</option>
                            <option
                                v-for="country in props.countries"
                                :key="country.id"
                                :value="country.id"
                            >
                                {{ country.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.corporate_country_id"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.corporate_country_id }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >State</label
                        >
                        <select
                            v-model="form.corporate_state_id"
                            required
                            :disabled="
                                form.corporate_address_permanent_address_same ||
                                !form.corporate_country_id
                            "
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="" disabled>Select a state</option>
                            <option
                                v-for="state in corporateAddress.states.value"
                                :key="state.id"
                                :value="state.id"
                            >
                                {{ state.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.corporate_state_id"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.corporate_state_id }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >City</label
                        >
                        <select
                            v-model="form.corporate_city_id"
                            required
                            :disabled="
                                form.corporate_address_permanent_address_same ||
                                !form.corporate_state_id
                            "
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="" disabled>Select a city</option>
                            <option
                                v-for="city in corporateAddress.cities.value"
                                :key="city.id"
                                :value="city.id"
                            >
                                {{ city.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.corporate_city_id"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.corporate_city_id }}
                        </p>
                    </div>
                </div>
            </fieldset>

            <fieldset class="flex flex-col gap-4">
                <legend
                    class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Currency & billing
                </legend>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Currency</label
                        >
                        <select
                            v-model="form.currency_id"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="" disabled>Select a currency</option>
                            <option
                                v-for="currency in props.currencies"
                                :key="currency.id"
                                :value="currency.id"
                            >
                                {{ currency.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.currency_id"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.currency_id }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Timezone</label
                        >
                        <select
                            v-model="form.timezone"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option
                                v-for="zone in timeZones"
                                :key="zone"
                                :value="zone"
                            >
                                {{ zone }}
                            </option>
                        </select>
                        <p
                            class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Times are saved in UTC and shown in this timezone.
                        </p>
                        <p
                            v-if="form.errors.timezone"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.timezone }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Checkout tolerance value</label
                        >
                        <input
                            v-model="form.checkout_tolerance_value"
                            type="number"
                            step="0.0001"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <p
                            v-if="form.errors.checkout_tolerance_value"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.checkout_tolerance_value }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Language</label
                        >
                        <select
                            v-model="form.language"
                            required
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="" disabled>Select a language</option>
                            <option
                                v-for="languageOption in props.languages"
                                :key="languageOption.id"
                                :value="languageOption.id"
                            >
                                {{ languageOption.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.language"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.language }}
                        </p>
                    </div>
                </div>

                <label
                    class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="form.ignore_decimal_places_in_bill_settlement"
                        type="checkbox"
                    />
                    Ignore decimal places in bill settlement
                </label>
            </fieldset>

            <fieldset class="flex flex-col gap-4">
                <legend
                    class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Location
                </legend>

                <div class="grid grid-cols-3 gap-4">
                    <div class="col-span-3">
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Location map URL</label
                        >
                        <input
                            v-model="form.location_map_url"
                            type="url"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <p
                            v-if="form.errors.location_map_url"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.location_map_url }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Latitude</label
                        >
                        <input
                            v-model="form.latitude"
                            type="number"
                            step="any"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <p
                            v-if="form.errors.latitude"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.latitude }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Longitude</label
                        >
                        <input
                            v-model="form.longitude"
                            type="number"
                            step="any"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <p
                            v-if="form.errors.longitude"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.longitude }}
                        </p>
                    </div>
                </div>
            </fieldset>

            <fieldset class="flex flex-col gap-4">
                <legend
                    class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Room invoice
                </legend>

                <div>
                    <label
                        class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Custom name for room invoice</label
                    >
                    <input
                        v-model="form['customise_name_for+room_invoice']"
                        type="text"
                        class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </div>
            </fieldset>
        </div>

        <div
            v-show="activeTab === 'terms-inclusions'"
            class="flex flex-col gap-8"
        >
            <fieldset class="flex flex-col gap-4">
                <legend
                    class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Terms & Inclusions
                </legend>

                <div
                    v-for="field in termsInclusionMetaFields"
                    :key="field.id"
                    class="flex flex-col gap-2"
                >
                    <label
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="form.meta_fields[field.id].enabled"
                            type="checkbox"
                        />
                        {{ field.key_label }}
                    </label>
                    <div
                        v-if="form.meta_fields[field.id].enabled"
                        class="ml-6 border-l border-[#e3e3e0] pl-4 dark:border-[#3E3E3A]"
                    >
                        <QuillEditor
                            v-model="form.meta_fields[field.id].data"
                        />
                        <p
                            v-if="metaFieldDataError(field.id)"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ metaFieldDataError(field.id) }}
                        </p>
                    </div>
                </div>
            </fieldset>
        </div>

        <div v-show="activeTab === 'meal-plans'" class="flex flex-col gap-8">
            <fieldset class="flex flex-col gap-4">
                <legend
                    class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Meal Plans
                </legend>

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
                                    Display Order
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Constant Name
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Display Name
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Short Display Name
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                        >
                            <tr
                                v-for="row in mealPlanRows"
                                :key="row.display_order"
                            >
                                <td
                                    class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    {{ row.display_order }}
                                </td>
                                <td
                                    class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    {{ row.constant_name }}
                                </td>
                                <td class="px-4 py-3">
                                    <input
                                        v-model="row.display_name"
                                        type="text"
                                        class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    <input
                                        v-model="row.short_display_name"
                                        type="text"
                                        class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    You may change the meal plan display name and short display
                    name. Saving this tab uses the same "Save changes" button as
                    the Details tab.
                </p>
            </fieldset>
        </div>

        <div v-show="activeTab === 'policies'" class="flex flex-col gap-8">
            <div class="flex items-center justify-between">
                <h2
                    class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Policies
                </h2>
                <div class="flex items-center gap-4">
                    <button
                        type="button"
                        class="text-sm text-[#1b6ed6] hover:underline"
                        @click="policyLogsOpen = true"
                    >
                        Activity Logs
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    >
                        {{ form.processing ? 'Saving...' : 'Save' }}
                    </button>
                </div>
            </div>

            <fieldset class="flex flex-col gap-2">
                <legend
                    class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Check-In/Check-Out Time
                </legend>
                <div class="flex flex-wrap gap-10">
                    <div>
                        <p
                            class="mb-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Check In
                        </p>
                        <div class="flex gap-2">
                            <label>
                                <span
                                    class="mb-1 block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                    >Hour</span
                                >
                                <input
                                    v-model.number="
                                        checkinCheckoutTime.check_in_hour
                                    "
                                    type="number"
                                    min="0"
                                    max="23"
                                    required
                                    class="block w-20 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                />
                            </label>
                            <label>
                                <span
                                    class="mb-1 block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                    >Min</span
                                >
                                <input
                                    v-model.number="
                                        checkinCheckoutTime.check_in_minute
                                    "
                                    type="number"
                                    min="0"
                                    max="59"
                                    required
                                    class="block w-20 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                />
                            </label>
                        </div>
                    </div>
                    <div>
                        <p
                            class="mb-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Check Out
                        </p>
                        <div class="flex gap-2">
                            <label>
                                <span
                                    class="mb-1 block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                    >Hour</span
                                >
                                <input
                                    v-model.number="
                                        checkinCheckoutTime.check_out_hour
                                    "
                                    type="number"
                                    min="0"
                                    max="23"
                                    required
                                    class="block w-20 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                />
                            </label>
                            <label>
                                <span
                                    class="mb-1 block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                    >Min</span
                                >
                                <input
                                    v-model.number="
                                        checkinCheckoutTime.check_out_minute
                                    "
                                    type="number"
                                    min="0"
                                    max="59"
                                    required
                                    class="block w-20 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                />
                            </label>
                        </div>
                    </div>
                </div>
            </fieldset>

            <fieldset class="flex flex-col gap-4">
                <legend
                    class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Weekends &amp; Holidays
                </legend>
                <div>
                    <p
                        class="mb-2 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Select weekend days as per your region
                    </p>
                    <div class="flex flex-wrap gap-4">
                        <label
                            v-for="(weekday, day) in WEEKDAY_LABELS"
                            :key="weekday"
                            class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            <input
                                v-model="weekendHolidays.weekend_days"
                                type="checkbox"
                                :value="day"
                            />
                            {{ weekday }}
                        </label>
                    </div>
                </div>
                <label
                    class="flex items-center gap-3 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Do you want to set up long weekends/holidays and events in
                    the calendar?
                    <input
                        v-model="weekendHolidays.holidays_enabled"
                        type="checkbox"
                    />
                </label>
                <div
                    v-if="weekendHolidays.holidays_enabled"
                    class="flex flex-col gap-2"
                >
                    <p
                        class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Add Long Weekend, Holidays &amp; Events
                    </p>
                    <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        Select the date/date range on the calendar you want to
                        display as holiday on the smart chart.
                    </p>
                    <HolidayCalendar
                        v-model="weekendHolidays.holidays"
                        :property-id="property.id"
                        :today="propertyToday"
                        :weekend-days="weekendHolidays.weekend_days"
                    />
                </div>
            </fieldset>

            <fieldset class="flex flex-col gap-2">
                <legend
                    class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Card Types
                </legend>
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    Cards accepted at the property.
                </p>
                <div class="flex flex-wrap gap-4">
                    <label
                        v-for="card in CARD_TYPES"
                        :key="card"
                        class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="cardTypes.accepted"
                            type="checkbox"
                            :value="card"
                        />
                        {{ card }}
                    </label>
                </div>
            </fieldset>

            <template v-for="policy in policyRichTextFields" :key="policy.key">
                <fieldset
                    v-if="policyField(policy.key)"
                    class="flex flex-col gap-2"
                >
                    <legend
                        class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        {{ policy.label }}
                    </legend>
                    <QuillEditor
                        v-model="
                            form.meta_fields[policyField(policy.key)!.id].data
                        "
                    />
                    <p
                        v-if="metaFieldDataError(policyField(policy.key)!.id)"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ metaFieldDataError(policyField(policy.key)!.id) }}
                    </p>
                </fieldset>
            </template>

            <ActivityLogsModal
                :show="policyLogsOpen"
                title="Policies activity logs"
                :logs="policyLogs"
                :timezone="property.timezone"
                @close="policyLogsOpen = false"
            />
        </div>

        <div v-show="activeTab === 'payment-terms'" class="flex gap-6">
            <div class="w-64 flex-shrink-0">
                <div class="mb-2 flex items-center justify-between">
                    <h2
                        class="font-display text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Defined Booking Types
                    </h2>
                    <button
                        type="button"
                        class="rounded-full bg-[#1b1b18] px-2 py-0.5 text-sm font-medium text-white dark:bg-[#eeeeec] dark:text-[#1C1C1A]"
                        @click="openPaymentTermModal"
                    >
                        +
                    </button>
                </div>
                <ul class="flex flex-col gap-1">
                    <li
                        v-for="entry in paymentTermEntries"
                        :key="entry.booking_type"
                    >
                        <button
                            type="button"
                            class="block w-full rounded-md px-3 py-2 text-left text-sm"
                            :class="
                                selectedBookingType === entry.booking_type
                                    ? 'bg-[#f5f5f4] font-medium text-[#1b1b18] dark:bg-[#1D1D1B] dark:text-[#EDEDEC]'
                                    : 'text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]'
                            "
                            @click="selectedBookingType = entry.booking_type"
                        >
                            {{ entry.booking_type }}
                        </button>
                    </li>
                    <li
                        v-if="!paymentTermEntries.length"
                        class="px-3 py-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        No booking types defined yet.
                    </li>
                </ul>
            </div>

            <div
                v-if="selectedPaymentTermEntry"
                class="flex flex-1 flex-col gap-4"
            >
                <h2
                    class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Define Payment Terms —
                    {{ selectedPaymentTermEntry.booking_type }}
                </h2>
                <div class="grid grid-cols-2 gap-2">
                    <label
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="
                                selectedPaymentTermEntry.enable_terms_on_invoice
                            "
                            type="checkbox"
                        />
                        Enable Terms on Invoice
                    </label>
                    <label
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="
                                selectedPaymentTermEntry.enable_terms_on_proforma_invoice
                            "
                            type="checkbox"
                        />
                        Enable Terms on Proforma Invoice
                    </label>
                    <label
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="
                                selectedPaymentTermEntry.enable_terms_on_guest_bill_summary
                            "
                            type="checkbox"
                        />
                        Enable Terms on Guest Bill Summary
                    </label>
                    <label
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="
                                selectedPaymentTermEntry.enable_terms_on_company_bill_summary
                            "
                            type="checkbox"
                        />
                        Enable Terms on Company Bill Summary
                    </label>
                </div>
                <QuillEditor v-model="selectedPaymentTermEntry.content" />
            </div>
            <p v-else class="flex-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Add a booking type to define its payment terms.
            </p>
        </div>

        <div v-show="activeTab === 'grc-settings'" class="flex flex-col gap-6">
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
                                Name
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Enable
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                    >
                        <tr
                            v-for="field in grcSettings.fields"
                            :key="field.name"
                        >
                            <td
                                class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ field.name }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <input
                                    v-model="field.enabled"
                                    type="checkbox"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <fieldset
                class="max-w-md rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
            >
                <legend
                    class="font-display mb-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Print Header
                </legend>
                <div class="flex items-center gap-4">
                    <label class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Guest Reservation Card</label
                    >
                    <input
                        v-model="grcSettings.print_header"
                        type="text"
                        class="block flex-1 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </div>
            </fieldset>
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
                :href="admin.properties.index()"
                class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
            >
                Cancel
            </Link>
        </div>
    </form>

    <PropertyAddonsForm
        v-show="activeTab === 'add-ons'"
        :property-id="property.id"
        :addons="addons"
        :pricing="addonPricing"
    />

    <PropertyWebCheckinForm
        v-show="activeTab === 'web-check-in'"
        :property-id="property.id"
        :settings="webCheckin"
    />

    <PropertyStayTimeChargesForm
        v-show="activeTab === 'early-late-charges'"
        :property-id="property.id"
        :settings="stayTimeCharges"
        :standard-times="standardStayTimes"
    />

    <PropertyBrandingForm
        v-show="activeTab === 'branding'"
        :property-id="property.id"
        :timezone="property.timezone"
        :branding="branding"
        :logs="brandingLogs"
    />

    <div
        v-show="activeTab === 'cancellation-rules'"
        class="flex max-w-3xl flex-col gap-4"
    >
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
                            Cancellation Rule
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Start Time Stamp
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            End Time Stamp
                        </th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                >
                    <tr
                        v-for="(rule, index) in property.cancellation_rules ??
                        []"
                        :key="rule.id"
                    >
                        <td
                            class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ index + 1 }}
                        </td>
                        <td
                            class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{ ruleTimestamp(rule.start_time_stamp) }}
                        </td>
                        <td
                            class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{ ruleTimestamp(rule.end_time_stamp) }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm">
                            <button
                                type="button"
                                class="font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                                @click="openEditCancellationRule(rule)"
                            >
                                Edit
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!property.cancellation_rules?.length">
                        <td
                            colspan="4"
                            class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            No cancellation rules yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div>
            <button
                v-if="!cancellationRulePanelOpen"
                type="button"
                class="inline-flex items-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                @click="openAddCancellationRule"
            >
                Add
            </button>
        </div>

        <form
            v-if="cancellationRulePanelOpen"
            class="flex flex-col gap-4 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
            @submit.prevent="submitCancellationRule"
        >
            <p
                class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-300"
            >
                Cancellation rule date ranges cannot overlap with other rules
                for this property.
            </p>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label
                        class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Start Date</label
                    >
                    <input
                        v-model="cancellationRuleForm.start_time_stamp"
                        type="datetime-local"
                        required
                        class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                    <p
                        v-if="cancellationRuleForm.errors.start_time_stamp"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ cancellationRuleForm.errors.start_time_stamp }}
                    </p>
                </div>
                <div>
                    <label
                        class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >End Date</label
                    >
                    <input
                        v-model="cancellationRuleForm.end_time_stamp"
                        type="datetime-local"
                        required
                        class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                    <p
                        v-if="cancellationRuleForm.errors.end_time_stamp"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ cancellationRuleForm.errors.end_time_stamp }}
                    </p>
                </div>
            </div>

            <div
                v-if="cancellationDateOverlapError"
                class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-400"
            >
                {{ cancellationDateOverlapError }}
            </div>

            <div class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <span
                        class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Cancellation Rules</span
                    >
                    <button
                        type="button"
                        class="inline-flex items-center rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-xs font-medium text-[#1b1b18] shadow-sm hover:bg-gray-50 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:bg-[#20201e]"
                        @click="addRuleTier"
                    >
                        + Add Row
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
                                    class="px-4 py-2.5 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Day From CheckIn
                                </th>
                                <th
                                    class="px-4 py-2.5 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Penalty %
                                </th>
                                <th
                                    class="w-20 px-4 py-2.5 text-center text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                        >
                            <tr
                                v-for="(
                                    tier, index
                                ) in cancellationRuleForm.rules"
                                :key="index"
                            >
                                <td class="px-4 py-2">
                                    <input
                                        v-if="
                                            index ===
                                            cancellationRuleForm.rules.length -
                                                1
                                        "
                                        :value="9999999"
                                        type="number"
                                        disabled
                                        readonly
                                        class="block w-full cursor-not-allowed rounded-md border border-[#e3e3e0] bg-[#f5f5f4] px-3 py-2 text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:bg-[#1D1D1B] dark:text-[#A1A09A]"
                                    />
                                    <input
                                        v-else
                                        v-model.number="tier.day_from_checkin"
                                        type="number"
                                        min="0"
                                        required
                                        placeholder="Day from check-in"
                                        class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </td>
                                <td class="px-4 py-2">
                                    <input
                                        v-model.number="tier.penalty_percent"
                                        type="number"
                                        min="0"
                                        max="100"
                                        required
                                        placeholder="Penalty %"
                                        class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <button
                                        v-if="
                                            index <
                                            cancellationRuleForm.rules.length -
                                                1
                                        "
                                        type="button"
                                        class="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                                        @click="removeRuleTier(index)"
                                    >
                                        Remove
                                    </button>
                                    <span
                                        v-else
                                        class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                        >—</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="cancellationRuleForm.errors.rules"
                    class="text-sm text-red-600"
                >
                    {{ cancellationRuleForm.errors.rules }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="cancellationRuleForm.processing"
                    class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                >
                    Save
                </button>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="closeCancellationRulePanel"
                >
                    Cancel
                </button>
            </div>
        </form>
    </div>

    <div
        v-show="activeTab === 'rooms-setup'"
        class="flex max-w-3xl flex-col gap-4"
    >
        <template v-if="!editingRoomCategory">
            <div class="flex items-center justify-between gap-3">
                <h2
                    class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Room category
                    <span
                        class="text-sm font-normal text-[#706f6c] dark:text-[#A1A09A]"
                        >(in order of display on Smart Chart)</span
                    >
                </h2>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    @click="roomCategoryLogsOpen = true"
                >
                    Activity Logs
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
                                class="w-10 px-4 py-3 text-left text-xs font-medium text-[#706f6c] dark:text-[#A1A09A]"
                                title="Drag rows to reorder"
                            >
                                ↕
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Display order
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Name
                            </th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                    >
                        <tr
                            v-for="(roomCategory, index) in orderedCategories"
                            :key="roomCategory.id"
                            draggable="true"
                            class="cursor-move"
                            :class="{
                                'opacity-50':
                                    draggingCategory?.list === 'display' &&
                                    draggingCategory.index === index,
                            }"
                            @dragstart="onCategoryDragStart('display', index)"
                            @dragover.prevent="
                                onCategoryDragOver('display', index)
                            "
                            @dragend="onCategoryDragEnd"
                        >
                            <td
                                class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                title="Drag to reorder"
                            >
                                ⠿
                            </td>
                            <td
                                class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ roomCategory.display_order }}
                            </td>
                            <td
                                class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ roomCategory.name }}
                            </td>
                            <td class="px-4 py-3 text-right text-sm">
                                <button
                                    type="button"
                                    class="mr-3 font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                                    @click="openEditRoomCategory(roomCategory)"
                                >
                                    Edit
                                </button>
                                <Link
                                    :href="
                                        admin.properties.roomCategories.destroy(
                                            { property, roomCategory },
                                        ).url
                                    "
                                    method="delete"
                                    as="button"
                                    class="font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                                    @click="confirmRoomCategoryDelete"
                                >
                                    Delete
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="!orderedCategories.length">
                            <td
                                colspan="4"
                                class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                No room categories yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div>
                <button
                    type="button"
                    class="inline-flex items-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    @click="openRoomCategoryModal"
                >
                    Add new room category
                </button>
            </div>

            <h2
                class="font-display mt-4 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Room category
                <span
                    class="text-sm font-normal text-[#706f6c] dark:text-[#A1A09A]"
                    >(upsell order)</span
                >
            </h2>
            <div
                v-if="upsellCategories.length"
                class="flex gap-6 rounded-lg border border-[#e3e3e0] bg-white p-4 dark:border-[#3E3E3A] dark:bg-[#161615]"
            >
                <ol class="flex flex-1 flex-col items-center gap-1">
                    <li
                        v-for="(roomCategory, index) in upsellCategories"
                        :key="roomCategory.id"
                        draggable="true"
                        class="flex cursor-move items-center justify-between gap-2 rounded px-3 py-1 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        :class="[
                            index % 2 === 0
                                ? 'bg-[#dbeafe] dark:bg-[#1e3a5f]'
                                : 'bg-[#e5e5e5] dark:bg-[#3E3E3A]',
                            {
                                'opacity-50':
                                    draggingCategory?.list === 'upsell' &&
                                    draggingCategory.index === index,
                            },
                        ]"
                        :style="{
                            width: `${Math.max(40, 80 - index * (40 / Math.max(1, upsellCategories.length - 1)))}%`,
                        }"
                        title="Drag to change the upsell order"
                        @dragstart="onCategoryDragStart('upsell', index)"
                        @dragover.prevent="onCategoryDragOver('upsell', index)"
                        @dragend="onCategoryDragEnd"
                    >
                        <span class="flex-1 text-center">{{
                            roomCategory.name
                        }}</span>
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >↕</span
                        >
                    </li>
                </ol>
                <div
                    class="flex flex-col items-center justify-between text-xs font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <span>Highest Price</span>
                    <div class="flex flex-1 items-center gap-1 py-2">
                        <span
                            class="rotate-180 text-[10px] tracking-widest text-[#706f6c] [writing-mode:vertical-rl] dark:text-[#A1A09A]"
                            >UPSELL</span
                        >
                        <div
                            class="relative h-full w-px bg-[#706f6c] dark:bg-[#A1A09A]"
                        >
                            <span
                                class="absolute -top-1 -left-[3px] text-[8px] leading-none text-[#706f6c] dark:text-[#A1A09A]"
                                >▲</span
                            >
                        </div>
                    </div>
                    <span>Lowest Price</span>
                </div>
            </div>

            <div
                v-if="roomCategoryLogsOpen"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                @click.self="roomCategoryLogsOpen = false"
            >
                <div
                    class="flex max-h-[80vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-xl dark:bg-[#161615]"
                >
                    <div
                        class="flex items-center justify-between border-b border-[#e3e3e0] px-4 py-3 dark:border-[#3E3E3A]"
                    >
                        <h3
                            class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Room category activity logs
                        </h3>
                        <button
                            type="button"
                            class="text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                            @click="roomCategoryLogsOpen = false"
                        >
                            ✕
                        </button>
                    </div>
                    <div class="overflow-y-auto">
                        <table
                            class="min-w-full divide-y divide-[#e3e3e0] text-sm dark:divide-[#3E3E3A]"
                        >
                            <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                                <tr>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                    >
                                        Date
                                    </th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                    >
                                        User
                                    </th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                    >
                                        Activity
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
                            >
                                <tr
                                    v-for="log in roomCategoryLogs"
                                    :key="log.id"
                                >
                                    <td
                                        class="px-4 py-2 whitespace-nowrap text-[#706f6c] dark:text-[#A1A09A]"
                                    >
                                        {{ formatLogDate(log.date) }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-[#1b1b18] dark:text-[#EDEDEC]"
                                    >
                                        {{ log.user ?? '--' }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-[#1b1b18] dark:text-[#EDEDEC]"
                                    >
                                        {{ log.action }}
                                    </td>
                                </tr>
                                <tr v-if="!roomCategoryLogs.length">
                                    <td
                                        colspan="3"
                                        class="px-4 py-3 text-[#706f6c] dark:text-[#A1A09A]"
                                    >
                                        No activity yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </template>

        <div v-else class="flex flex-col gap-6">
            <div class="flex items-center justify-between">
                <h2
                    class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Editing Room Category: {{ currentRoomCategory?.name }}
                </h2>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="closeRoomCategoryDetail"
                >
                    Close
                </button>
            </div>

            <div
                class="flex gap-1 border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
            >
                <button
                    type="button"
                    class="border-b-2 px-3 py-2 text-sm font-medium"
                    :class="
                        roomCategoryDetailTab === 'category-details'
                            ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                            : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
                    "
                    @click="roomCategoryDetailTab = 'category-details'"
                >
                    Category Details
                </button>
                <button
                    type="button"
                    class="border-b-2 px-3 py-2 text-sm font-medium"
                    :class="
                        roomCategoryDetailTab === 'rooms-under-category'
                            ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                            : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
                    "
                    @click="roomCategoryDetailTab = 'rooms-under-category'"
                >
                    Rooms under Category
                </button>
                <button
                    type="button"
                    class="border-b-2 px-3 py-2 text-sm font-medium"
                    :class="
                        roomCategoryDetailTab === 'images'
                            ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                            : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
                    "
                    @click="roomCategoryDetailTab = 'images'"
                >
                    Room Category Images
                </button>
                <button
                    type="button"
                    class="border-b-2 px-3 py-2 text-sm font-medium"
                    :class="
                        roomCategoryDetailTab === 'category-amenities'
                            ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                            : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
                    "
                    @click="roomCategoryDetailTab = 'category-amenities'"
                >
                    Category Amenities
                </button>
                <button
                    type="button"
                    class="border-b-2 px-3 py-2 text-sm font-medium"
                    :class="
                        roomCategoryDetailTab === 'bed-type'
                            ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                            : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
                    "
                    @click="roomCategoryDetailTab = 'bed-type'"
                >
                    Bed Type
                </button>
                <button
                    type="button"
                    class="border-b-2 px-3 py-2 text-sm font-medium"
                    :class="
                        roomCategoryDetailTab === 'extra-bed-type'
                            ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                            : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
                    "
                    @click="roomCategoryDetailTab = 'extra-bed-type'"
                >
                    Extra Bed Type
                </button>
            </div>

            <form
                v-show="roomCategoryDetailTab === 'category-details'"
                class="flex flex-col gap-6"
                @submit.prevent="submitRoomCategoryDetail"
            >
                <fieldset class="flex flex-col gap-4">
                    <legend
                        class="font-display mb-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Category details
                    </legend>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Name</label
                            >
                            <input
                                v-model="roomCategoryDetailForm.name"
                                type="text"
                                required
                                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <p
                                v-if="roomCategoryDetailForm.errors.name"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ roomCategoryDetailForm.errors.name }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Description</label
                            >
                            <input
                                v-model="roomCategoryDetailForm.description"
                                type="text"
                                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <p
                                v-if="roomCategoryDetailForm.errors.description"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ roomCategoryDetailForm.errors.description }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Abbreviation</label
                            >
                            <input
                                v-model="roomCategoryDetailForm.abbreviation"
                                type="text"
                                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <p
                                v-if="
                                    roomCategoryDetailForm.errors.abbreviation
                                "
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ roomCategoryDetailForm.errors.abbreviation }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Integration code (optional)</label
                            >
                            <input
                                v-model="
                                    roomCategoryDetailForm.integration_code
                                "
                                type="text"
                                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <p
                                v-if="
                                    roomCategoryDetailForm.errors
                                        .integration_code
                                "
                                class="mt-1 text-sm text-red-600"
                            >
                                {{
                                    roomCategoryDetailForm.errors
                                        .integration_code
                                }}
                            </p>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="flex flex-col gap-4">
                    <legend
                        class="font-display mb-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Inclusion details
                    </legend>
                    <QuillEditor
                        v-model="roomCategoryDetailForm.inclusion_details"
                    />
                    <p
                        v-if="roomCategoryDetailForm.errors.inclusion_details"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ roomCategoryDetailForm.errors.inclusion_details }}
                    </p>
                </fieldset>

                <fieldset class="flex flex-col gap-2">
                    <label
                        class="flex items-start gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="roomCategoryDetailForm.sell_by_bed"
                            type="checkbox"
                            class="mt-0.5"
                        />
                        <span>
                            Sell by bed (dormitory)
                            <span
                                class="block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >Guests book single beds. Set each room's number
                                of beds under Rooms under Category.</span
                            >
                        </span>
                    </label>
                    <p
                        v-if="roomCategoryDetailForm.errors.sell_by_bed"
                        class="text-sm text-red-600"
                    >
                        {{ roomCategoryDetailForm.errors.sell_by_bed }}
                    </p>
                </fieldset>

                <fieldset class="flex flex-col gap-4">
                    <legend
                        class="font-display mb-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Occupancy details
                    </legend>
                    <!-- A dorm bed is one guest: the server fixes occupancy at 1 adult. -->
                    <p
                        v-if="roomCategoryDetailForm.sell_by_bed"
                        class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Sold by bed: each bed is one guest (1 adult, no
                        children), priced at the Single rate.
                    </p>
                    <div
                        v-if="!roomCategoryDetailForm.sell_by_bed"
                        class="grid grid-cols-2 gap-4"
                    >
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Base occupancy (minimum occupancy at which room
                                sells)</label
                            >
                            <input
                                v-model.number="
                                    roomCategoryDetailForm.base_occupancy
                                "
                                type="number"
                                min="0"
                                required
                                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <p
                                v-if="
                                    roomCategoryDetailForm.errors.base_occupancy
                                "
                                class="mt-1 text-sm text-red-600"
                            >
                                {{
                                    roomCategoryDetailForm.errors.base_occupancy
                                }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Ideal occupancy (total number of beds in
                                room)</label
                            >
                            <input
                                v-model.number="
                                    roomCategoryDetailForm.ideal_occupancy
                                "
                                type="number"
                                min="0"
                                required
                                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <p
                                v-if="
                                    roomCategoryDetailForm.errors
                                        .ideal_occupancy
                                "
                                class="mt-1 text-sm text-red-600"
                            >
                                {{
                                    roomCategoryDetailForm.errors
                                        .ideal_occupancy
                                }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Max adults</label
                            >
                            <input
                                v-model.number="
                                    roomCategoryDetailForm.max_adults
                                "
                                type="number"
                                min="0"
                                required
                                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <p
                                v-if="roomCategoryDetailForm.errors.max_adults"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ roomCategoryDetailForm.errors.max_adults }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Max children</label
                            >
                            <input
                                v-model.number="
                                    roomCategoryDetailForm.max_children
                                "
                                type="number"
                                min="0"
                                required
                                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <p
                                v-if="
                                    roomCategoryDetailForm.errors.max_children
                                "
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ roomCategoryDetailForm.errors.max_children }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Default number of children</label
                            >
                            <input
                                v-model.number="
                                    roomCategoryDetailForm.default_number_of_children
                                "
                                type="number"
                                min="0"
                                required
                                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <p
                                v-if="
                                    roomCategoryDetailForm.errors
                                        .default_number_of_children
                                "
                                class="mt-1 text-sm text-red-600"
                            >
                                {{
                                    roomCategoryDetailForm.errors
                                        .default_number_of_children
                                }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Total number of guests</label
                            >
                            <input
                                v-model.number="
                                    roomCategoryDetailForm.total_number_of_guests
                                "
                                type="number"
                                min="0"
                                required
                                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <p
                                v-if="
                                    roomCategoryDetailForm.errors
                                        .total_number_of_guests
                                "
                                class="mt-1 text-sm text-red-600"
                            >
                                {{
                                    roomCategoryDetailForm.errors
                                        .total_number_of_guests
                                }}
                            </p>
                        </div>
                    </div>

                    <label
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="
                                roomCategoryDetailForm.sync_category_on_ota
                            "
                            type="checkbox"
                        />
                        Sync category on OTA
                    </label>
                    <label
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="
                                roomCategoryDetailForm.exclude_from_revenue
                            "
                            type="checkbox"
                        />
                        Exclude from revenue
                    </label>
                    <label
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="
                                roomCategoryDetailForm.exclude_from_occupancy
                            "
                            type="checkbox"
                        />
                        Exclude from occupancy
                    </label>
                    <label
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="
                                roomCategoryDetailForm.exclude_from_upgrade
                            "
                            type="checkbox"
                        />
                        Exclude from upgrade
                    </label>
                </fieldset>

                <fieldset class="flex flex-col gap-2">
                    <legend
                        class="font-display mb-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Meal plan(s)
                    </legend>
                    <label
                        v-for="mealPlan in props.mealPlanCatalog"
                        :key="mealPlan.constant_name"
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="roomCategoryDetailForm.meal_plans"
                            type="checkbox"
                            :value="mealPlan.constant_name"
                        />
                        {{ mealPlan.display_name }} ({{
                            mealPlan.constant_name
                        }})
                    </label>
                    <p
                        v-if="roomCategoryDetailForm.errors.meal_plans"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ roomCategoryDetailForm.errors.meal_plans }}
                    </p>
                </fieldset>

                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="roomCategoryDetailForm.processing"
                        class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    >
                        Save
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="closeRoomCategoryDetail"
                    >
                        Cancel
                    </button>
                </div>
            </form>

            <div
                v-show="roomCategoryDetailTab === 'rooms-under-category'"
                class="flex flex-col gap-4"
            >
                <div
                    class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
                >
                    <table
                        class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
                    >
                        <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                            <tr>
                                <th class="w-8 px-4 py-3"></th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Display order
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Name
                                </th>
                                <th
                                    v-if="currentRoomCategory?.sell_by_bed"
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Beds
                                </th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                        >
                            <tr
                                v-for="(room, index) in orderedRooms"
                                :key="room.id"
                                draggable="true"
                                class="cursor-move"
                                @dragstart="onRoomDragStart(index)"
                                @dragover.prevent="onRoomDragOver(index)"
                                @dragend="onRoomDragEnd"
                            >
                                <td
                                    class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                    title="Drag to reorder"
                                >
                                    ⠿
                                </td>
                                <td
                                    class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    {{ room.display_order }}
                                </td>
                                <td
                                    class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    <span v-if="editingRoomId !== room.id">{{
                                        room.name
                                    }}</span>
                                    <input
                                        v-else
                                        v-model="editingRoomName"
                                        type="text"
                                        class="block w-full rounded-md border border-[#e3e3e0] bg-white px-2 py-1 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </td>
                                <td
                                    v-if="currentRoomCategory?.sell_by_bed"
                                    class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    <span v-if="editingRoomId !== room.id">{{
                                        room.bed_count ?? '--'
                                    }}</span>
                                    <template v-else>
                                        <input
                                            v-model.number="editingRoomBeds"
                                            type="number"
                                            min="1"
                                            max="100"
                                            class="block w-20 rounded-md border border-[#e3e3e0] bg-white px-2 py-1 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                        />
                                        <p
                                            v-if="roomErrors.bed_count"
                                            class="mt-1 text-xs text-red-600"
                                        >
                                            {{ roomErrors.bed_count }}
                                        </p>
                                    </template>
                                </td>
                                <td class="px-4 py-3 text-right text-sm">
                                    <template v-if="editingRoomId === room.id">
                                        <button
                                            type="button"
                                            class="mr-3 font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                                            @click="saveEditRoom(room.id)"
                                        >
                                            Save
                                        </button>
                                        <button
                                            type="button"
                                            class="font-medium text-[#706f6c] underline underline-offset-4 dark:text-[#A1A09A]"
                                            @click="cancelEditRoom"
                                        >
                                            Cancel
                                        </button>
                                    </template>
                                    <template v-else>
                                        <button
                                            type="button"
                                            class="mr-3 font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                                            @click="startEditRoom(room)"
                                        >
                                            Edit
                                        </button>
                                        <Link
                                            v-if="editingRoomCategory"
                                            :href="
                                                admin.properties.roomCategories.rooms.destroy(
                                                    {
                                                        property,
                                                        roomCategory:
                                                            editingRoomCategory,
                                                        room,
                                                    },
                                                ).url
                                            "
                                            method="delete"
                                            as="button"
                                            class="font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                                            @click="confirmRoomDelete"
                                        >
                                            Delete
                                        </Link>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="!orderedRooms.length">
                                <td
                                    colspan="4"
                                    class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    No rooms yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div>
                    <button
                        type="button"
                        class="inline-flex items-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="openRoomModal"
                    >
                        Add new room
                    </button>
                </div>
            </div>

            <div
                v-show="roomCategoryDetailTab === 'images'"
                class="flex flex-col gap-4"
            >
                <div class="flex flex-wrap items-start gap-4">
                    <div>
                        <p
                            class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Upload Image
                        </p>
                        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                            (Image size should be 300 x 250 pix, and upto 1000KB
                            in size)
                        </p>
                    </div>
                    <input
                        ref="imageInput"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        class="hidden"
                        @change="onImageFileChange"
                    />
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="selectImageFile"
                    >
                        Select Image
                    </button>
                    <button
                        type="button"
                        :disabled="!imageForm.image || imageForm.processing"
                        class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="uploadImage"
                    >
                        Upload
                    </button>
                    <img
                        v-if="imagePreviewUrl"
                        :src="imagePreviewUrl"
                        class="h-16 w-20 rounded-md border border-[#e3e3e0] object-cover dark:border-[#3E3E3A]"
                    />
                    <p
                        v-if="imageForm.errors.image"
                        class="w-full text-sm text-red-600"
                    >
                        {{ imageForm.errors.image }}
                    </p>
                </div>

                <div
                    class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-5"
                >
                    <div
                        v-for="image in currentRoomCategory?.images ?? []"
                        :key="image.id"
                        class="flex flex-col gap-2"
                    >
                        <img
                            :src="image.url"
                            class="aspect-[6/5] w-full rounded-md border border-[#e3e3e0] object-cover dark:border-[#3E3E3A]"
                        />
                        <div class="flex items-center justify-between">
                            <input
                                type="checkbox"
                                :checked="image.selected"
                                @change="toggleImageSelected(image)"
                            />
                            <Link
                                v-if="editingRoomCategory"
                                :href="
                                    admin.properties.roomCategories.images.destroy(
                                        {
                                            property,
                                            roomCategory: editingRoomCategory,
                                            image,
                                        },
                                    ).url
                                "
                                method="delete"
                                as="button"
                                class="text-sm font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                                @click="confirmImageDelete"
                            >
                                Delete
                            </Link>
                        </div>
                    </div>
                    <p
                        v-if="!currentRoomCategory?.images?.length"
                        class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        No images yet.
                    </p>
                </div>
            </div>

            <div
                v-show="roomCategoryDetailTab === 'category-amenities'"
                class="flex flex-col gap-6"
            >
                <div
                    v-for="category in props.roomCategoryAmenityCategories"
                    :key="category.id"
                >
                    <h2
                        class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        {{ category.name }}
                    </h2>
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
                                        Name
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                    >
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                            >
                                <tr
                                    v-for="amenity in category.amenities"
                                    :key="amenity.id"
                                >
                                    <td
                                        class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                    >
                                        {{ amenity.name }}
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <input
                                            v-model="
                                                categoryAmenityForm.amenity_ids
                                            "
                                            type="checkbox"
                                            :value="amenity.id"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        :disabled="categoryAmenityForm.processing"
                        class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="submitCategoryAmenities"
                    >
                        Save
                    </button>
                </div>
            </div>

            <div
                v-show="roomCategoryDetailTab === 'bed-type'"
                class="flex flex-col gap-4"
            >
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
                                    No. of Beds
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                        >
                            <tr v-for="row in bedTypeRows" :key="row.name">
                                <td
                                    class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    {{ row.name }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <input
                                        v-model="row.enabled"
                                        type="checkbox"
                                    />
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <input
                                        v-model.number="row.quantity"
                                        type="number"
                                        min="1"
                                        :disabled="!row.enabled"
                                        class="block w-20 rounded-md border border-[#e3e3e0] bg-white px-2 py-1 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        :disabled="bedTypeForm.processing"
                        class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="submitBedTypes"
                    >
                        Save
                    </button>
                </div>
            </div>

            <div
                v-show="roomCategoryDetailTab === 'extra-bed-type'"
                class="flex flex-col gap-4"
            >
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
                                    Name
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                        >
                            <tr v-for="row in extraBedTypeRows" :key="row.name">
                                <td
                                    class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    {{ row.name }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <input
                                        v-model="row.enabled"
                                        type="checkbox"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        :disabled="extraBedTypeForm.processing"
                        class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="submitExtraBedTypes"
                    >
                        Save
                    </button>
                </div>
            </div>
        </div>
    </div>

    <Modal
        :show="roomCategoryModalOpen"
        title="Add new room category"
        @close="roomCategoryModalOpen = false"
    >
        <form class="flex flex-col gap-4" @submit.prevent="submitRoomCategory">
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Room category name</label
                >
                <input
                    v-model="roomCategoryForm.name"
                    type="text"
                    required
                    autofocus
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
                <p
                    v-if="roomCategoryForm.errors.name"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ roomCategoryForm.errors.name }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="roomCategoryForm.processing"
                    class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                >
                    Save
                </button>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="roomCategoryModalOpen = false"
                >
                    Cancel
                </button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="roomModalOpen"
        title="New room"
        @close="roomModalOpen = false"
    >
        <form class="flex flex-col gap-4" @submit.prevent="submitRoom">
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Room name</label
                >
                <input
                    v-model="roomForm.name"
                    type="text"
                    required
                    autofocus
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
                <p
                    v-if="roomForm.errors.name"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ roomForm.errors.name }}
                </p>
            </div>
            <div v-if="currentRoomCategory?.sell_by_bed">
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Number of beds</label
                >
                <input
                    v-model.number="roomForm.bed_count"
                    type="number"
                    min="1"
                    max="100"
                    required
                    class="block w-28 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
                <p
                    v-if="roomForm.errors.bed_count"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ roomForm.errors.bed_count }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="roomForm.processing"
                    class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                >
                    Save
                </button>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="roomModalOpen = false"
                >
                    Cancel
                </button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="paymentTermModalOpen"
        title="Add a Booking Type"
        @close="paymentTermModalOpen = false"
    >
        <form class="flex flex-col gap-4" @submit.prevent="addBookingType">
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Select a booking type</label
                >
                <select
                    v-model="newBookingTypeForm.booking_type"
                    required
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                >
                    <option value="" disabled>Select a booking type</option>
                    <option
                        v-for="option in unusedBookingTypes"
                        :key="option.value"
                        :value="option.label"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                >
                    Save
                </button>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="paymentTermModalOpen = false"
                >
                    Cancel
                </button>
            </div>
        </form>
    </Modal>

    <div
        v-show="activeTab === 'property-amenities'"
        class="flex max-w-3xl flex-col gap-6"
    >
        <div
            v-for="category in props.propertyAmenityCategories"
            :key="category.id"
        >
            <h2
                class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                {{ category.name }}
            </h2>
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
                                Name
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                    >
                        <tr
                            v-for="amenity in category.amenities"
                            :key="amenity.id"
                        >
                            <td
                                class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ amenity.name }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <input
                                    v-model="amenityForm.amenity_ids"
                                    type="checkbox"
                                    :value="amenity.id"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button
                type="button"
                :disabled="amenityForm.processing"
                class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                @click="submitPropertyAmenities"
            >
                Save
            </button>
        </div>
    </div>
</template>
