<script setup lang="ts">
import QuillEditor from '@/components/QuillEditor.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useLocationCascade } from '@/lib/useLocationCascade';
import { useTimeZoneOptions } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import type {
    FormFieldOption,
    MetaField,
    Option,
    PropertyGroup,
} from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
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
    metaFields: MetaField[];
}>();

const specialMetaFieldKeys = [
    'meal_plans',
    'checkin_checkout_time',
    'weekend_holidays',
    'checkin_checkout_policy',
    'payment_policy',
    'house_rules',
    'card_types',
    'payment_terms',
    'GRC_settings',
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

const address = useLocationCascade();
const corporateAddress = useLocationCascade();

const metaFieldValues: Record<number, { enabled: boolean; data: string }> = {};
props.metaFields.forEach((field) => {
    metaFieldValues[field.id] = { enabled: false, data: '' };
});

const timeZones = useTimeZoneOptions();

const form = useForm({
    name: '',
    property_legal_name: '',
    property_group_id: '',
    abbreviation: '',
    property_type: '',

    third_party_analytics_enabled: false,
    third_party_ad_tracking_enabled: false,
    third_party_analytics: {} as Record<number, string>,
    third_party_ad_tracking: {} as Record<number, string>,
    metasearch_platforms: {} as Record<number, string>,
    meta_fields: metaFieldValues,

    property_contact_number: '',
    outlet_sac_code_enabled: false,

    address_line_1: '',
    address_line_2: '',
    postal_code: '',
    country_id: '',
    state_id: '',
    city_id: '',

    corporate_address_permanent_address_same: false,
    corporate_address_line_1: '',
    corporate_address_line_2: '',
    corporate_postal_code: '',
    corporate_country_id: '',
    corporate_state_id: '',
    corporate_city_id: '',

    currency_id: '',
    timezone:
        Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Kolkata',
    ignore_decimal_places_in_bill_settlement: false,
    checkout_tolerance_value: '0.1000',

    language: '',

    location_map_url: '',
    latitude: '',
    longitude: '',
    property_segment: '',

    star_rating: '',
    tax_type: 1,
    metasearch_engines_listings_enabled: false,

    'customise_name_for+room_invoice': '',
});

function metaFieldDataError(fieldId: number): string | undefined {
    return (form.errors as Record<string, string>)[
        `meta_fields.${fieldId}.data`
    ];
}

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
    form.post(admin.properties.store().url);
}
</script>

<template>
    <Head title="Create property" />

    <h1
        class="font-display mb-6 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
    >
        Create property
    </h1>

    <form
        class="flex max-w-3xl flex-col gap-8"
        style="overflow-anchor: none"
        @submit.prevent="submit"
    >
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
                        <option value="" disabled>Select a star rating</option>
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
                <input v-model="form.outlet_sac_code_enabled" type="checkbox" />
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
                    <QuillEditor v-model="form.meta_fields[field.id].data" />
                    <p
                        v-if="metaFieldDataError(field.id)"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ metaFieldDataError(field.id) }}
                    </p>
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
                    <p class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
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

        <div class="flex items-center gap-3">
            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
            >
                {{ form.processing ? 'Creating...' : 'Create property' }}
            </button>
            <Link
                :href="admin.properties.index()"
                class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
            >
                Cancel
            </Link>
        </div>
    </form>
</template>
