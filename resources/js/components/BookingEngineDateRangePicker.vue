<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    checkIn: string;
    checkOut: string;
    calendarUrl: string;
    currency: string;
}>();

const emit = defineEmits<{
    (e: 'update:checkIn', value: string): void;
    (e: 'update:checkOut', value: string): void;
    (e: 'apply'): void;
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

function addDays(iso: string, days: number): string {
    const date = parseIso(iso);
    date.setDate(date.getDate() + days);

    return toIso(date);
}

function formatDisplay(iso: string): string {
    if (!iso) {
        return '';
    }

    const date = parseIso(iso);
    const weekday = date.toLocaleDateString(undefined, { weekday: 'short' });
    const day = String(date.getDate()).padStart(2, '0');
    const month = date.toLocaleDateString(undefined, { month: 'long' });

    return `${day} ${month} ${date.getFullYear()}, ${weekday}`;
}

/** Days before today can't be booked. */
const todayIso = toIso(new Date());

function isPast(iso: string): boolean {
    return iso < todayIso;
}

const leftMonth = ref(parseIso(props.checkIn || todayIso));

/** The calendar never pages back past the current month. */
const canGoToPreviousMonth = computed(() => {
    const now = new Date();

    return (
        leftMonth.value.getFullYear() * 12 + leftMonth.value.getMonth() >
        now.getFullYear() * 12 + now.getMonth()
    );
});
const rightMonth = computed(() => {
    const next = new Date(leftMonth.value);
    next.setMonth(next.getMonth() + 1);

    return next;
});

watch(open, (value) => {
    if (value) {
        leftMonth.value = parseIso(
            props.checkIn && !isPast(props.checkIn) ? props.checkIn : todayIso,
        );
    }
});

function shiftMonths(delta: number): void {
    if (delta < 0 && !canGoToPreviousMonth.value) {
        return;
    }

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

type DayRate = { available: boolean; price: number | null };

const rates = ref<Record<string, DayRate>>({});
let fetchToken = 0;

async function fetchRates(): Promise<void> {
    const from = leftDays.value[0].iso;
    const to = addDays(rightDays.value[rightDays.value.length - 1].iso, 1);
    const token = ++fetchToken;

    try {
        const response = await fetch(
            `${props.calendarUrl}?from=${from}&to=${to}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            return;
        }

        const data = (await response.json()) as {
            days: { date: string; available: boolean; price: number | null }[];
        };

        if (token !== fetchToken) {
            return;
        }

        const map: Record<string, DayRate> = {};
        data.days.forEach((day) => {
            map[day.date] = { available: day.available, price: day.price };
        });
        rates.value = map;
    } catch {
        // Calendar pricing is a nice-to-have — leave days unpriced on failure.
    }
}

watch(leftMonth, fetchRates, { immediate: true });

function rateFor(iso: string): DayRate | undefined {
    return rates.value[iso];
}

function isUnavailable(iso: string): boolean {
    if (isPast(iso)) {
        return true;
    }

    const rate = rateFor(iso);

    return rate !== undefined && !rate.available;
}

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
    // Picking the check-in date requires a room to actually be free that night.
    // The check-out date is just the departure boundary, so it's always pickable.
    if (
        (!props.checkIn || (props.checkIn && props.checkOut)) &&
        isUnavailable(iso)
    ) {
        return;
    }

    if (!props.checkIn || (props.checkIn && props.checkOut)) {
        emit('update:checkIn', iso);
        emit('update:checkOut', '');

        return;
    }

    if (iso <= props.checkIn) {
        if (isUnavailable(iso)) {
            return;
        }

        emit('update:checkIn', iso);
        emit('update:checkOut', '');

        return;
    }

    emit('update:checkOut', iso);
}

function onApplyDates(): void {
    if (props.checkIn && props.checkOut) {
        open.value = false;
        emit('apply');
    }
}

function onClickOutside(event: MouseEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', onClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));

function dayClasses(day: CalendarDay): (string | Record<string, boolean>)[] {
    const unavailable = isUnavailable(day.iso);

    return [
        !day.inCurrentMonth ? 'text-[#c7c7c3]' : 'text-[#1b1b18]',
        isInRange(day.iso) || isInHoverPreview(day.iso) ? 'bg-[#fde3db]' : '',
        {
            'scale-105 bg-[#f53003] font-semibold text-white hover:bg-[#f53003]':
                isSameDay(day.iso, checkInProp()) ||
                isSameDay(day.iso, checkOutProp()),
            'cursor-not-allowed opacity-40': unavailable,
            'hover:bg-[#f5f5f4]': !unavailable,
        },
    ];
}

// Small helpers so the template doesn't reach into props directly inside :class expressions.
function checkInProp(): string {
    return props.checkIn;
}
function checkOutProp(): string {
    return props.checkOut;
}
</script>

<template>
    <div ref="root" class="relative flex flex-wrap gap-3">
        <label class="flex w-48 flex-col gap-1 text-sm text-[#706f6c]">
            Check-In
            <button
                type="button"
                class="flex items-center justify-between gap-2 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-left text-sm text-[#1b1b18]"
                @click="open = !open"
            >
                <span class="truncate">{{
                    formatDisplay(checkIn) || 'Select date'
                }}</span>
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-4 w-4 shrink-0 text-[#706f6c]"
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
        </label>

        <label class="flex w-48 flex-col gap-1 text-sm text-[#706f6c]">
            Check-Out
            <button
                type="button"
                class="flex items-center justify-between gap-2 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-left text-sm text-[#1b1b18]"
                @click="open = !open"
            >
                <span class="truncate">{{
                    formatDisplay(checkOut) || 'Select date'
                }}</span>
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-4 w-4 shrink-0 text-[#706f6c]"
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
        </label>

        <div
            v-if="open"
            class="absolute top-full left-0 z-50 mt-2 w-[min(95vw,720px)] rounded-md border border-[#e3e3e0] bg-white p-4 shadow-lg"
        >
            <div class="mb-4 grid grid-cols-2 gap-4">
                <span
                    class="flex items-center gap-2 rounded-md border border-[#e3e3e0] px-2 py-1.5 text-sm text-[#1b1b18]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4 shrink-0 text-[#706f6c]"
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
                </span>
                <span
                    class="flex items-center gap-2 rounded-md border border-[#e3e3e0] px-2 py-1.5 text-sm text-[#1b1b18]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4 shrink-0 text-[#706f6c]"
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
                </span>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <button
                            type="button"
                            title="Previous month"
                            :disabled="!canGoToPreviousMonth"
                            class="rounded-md p-1 text-[#1b1b18] hover:bg-[#f5f5f4] disabled:cursor-not-allowed disabled:opacity-30"
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
                        <p class="text-sm font-semibold text-[#1b1b18]">
                            {{ monthNames[leftMonth.getMonth()] }}
                            {{ leftMonth.getFullYear() }}
                        </p>
                        <span class="h-4 w-4"></span>
                    </div>
                    <div class="grid grid-cols-7 gap-1 text-center text-xs">
                        <span
                            v-for="day in dayNames"
                            :key="day"
                            class="py-1 font-medium text-[#706f6c]"
                            >{{ day }}</span
                        >
                        <button
                            v-for="day in leftDays"
                            :key="day.iso"
                            type="button"
                            :disabled="isUnavailable(day.iso)"
                            class="flex flex-col items-center rounded-md py-1 text-sm transition-all duration-150 ease-out"
                            :class="dayClasses(day)"
                            @click="selectDay(day.iso)"
                            @mouseenter="onDayHover(day.iso)"
                            @mouseleave="onDayLeave"
                        >
                            <span>{{ day.date.getDate() }}</span>
                            <span
                                v-if="isUnavailable(day.iso)"
                                class="text-[10px] leading-tight"
                                >✕</span
                            >
                            <span
                                v-else-if="rateFor(day.iso)?.price != null"
                                class="text-[10px] leading-tight opacity-80"
                                >{{ rateFor(day.iso)!.price }}</span
                            >
                        </button>
                    </div>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <span class="h-4 w-4"></span>
                        <p class="text-sm font-semibold text-[#1b1b18]">
                            {{ monthNames[rightMonth.getMonth()] }}
                            {{ rightMonth.getFullYear() }}
                        </p>
                        <button
                            type="button"
                            title="Next month"
                            class="rounded-md p-1 text-[#1b1b18] hover:bg-[#f5f5f4]"
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
                            class="py-1 font-medium text-[#706f6c]"
                            >{{ day }}</span
                        >
                        <button
                            v-for="day in rightDays"
                            :key="day.iso"
                            type="button"
                            :disabled="isUnavailable(day.iso)"
                            class="flex flex-col items-center rounded-md py-1 text-sm transition-all duration-150 ease-out"
                            :class="dayClasses(day)"
                            @click="selectDay(day.iso)"
                            @mouseenter="onDayHover(day.iso)"
                            @mouseleave="onDayLeave"
                        >
                            <span>{{ day.date.getDate() }}</span>
                            <span
                                v-if="isUnavailable(day.iso)"
                                class="text-[10px] leading-tight"
                                >✕</span
                            >
                            <span
                                v-else-if="rateFor(day.iso)?.price != null"
                                class="text-[10px] leading-tight opacity-80"
                                >{{ rateFor(day.iso)!.price }}</span
                            >
                        </button>
                    </div>
                </div>
            </div>

            <div
                class="mt-3 flex items-center justify-between border-t border-[#e3e3e0] pt-3"
            >
                <p class="text-xs text-[#706f6c]">
                    Showing our lowest prices in {{ currency }}
                </p>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded border border-[#d1d5db] px-3 py-1.5 text-xs font-medium text-[#1b1b18] hover:bg-[#f5f5f4]"
                        @click="open = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="!checkIn || !checkOut"
                        class="rounded bg-[#4a7c59] px-4 py-1.5 text-xs font-semibold text-white uppercase hover:bg-[#3f6d4d] disabled:cursor-not-allowed disabled:opacity-50"
                        @click="onApplyDates"
                    >
                        Apply
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
