<script setup lang="ts">
import DateRangePicker from '@/components/DateRangePicker.vue';
import HolidayMarker from '@/components/HolidayMarker.vue';
import HolidayTypeIcon from '@/components/HolidayTypeIcon.vue';
import HolidayTypeSelect from '@/components/HolidayTypeSelect.vue';
import {
    HOLIDAY_TYPES,
    datesBetween,
    formatHolidayDate,
    holidayOn,
    holidayType,
    type Holiday,
    type HolidayDetail,
    type HolidayType,
} from '@/lib/holidays';
import admin from '@/routes/admin';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    propertyId: number;
    /** Today in the property's timezone (Y-m-d). */
    today: string;
    /** Weekend days, 0 = Monday … 6 = Sunday, shown in red. */
    weekendDays: number[];
}>();

const holidays = defineModel<Holiday[]>({ required: true });

const MONTHS = [
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
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const fieldClass =
    'block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]';
const labelClass =
    'mb-0.5 block text-[11px] font-medium text-[#706f6c] dark:text-[#A1A09A]';

const todayYear = Number(props.today.slice(0, 4));
const year = ref(todayYear);
const yearOptions = computed(() =>
    [
        ...new Set([
            todayYear - 1,
            todayYear,
            todayYear + 1,
            todayYear + 2,
            year.value,
        ]),
    ].sort((a, b) => a - b),
);

type Draft = {
    from: string;
    to: string | null;
    day_wise: boolean;
    detail: HolidayDetail;
    /** Day-wise details by date, kept when the range changes. */
    days: Record<string, HolidayDetail>;
};

/** The holiday being added or edited in the Holiday Details panel. */
const draft = ref<Draft | null>(null);
const editingIndex = ref<number | null>(null);
const draftError = ref('');

function emptyDetail(type: HolidayType = 'national_holiday'): HolidayDetail {
    return { name: '', type, description: '' };
}

function ymd(y: number, month: number, day: number): string {
    return `${y}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/** Each month's day cells (null = padding before the 1st), Monday first. */
const months = computed(() =>
    MONTHS.map((name, month) => {
        const daysInMonth = new Date(
            Date.UTC(year.value, month + 1, 0),
        ).getUTCDate();
        const leading =
            (new Date(Date.UTC(year.value, month, 1)).getUTCDay() + 6) % 7;

        return {
            name,
            month,
            cells: [
                ...Array.from({ length: leading }, () => null),
                ...Array.from({ length: daysInMonth }, (_, i) =>
                    ymd(year.value, month, i + 1),
                ),
            ] as (string | null)[],
        };
    }),
);

const draftDates = computed(() =>
    draft.value
        ? datesBetween(draft.value.from, draft.value.to ?? draft.value.from)
        : [],
);

const draftRangeLabel = computed(() => {
    if (!draft.value) {
        return '';
    }

    const last = draft.value.to ?? draft.value.from;

    return last === draft.value.from
        ? formatHolidayDate(draft.value.from)
        : `${formatHolidayDate(draft.value.from, false)} - ${formatHolidayDate(last)}`;
});

function holidayIndexOn(date: string): number {
    return holidays.value.findIndex(
        (holiday) => holiday.from <= date && date <= holiday.to,
    );
}

function isWeekend(date: string): boolean {
    const [y, m, d] = date.split('-').map(Number);
    return props.weekendDays.includes(
        (new Date(Date.UTC(y, m - 1, d)).getUTCDay() + 6) % 7,
    );
}

function cellClass(date: string): string {
    if (draft.value && draftDates.value.includes(date)) {
        return 'bg-[#f97316] font-semibold text-white';
    }

    if (date === props.today) {
        return 'ring-1 ring-[#1b1b18] dark:ring-[#EDEDEC]';
    }

    return isWeekend(date)
        ? 'text-[#f53003]'
        : 'text-[#1b1b18] dark:text-[#EDEDEC]';
}

function openHoliday(index: number): void {
    const holiday = holidays.value[index];

    editingIndex.value = index;
    draftError.value = '';
    draft.value = {
        from: holiday.from,
        to: holiday.to,
        day_wise: Boolean(holiday.day_wise),
        detail: {
            name: holiday.name,
            type: holidayType(holiday.type).value,
            description: holiday.description ?? '',
        },
        days: Object.fromEntries(
            (holiday.days ?? []).map((day) => [
                day.date,
                {
                    name: day.name,
                    type: holidayType(day.type).value,
                    description: day.description ?? '',
                },
            ]),
        ),
    };
}

/**
 * A click starts a range, a second click ends it; a click on a marked day (with nothing
 * being picked) opens that holiday.
 */
function pick(date: string): void {
    draftError.value = '';

    if (!draft.value) {
        const index = holidayIndexOn(date);

        if (index !== -1) {
            openHoliday(index);
            return;
        }

        draft.value = {
            from: date,
            to: null,
            day_wise: false,
            detail: emptyDetail(),
            days: {},
        };
        return;
    }

    if (draft.value.to === null && date >= draft.value.from) {
        draft.value.to = date;
        return;
    }

    // A new range for the same details.
    draft.value.from = date;
    draft.value.to = null;
}

/** Day-wise entries start from the range's details, one per date in the range. */
watch(
    () => [draftDates.value, draft.value?.day_wise] as const,
    ([dates, dayWise]) => {
        if (draft.value && dayWise) {
            dates.forEach((date) => {
                draft.value!.days[date] ??= { ...draft.value!.detail };
            });
        }
    },
);

function dayDetail(date: string): HolidayDetail {
    return (
        draft.value?.days[date] ?? { ...(draft.value?.detail ?? emptyDetail()) }
    );
}

function setRangeStart(date: string): void {
    if (draft.value && date) {
        draft.value.from = date;
        draft.value.to = null;
        year.value = Number(date.slice(0, 4));
    }
}

function setRangeEnd(date: string): void {
    if (draft.value) {
        draft.value.to = date || null;
    }
}

function cancel(): void {
    draft.value = null;
    editingIndex.value = null;
    draftError.value = '';
}

function save(): void {
    if (!draft.value) {
        return;
    }

    const from = draft.value.from;
    const to = draft.value.to ?? draft.value.from;
    const dates = datesBetween(from, to);
    const dayWise = draft.value.day_wise && dates.length > 1;
    const days = dayWise
        ? dates.map((date) => ({
              date,
              ...dayDetail(date),
              name: dayDetail(date).name.trim(),
          }))
        : [];

    if (
        dayWise
            ? days.some((day) => !day.name)
            : !draft.value.detail.name.trim()
    ) {
        draftError.value = 'Enter a title for every day.';
        return;
    }

    if (
        holidays.value.some(
            (holiday, index) =>
                index !== editingIndex.value &&
                holiday.from <= to &&
                from <= holiday.to,
        )
    ) {
        draftError.value =
            'These dates overlap a holiday or event already on the calendar.';
        return;
    }

    const holiday: Holiday = {
        from,
        to,
        name: dayWise ? days[0].name : draft.value.detail.name.trim(),
        type: dayWise ? days[0].type : draft.value.detail.type,
        description: dayWise
            ? days[0].description
            : draft.value.detail.description.trim(),
        day_wise: dayWise,
        days,
    };

    holidays.value = [
        ...holidays.value.filter((_, index) => index !== editingIndex.value),
        holiday,
    ].sort((a, b) => a.from.localeCompare(b.from));
    cancel();
    persist();
}

const saving = ref(false);

/** Holidays save as soon as they change, so the Smart Chart and booking form show them. */
function persist(): void {
    router.put(
        admin.properties.holidays.update(props.propertyId).url,
        { holidays: holidays.value },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onError: (errors) =>
                (saveError.value =
                    Object.values(errors)[0] ?? 'Could not save.'),
            onSuccess: () => (saveError.value = ''),
        },
    );
}

const saveError = ref('');

function remove(): void {
    if (editingIndex.value !== null) {
        holidays.value = holidays.value.filter(
            (_, index) => index !== editingIndex.value,
        );
        persist();
    }

    cancel();
}

function goToday(): void {
    year.value = todayYear;
    cancel();
}

const holidaysThisYear = computed(() =>
    holidays.value
        .map((holiday, index) => ({ holiday, index }))
        .filter(
            ({ holiday }) =>
                holiday.from.startsWith(String(year.value)) ||
                holiday.to.startsWith(String(year.value)),
        ),
);
</script>

<template>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
        <div class="flex-1">
            <div class="mb-3 flex items-center gap-2">
                <button
                    type="button"
                    class="rounded border border-[#e3e3e0] px-2 py-1 text-xs text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    @click="goToday"
                >
                    Today
                </button>
                <select
                    v-model.number="year"
                    class="rounded border border-[#e3e3e0] bg-white px-2 py-1 text-xs text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                >
                    <option
                        v-for="option in yearOptions"
                        :key="option"
                        :value="option"
                    >
                        {{ option }}
                    </option>
                </select>
                <ul class="ml-auto flex flex-wrap items-center gap-x-4 gap-y-1">
                    <li
                        v-for="option in HOLIDAY_TYPES"
                        :key="option.value"
                        class="flex items-center gap-1 text-xs text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <HolidayTypeIcon
                            :type="option.value"
                            class="h-3.5 w-3.5"
                        />
                        {{ option.label }}
                    </li>
                </ul>
            </div>

            <div
                class="grid grid-cols-2 gap-x-6 gap-y-4 md:grid-cols-3 xl:grid-cols-4"
            >
                <div v-for="month in months" :key="month.month">
                    <p
                        class="mb-1 text-xs font-semibold"
                        :class="
                            year === todayYear &&
                            month.month === Number(today.slice(5, 7)) - 1
                                ? 'text-[#f97316]'
                                : 'text-[#1b1b18] dark:text-[#EDEDEC]'
                        "
                    >
                        {{ month.name }}
                    </p>
                    <div
                        class="grid grid-cols-7 gap-px text-center text-[11px]"
                    >
                        <span
                            v-for="weekday in WEEKDAYS"
                            :key="weekday"
                            class="font-semibold text-[#706f6c] dark:text-[#A1A09A]"
                            >{{ weekday }}</span
                        >
                        <template
                            v-for="(date, cell) in month.cells"
                            :key="cell"
                        >
                            <span v-if="date === null" />
                            <div v-else class="relative">
                                <button
                                    type="button"
                                    class="w-full rounded-full py-0.5 hover:bg-[#e3e3e0] dark:hover:bg-[#3E3E3A]"
                                    :class="cellClass(date)"
                                    @click="pick(date)"
                                >
                                    {{ Number(date.slice(8)) }}
                                </button>
                                <HolidayMarker
                                    v-if="holidayOn(date, holidays)"
                                    :holiday="holidayOn(date, holidays)!"
                                    class="absolute -top-1 -right-0.5"
                                />
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <aside
            class="flex w-full flex-col gap-3 rounded-md bg-[#e6eeec] p-4 text-sm lg:w-80 dark:bg-[#1D1D1B]"
        >
            <template v-if="draft">
                <div class="flex items-center justify-between gap-2">
                    <h3
                        class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Holiday Details
                    </h3>
                    <div class="flex gap-1.5">
                        <button
                            type="button"
                            class="rounded border border-[#706f6c] px-2 py-1 text-[11px] font-medium text-[#1b1b18] uppercase hover:bg-white dark:text-[#EDEDEC] dark:hover:bg-[#0a0a0a]"
                            @click="cancel"
                        >
                            Cancel
                        </button>
                        <button
                            v-if="editingIndex !== null"
                            type="button"
                            class="rounded border border-red-500 px-2 py-1 text-[11px] font-medium text-red-600 uppercase hover:bg-red-50 dark:hover:bg-red-950"
                            @click="remove"
                        >
                            Delete
                        </button>
                        <button
                            type="button"
                            class="rounded bg-[#16a34a] px-2 py-1 text-[11px] font-medium text-white uppercase hover:bg-[#15803d]"
                            @click="save"
                        >
                            {{
                                editingIndex !== null
                                    ? 'Update'
                                    : 'Add to Calendar'
                            }}
                        </button>
                    </div>
                </div>

                <div>
                    <span :class="labelClass">Select Date/Date Range</span>
                    <DateRangePicker
                        :check-in="draft.from"
                        :check-out="draft.to ?? ''"
                        :holidays="holidays"
                        allow-same-day
                        align-right
                        @update:check-in="setRangeStart"
                        @update:check-out="setRangeEnd"
                    />
                    <p
                        class="mt-1 text-[11px] text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{ draftRangeLabel }}
                    </p>
                    <p
                        v-if="draft.to === null"
                        class="mt-1 text-[11px] text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Pick the last day (the same day again for a one-day
                        holiday), here or on the calendar.
                    </p>
                </div>

                <label
                    v-if="draftDates.length > 1"
                    class="flex items-center justify-between gap-2 text-xs font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Do you want to add day wise detail?
                    <input v-model="draft.day_wise" type="checkbox" />
                </label>

                <div
                    class="flex max-h-[28rem] flex-col gap-3 overflow-y-auto pr-1"
                >
                    <template v-if="draft.day_wise && draftDates.length > 1">
                        <div
                            v-for="date in draftDates.filter(
                                (day) => draft!.days[day],
                            )"
                            :key="date"
                            class="flex flex-col gap-2 border-b border-dashed border-[#a1a09a] pb-3 last:border-b-0"
                        >
                            <p
                                class="text-xs font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ date.split('-').reverse().join('/') }}
                            </p>
                            <label>
                                <span :class="labelClass">Title</span>
                                <input
                                    v-model="draft.days[date].name"
                                    type="text"
                                    maxlength="100"
                                    :class="fieldClass"
                                />
                            </label>
                            <div>
                                <span :class="labelClass"
                                    >Select Holiday/Event Type</span
                                >
                                <HolidayTypeSelect
                                    v-model="draft.days[date].type"
                                />
                            </div>
                            <label>
                                <span :class="labelClass">Description</span>
                                <textarea
                                    v-model="draft.days[date].description"
                                    rows="2"
                                    maxlength="500"
                                    :class="fieldClass"
                                />
                            </label>
                        </div>
                    </template>
                    <template v-else>
                        <label>
                            <span :class="labelClass">Title</span>
                            <input
                                v-model="draft.detail.name"
                                type="text"
                                maxlength="100"
                                :class="fieldClass"
                                @keydown.enter.prevent="save"
                            />
                        </label>
                        <div>
                            <span :class="labelClass"
                                >Select Holiday/Event Type</span
                            >
                            <HolidayTypeSelect v-model="draft.detail.type" />
                        </div>
                        <label>
                            <span :class="labelClass">Description</span>
                            <textarea
                                v-model="draft.detail.description"
                                rows="3"
                                maxlength="500"
                                :class="fieldClass"
                            />
                        </label>
                    </template>
                </div>
                <p v-if="draftError" class="text-xs text-red-600">
                    {{ draftError }}
                </p>
            </template>

            <template v-else>
                <p
                    v-if="saving"
                    class="text-center text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Saving…
                </p>
                <p v-if="saveError" class="text-center text-xs text-red-600">
                    {{ saveError }}
                </p>
                <p
                    class="text-center text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Select a date/date range to add Events/Holidays/Long Weekend
                    Holidays or review Holiday Details.
                </p>
                <ul
                    v-if="holidaysThisYear.length"
                    class="flex flex-col gap-1.5"
                >
                    <li
                        v-for="{ holiday, index } in holidaysThisYear"
                        :key="`${holiday.from}-${index}`"
                        class="flex items-start gap-2"
                    >
                        <HolidayMarker
                            :holiday="holidayOn(holiday.from, holidays)!"
                            class="mt-1"
                        />
                        <button
                            type="button"
                            class="text-left text-xs text-[#1b1b18] hover:underline dark:text-[#EDEDEC]"
                            @click="openHoliday(index)"
                        >
                            {{ holiday.name
                            }}<template v-if="holiday.day_wise">
                                (day wise)</template
                            >
                            <span
                                class="block text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ formatHolidayDate(holiday.from)
                                }}<template v-if="holiday.to !== holiday.from">
                                    –
                                    {{
                                        formatHolidayDate(holiday.to)
                                    }}</template
                                >
                            </span>
                        </button>
                    </li>
                </ul>
            </template>
        </aside>
    </div>
</template>
