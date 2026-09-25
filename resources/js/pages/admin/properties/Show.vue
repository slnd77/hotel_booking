<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import type { Property } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

defineOptions({ layout: AdminLayout });

defineProps<{
    property: Property;
}>();
</script>

<template>
    <Head title="Property" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            {{ property.name }}
        </h1>
        <div class="flex items-center gap-3">
            <Link
                :href="admin.properties.edit(property)"
                class="inline-flex items-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
            >
                Edit
            </Link>
            <Link
                :href="admin.properties.index()"
                class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
            >
                Back
            </Link>
        </div>
    </div>

    <div class="flex max-w-3xl flex-col gap-6">
        <section>
            <h2
                class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Basic info
            </h2>
            <dl
                class="divide-y divide-[#e3e3e0] rounded-lg border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
            >
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Legal name
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ property.property_legal_name }}
                    </dd>
                </div>
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Property group
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ property.property_group?.name ?? '—' }}
                    </dd>
                </div>
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Abbreviation
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ property.abbreviation }}
                    </dd>
                </div>
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Star rating
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ property.star_rating }}
                    </dd>
                </div>
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Contact number
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ property.property_contact_number ?? '—' }}
                    </dd>
                </div>
            </dl>
        </section>

        <section>
            <h2
                class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Address
            </h2>
            <dl
                class="divide-y divide-[#e3e3e0] rounded-lg border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
            >
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Address
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ property.address_line_1 }},
                        {{ property.address_line_2 }}
                    </dd>
                </div>
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        City / State / Country
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{
                            [
                                property.city?.name,
                                property.state?.name,
                                property.country?.name,
                            ]
                                .filter(Boolean)
                                .join(', ')
                        }}
                    </dd>
                </div>
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Postal code
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ property.postal_code }}
                    </dd>
                </div>
            </dl>
        </section>

        <section>
            <h2
                class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Corporate address
            </h2>
            <dl
                class="divide-y divide-[#e3e3e0] rounded-lg border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
            >
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Address
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ property.corporate_address_line_1 }},
                        {{ property.corporate_address_line_2 }}
                    </dd>
                </div>
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        City / State / Country
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{
                            [
                                property.corporate_city?.name,
                                property.corporate_state?.name,
                                property.corporate_country?.name,
                            ]
                                .filter(Boolean)
                                .join(', ')
                        }}
                    </dd>
                </div>
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Postal code
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ property.corporate_postal_code }}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            v-if="
                property.third_party_analytics_enabled &&
                property.third_party_analytics?.length
            "
        >
            <h2
                class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Third-party analytics
            </h2>
            <dl
                class="divide-y divide-[#e3e3e0] rounded-lg border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
            >
                <div
                    v-for="provider in property.third_party_analytics"
                    :key="provider.id"
                    class="flex justify-between px-4 py-3"
                >
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{ provider.name }}
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ provider.pivot?.tracking_id ?? '—' }}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            v-if="
                property.third_party_ad_tracking_enabled &&
                property.third_party_ad_tracking?.length
            "
        >
            <h2
                class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Third-party ad tracking
            </h2>
            <dl
                class="divide-y divide-[#e3e3e0] rounded-lg border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
            >
                <div
                    v-for="provider in property.third_party_ad_tracking"
                    :key="provider.id"
                    class="flex justify-between px-4 py-3"
                >
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{ provider.name }}
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ provider.pivot?.tracking_id ?? '—' }}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            v-if="
                property.metasearch_engines_listings_enabled &&
                property.metasearch_platforms?.length
            "
        >
            <h2
                class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Meta search platforms
            </h2>
            <dl
                class="divide-y divide-[#e3e3e0] rounded-lg border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
            >
                <div
                    v-for="platform in property.metasearch_platforms"
                    :key="platform.id"
                    class="flex justify-between px-4 py-3"
                >
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{ platform.name }}
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ platform.pivot?.listing_id ?? '—' }}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            v-if="
                property.meta_field_values?.some(
                    (value) => value.enabled && value.data,
                )
            "
        >
            <h2
                class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Terms & Inclusions
            </h2>
            <dl
                class="divide-y divide-[#e3e3e0] rounded-lg border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
            >
                <template
                    v-for="value in property.meta_field_values"
                    :key="value.id"
                >
                    <div
                        v-if="value.enabled && value.data"
                        class="flex flex-col gap-1 px-4 py-3"
                    >
                        <dt
                            class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{ value.meta_field?.key_label }}
                        </dt>
                        <dd
                            class="text-sm whitespace-pre-line text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ value.data }}
                        </dd>
                    </div>
                </template>
            </dl>
        </section>

        <section>
            <h2
                class="font-display mb-2 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Currency, billing & location
            </h2>
            <dl
                class="divide-y divide-[#e3e3e0] rounded-lg border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
            >
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Checkout tolerance value
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ property.checkout_tolerance_value }}
                    </dd>
                </div>
                <div class="flex justify-between px-4 py-3">
                    <dt
                        class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Location map
                    </dt>
                    <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        <a
                            v-if="property.location_map_url"
                            :href="property.location_map_url"
                            target="_blank"
                            class="underline underline-offset-4"
                        >
                            View map
                        </a>
                        <span v-else>—</span>
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</template>
