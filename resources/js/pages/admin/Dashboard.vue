<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import SmartBookingChart from '@/components/SmartBookingChart.vue';
import type { ChartData } from '@/components/SmartBookingChart.vue';
import { useSelectedProperty } from '@/lib/useSelectedProperty';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    properties: { id: number; name: string; abbreviation: string }[];
    selectedPropertyId: number | null;
    smartChart: ChartData | null;
}>();

// The server renders the chart for the header's property (CurrentProperty), so both agree.
const { selectedPropertyId } = useSelectedProperty();

/**
 * Falls back to the property the server rendered the chart for, so the page
 * never flashes "select a property" before the header's selection is known.
 */
const selectedProperty = computed(() => {
    const storedId = Number(selectedPropertyId.value);

    return (
        props.properties.find((property) => property.id === storedId) ??
        props.properties.find(
            (property) => property.id === props.selectedPropertyId,
        ) ??
        null
    );
});
</script>

<template>
    <Head title="Smart Booking Chart" />

    <div
        v-if="!properties.length"
        class="rounded-lg border border-[#e3e3e0] p-6 text-base text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]"
    >
        No properties found.
    </div>

    <div
        v-else-if="!selectedProperty"
        class="rounded-lg border border-[#e3e3e0] p-6 text-base text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]"
    >
        Select a property from the dropdown at the top of the page.
    </div>

    <SmartBookingChart
        v-else
        :property="selectedProperty"
        :initial-data="smartChart"
    />
</template>
