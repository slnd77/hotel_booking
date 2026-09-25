<script setup lang="ts">
import HolidayMarker from '@/components/HolidayMarker.vue';
import { holidayOn, type Holiday } from '@/lib/holidays';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    checkIn: string;
    checkOut: string;
    /** Marked with a flag; hovering it shows the details. */
    holidays?: Holiday[];
    /** An inclusive range (e.g. a holiday): clicking the first day again ends a one-day range. */
    allowSameDay?: boolean;
    /** Open the calendar towards the left, for a field near the right edge. */
    alignRight?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:checkIn', value: string): void;
    (e: 'update:checkOut', value: string): void;
}>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const hoveredIso = ref('');

const selectingCheckOut = computed(
    () => Boolean(props.checkIn) && !props.checkOut,
);

const monthNames = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];
const dayNames = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

function parseIso(iso: string): Date {
    return new Date(`${iso}T00:00:00`);
}

function toIso(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function formatDisplay(iso: string): string {
    if (!iso) {
        return '';
    }

    const date = parseIso(iso);

    return `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}/${date.getFullYear()}`;
}

const leftMonth = ref(parseIso(props.checkIn || toIso(new Date())));
const rightMonth = computed(() => {
    const next = new Date(leftMonth.value);
    next.setMonth(next.getMonth() + 1);

    return next;
});

watch(open, (value) => {
    if (value) {
        leftMonth.value = parseIso(props.checkIn || toIso(new Date()));
    }
});

function shiftMonths(delta: number): void {
    const next = new Date(leftMonth.value);
    next.setMonth(next.getMonth() + delta);
    leftMonth.value = next;
}

type CalendarDay = { date: Date; iso: string; inCurrentMonth: boolean };

function buildMonthGrid(month: Date): CalendarDay[] {
    const year = month.getFullYear();
    const monthIndex = month.getMonth();
    const firstOfMonth = new Date(year, monthIndex, 1);
    // Monday-first offset: getDay() 0=Sun..6=Sat, so shift so Monday=0.
    const startOffset = (firstOfMonth.getDay() + 6) % 7;

    const gridStart = new Date(year, monthIndex, 1 - startOffset);

    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(gridStart);
        date.setDate(gridStart.getDate() + index);

        return {
            date,
            iso: toIso(date),
            inCurrentMonth: date.getMonth() === monthIndex,
        };
    });
}

const leftDays = computed(() => buildMonthGrid(leftMonth.value));
const rightDays = computed(() => buildMonthGrid(rightMonth.value));

function isSameDay(a: string, b: string): boolean {
    return a !== '' && a === b;
}

function isInRange(iso: string): boolean {
    if (!props.checkIn || !props.checkOut) {
        return false;
    }

    return iso > props.checkIn && iso < props.checkOut;
}

function isInHoverPreview(iso: string): boolean {
    if (
        !selectingCheckOut.value ||
        !hoveredIso.value ||
        hoveredIso.value <= props.checkIn
    ) {
        return false;
    }

    return iso > props.checkIn && iso <= hoveredIso.value;
}

function onDayHover(iso: string): void {
    if (selectingCheckOut.value) {
        hoveredIso.value = iso;
    }
}

function onDayLeave(): void {
    hoveredIso.value = '';
}

function selectDay(iso: string): void {
    if (!props.checkIn || (props.checkIn && props.checkOut)) {
        emit('update:checkIn', iso);
        emit('update:checkOut', '');

        return;
    }

    if (props.allowSameDay && iso === props.checkIn) {
        emit('update:checkOut', iso);
        open.value = false;

        return;
    }

    if (iso <= props.checkIn) {
        emit('update:checkIn', iso);
        emit('update:checkOut', '');

        return;
    }

    emit('update:checkOut', iso);
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
    <div ref="root" class="relative">
        <button
            type="button"
            class="flex w-full items-center justify-between gap-2 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            @click="open = !open"
        >
            <span
                >{{ formatDisplay(checkIn) }} |
                {{ formatDisplay(checkOut) }}</span
            >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 shrink-0 text-[#706f6c] dark:text-[#A1A09A]"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"
                />
            </svg>
        </button>

        <div
            v-if="open"
            class="absolute z-50 mt-2 w-[min(90vw,640px)] rounded-md border border-[#e3e3e0] bg-white p-4 shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615]"
            :class="alignRight ? 'right-0' : 'left-0'"
        >
            <div class="mb-4 grid grid-cols-2 gap-4">
                <label
                    class="flex items-center gap-2 rounded-md border border-[#e3e3e0] px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4 shrink-0 text-[#706f6c] dark:text-[#A1A09A]"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"
                        />
                    </svg>
                    {{ formatDisplay(checkIn) }}
                </label>
                <label
                    class="flex items-center gap-2 rounded-md border border-[#e3e3e0] px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4 shrink-0 text-[#706f6c] dark:text-[#A1A09A]"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"
                        />
                    </svg>
                    {{ formatDisplay(checkOut) }}
                </label>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <button
                            type="button"
                            title="Previous month"
                            class="rounded-md p-1 text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                            @click="shiftMonths(-1)"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M15 19l-7-7 7-7"
                                />
                            </svg>
                        </button>
                        <p
                            class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ monthNames[leftMonth.getMonth()] }}
                            {{ leftMonth.getFullYear() }}
                        </p>
                        <span class="h-4 w-4"></span>
                    </div>
                    <div class="grid grid-cols-7 gap-1 text-center text-xs">
                        <span
                            v-for="day in dayNames"
                            :key="day"
                            class="py-1 font-medium text-[#706f6c] dark:text-[#A1A09A]"
                            >{{ day }}</span
                        >
                        <button
                            v-for="day in leftDays"
                            :key="day.iso"
                            type="button"
                            class="relative rounded-md py-1.5 text-sm transition-all duration-150 ease-out"
                            :class="[
                                !day.inCurrentMonth
                                    ? 'text-[#c7c7c3] dark:text-[#4a4a47]'
                                    : 'text-[#1b1b18] dark:text-[#EDEDEC]',
                                isInRange(day.iso) || isInHoverPreview(day.iso)
                                    ? 'bg-[#fde3db] dark:bg-[#3a2018]'
                                    : '',
                                isSameDay(day.iso, checkIn) ||
                                isSameDay(day.iso, checkOut)
                                    ? 'scale-105 bg-[#f53003] font-semibold text-white hover:bg-[#f53003]'
                                    : hoveredIso === day.iso &&
                                        selectingCheckOut
                                      ? 'scale-105 bg-[#f53003]/80 font-semibold text-white'
                                      : 'hover:bg-[#f5f5f4] dark:hover:bg-[#1D1D1B]',
                            ]"
                            @click="selectDay(day.iso)"
                            @mouseenter="onDayHover(day.iso)"
                            @mouseleave="onDayLeave"
                        >
                            {{ day.date.getDate() }}
                            <HolidayMarker
                                v-if="
                                    day.inCurrentMonth &&
                                    holidayOn(day.iso, holidays)
                                "
                                :holiday="holidayOn(day.iso, holidays)!"
                                class="absolute -top-0.5 right-0"
                            />
                        </button>
                    </div>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <span class="h-4 w-4"></span>
                        <p
                            class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ monthNames[rightMonth.getMonth()] }}
                            {{ rightMonth.getFullYear() }}
                        </p>
                        <button
                            type="button"
                            title="Next month"
                            class="rounded-md p-1 text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                            @click="shiftMonths(1)"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M9 5l7 7-7 7"
                                />
                            </svg>
                        </button>
                    </div>
                    <div class="grid grid-cols-7 gap-1 text-center text-xs">
                        <span
                            v-for="day in dayNames"
                            :key="day"
                            class="py-1 font-medium text-[#706f6c] dark:text-[#A1A09A]"
                            >{{ day }}</span
                        >
                        <button
                            v-for="day in rightDays"
                            :key="day.iso"
                            type="button"
                            class="relative rounded-md py-1.5 text-sm transition-all duration-150 ease-out"
                            :class="[
                                !day.inCurrentMonth
                                    ? 'text-[#c7c7c3] dark:text-[#4a4a47]'
                                    : 'text-[#1b1b18] dark:text-[#EDEDEC]',
                                isInRange(day.iso) || isInHoverPreview(day.iso)
                                    ? 'bg-[#fde3db] dark:bg-[#3a2018]'
                                    : '',
                                isSameDay(day.iso, checkIn) ||
                                isSameDay(day.iso, checkOut)
                                    ? 'scale-105 bg-[#f53003] font-semibold text-white hover:bg-[#f53003]'
                                    : hoveredIso === day.iso &&
                                        selectingCheckOut
                                      ? 'scale-105 bg-[#f53003]/80 font-semibold text-white'
                                      : 'hover:bg-[#f5f5f4] dark:hover:bg-[#1D1D1B]',
                            ]"
                            @click="selectDay(day.iso)"
                            @mouseenter="onDayHover(day.iso)"
                            @mouseleave="onDayLeave"
                        >
                            {{ day.date.getDate() }}
                            <HolidayMarker
                                v-if="
                                    day.inCurrentMonth &&
                                    holidayOn(day.iso, holidays)
                                "
                                :holiday="holidayOn(day.iso, holidays)!"
                                class="absolute -top-0.5 right-0"
                            />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
