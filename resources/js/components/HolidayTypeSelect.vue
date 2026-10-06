<script setup lang="ts">
import HolidayTypeIcon from '@/components/HolidayTypeIcon.vue';
import { HOLIDAY_TYPES, holidayType, type HolidayType } from '@/lib/holidays';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const type = defineModel<HolidayType>({ required: true });

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const selected = computed(() => holidayType(type.value));

function choose(value: HolidayType): void {
    type.value = value;
    open.value = false;
}

function onClickOutside(event: MouseEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', onClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
    <!-- A select with each type's icon before its name (a native <select> can't show icons). -->
    <div ref="root" class="relative">
        <button
            type="button"
            class="flex w-full items-center gap-2 rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-left text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            aria-haspopup="listbox"
            :aria-expanded="open"
            @click="open = !open"
            @keydown.escape="open = false"
        >
            <HolidayTypeIcon :type="selected.value" class="h-4 w-4" />
            <span class="flex-1">{{ selected.label }}</span>
            <svg
                class="h-4 w-4 text-[#706f6c]"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
            >
                <path
                    fill-rule="evenodd"
                    d="M5.2 7.2a.75.75 0 0 1 1.06 0L10 10.94l3.74-3.74a.75.75 0 1 1 1.06 1.06l-4.27 4.27a.75.75 0 0 1-1.06 0L5.2 8.26a.75.75 0 0 1 0-1.06Z"
                />
            </svg>
        </button>
        <ul
            v-if="open"
            role="listbox"
            class="absolute right-0 left-0 z-50 mt-1 overflow-hidden rounded-md border border-[#e3e3e0] bg-white py-1 shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <li
                v-for="option in HOLIDAY_TYPES"
                :key="option.value"
                role="option"
                :aria-selected="option.value === selected.value"
            >
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    :class="
                        option.value === selected.value
                            ? 'bg-[#f5f5f4] font-medium dark:bg-[#1D1D1B]'
                            : ''
                    "
                    @click="choose(option.value)"
                >
                    <HolidayTypeIcon :type="option.value" class="h-4 w-4" />
                    {{ option.label }}
                </button>
            </li>
        </ul>
    </div>
</template>
