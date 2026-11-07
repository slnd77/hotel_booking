<script setup lang="ts">
import HolidayTypeIcon from '@/components/HolidayTypeIcon.vue';
import {
    formatHolidayDate,
    holidayType,
    type HolidayDay,
} from '@/lib/holidays';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        holiday: HolidayDay;
        /** Which way the hover card opens. */
        placement?: 'below' | 'above';
    }>(),
    { placement: 'below' },
);

const type = computed(() => holidayType(props.holiday.type));
</script>

<template>
    <!-- The holiday type's icon; hovering it shows the holiday's details. -->
    <span class="group/holiday relative inline-flex">
        <HolidayTypeIcon :type="holiday.type" class="h-3 w-3" />
        <span
            role="tooltip"
            class="pointer-events-none absolute left-1/2 z-[60] hidden w-56 -translate-x-1/2 rounded-md border border-[#e3e3e0] bg-white p-2.5 text-left text-xs font-normal whitespace-normal text-[#1b1b18] shadow-lg group-hover/holiday:block dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
            :class="
                placement === 'below' ? 'top-full mt-1' : 'bottom-full mb-1'
            "
        >
            <span class="block font-semibold">{{ holiday.name }}</span>
            <span
                class="mt-0.5 flex items-center gap-1"
                :style="{ color: type.color }"
            >
                <HolidayTypeIcon :type="holiday.type" class="h-3 w-3" />
                {{ type.label }}
            </span>
            <span class="block text-[#706f6c] dark:text-[#A1A09A]">{{
                formatHolidayDate(holiday.date)
            }}</span>
            <span
                v-if="holiday.description"
                class="mt-1 block text-[#706f6c] dark:text-[#A1A09A]"
                >{{ holiday.description }}</span
            >
        </span>
    </span>
</template>
