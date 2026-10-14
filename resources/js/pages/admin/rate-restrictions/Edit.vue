<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import { usePropertyTime } from '@/lib/usePropertyTime';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineOptions({ layout: AdminLayout });

type Restriction = {
    min_stay: number | null;
    max_stay: number | null;
    closed_to_arrival: boolean;
    closed_to_departure: boolean;
    stop_sell: boolean;
};

const props = defineProps<{
    property: { id: number; name: string };
    today: string;
    from: string;
    to: string;
    roomCategories: { id: number; name: string }[];
    /** date => room category id => what applies (property-wide and the category's own, combined). */
    effective: Record<string, Record<string, Restriction>>;
    propertyWide: Record<string, Restriction>;
    activityLogs: { action: string; user: string | null; at: string | null }[];
}>();

const propertyTime = usePropertyTime();
const activityLogsOpen = ref(false);

function addDays(iso: string, days: number): string {
    const date = new Date(`${iso}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

const dates = computed(() => {
    const list: string[] = [];

    for (let date = props.from; date <= props.to; date = addDays(date, 1)) {
        list.push(date);
    }

    return list;
});

function dayLabel(iso: string): {
    weekday: string;
    day: string;
    month: string;
} {
    const date = new Date(`${iso}T00:00:00Z`);

    return {
        weekday: date.toLocaleDateString('en-GB', {
            weekday: 'short',
            timeZone: 'UTC',
        }),
        day: String(date.getUTCDate()),
        month: date.toLocaleDateString('en-GB', {
            month: 'short',
            timeZone: 'UTC',
        }),
    };
}

function cell(date: string, categoryId: number): Restriction | null {
    return props.effective[date]?.[String(categoryId)] ?? null;
}

function go(from: string): void {
    router.get(
        admin.rateRestrictions.edit(props.property.id).url,
        { from },
        { preserveScroll: true, preserveState: false },
    );
}

type Tri = 'keep' | 'on' | 'off';

const weekdayOptions = [
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
    { value: 0, label: 'Sun' },
];

const editor = ref({
    from: props.from,
    to: addDays(props.from, 6),
    weekdays: [] as number[],
    room_category_ids: [] as number[],
    change_min: false,
    min_stay: null as number | null,
    change_max: false,
    max_stay: null as number | null,
    closed_to_arrival: 'keep' as Tri,
    closed_to_departure: 'keep' as Tri,
    stop_sell: 'keep' as Tri,
});

const form = useForm({
    action: 'set' as 'set' | 'clear',
    from: '',
    to: '',
    weekdays: [] as number[],
    room_category_ids: [] as number[],
    values: {} as Record<string, number | boolean | null>,
});

function payload(
    action: 'set' | 'clear',
): typeof form.data extends () => infer T ? T : never {
    const values: Record<string, number | boolean | null> = {};
    const state = editor.value;

    if (state.change_min) {
        values.min_stay = state.min_stay || null;
    }

    if (state.change_max) {
        values.max_stay = state.max_stay || null;
    }

    (
        ['closed_to_arrival', 'closed_to_departure', 'stop_sell'] as const
    ).forEach((field) => {
        if (state[field] !== 'keep') {
            values[field] = state[field] === 'on';
        }
    });

    return {
        action,
        from: state.from,
        to: state.to,
        weekdays: state.weekdays,
        room_category_ids: state.room_category_ids,
        values,
    };
}

const nothingToChange = computed(
    () => Object.keys(payload('set').values).length === 0,
);

function save(action: 'set' | 'clear'): void {
    form.transform(() => payload(action)).put(
        admin.rateRestrictions.update(props.property.id).url,
        { preserveScroll: true },
    );
}

/** Clicking a date in the calendar picks it as the range to edit. */
function pick(date: string, categoryId: number | null): void {
    editor.value.from = date;
    editor.value.to = date;
    editor.value.room_category_ids = categoryId === null ? [] : [categoryId];
}

const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const labelClass =
    'flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]';
</script>

<template>
    <Head :title="`Restrictions: ${property.name}`" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <Link
                :href="admin.rateSettings.edit(property.id).url"
                class="text-xs text-[#706f6c] underline dark:text-[#A1A09A]"
                >Rate Settings</Link
            >
            <h1
                class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Stay Restrictions
            </h1>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Minimum and maximum stay and closed to arrival apply to stays
                arriving on the date; closed to departure to stays leaving on
                it; stop-sell to any stay that includes the night. They apply on
                the booking engine and the booking form, and go to the channel
                manager.
            </p>
        </div>
        <button
            type="button"
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
            @click="activityLogsOpen = !activityLogsOpen"
        >
            Activity Logs
        </button>
    </div>

    <div
        v-if="activityLogsOpen"
        class="mb-6 rounded-lg border border-[#e3e3e0] p-4 text-sm dark:border-[#3E3E3A]"
    >
        <p
            v-if="!activityLogs.length"
            class="text-[#706f6c] dark:text-[#A1A09A]"
        >
            No changes yet.
        </p>
        <ul v-else class="flex max-h-60 flex-col gap-1 overflow-y-auto">
            <li
                v-for="(log, index) in activityLogs"
                :key="index"
                class="dark:text-[#EDEDEC]"
            >
                {{ log.action }}
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >— {{ log.user ?? 'System' }},
                    {{ propertyTime.dateTime(log.at) }}</span
                >
            </li>
        </ul>
    </div>

    <section
        class="mb-6 flex flex-col gap-4 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
    >
        <h2 class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">
            Set restrictions
        </h2>
        <div class="flex flex-wrap items-end gap-3">
            <label :class="labelClass"
                >From<input
                    v-model="editor.from"
                    type="date"
                    :class="inputClass"
                    data-test="restriction-from"
            /></label>
            <label :class="labelClass"
                >To<input
                    v-model="editor.to"
                    type="date"
                    :min="editor.from"
                    :class="inputClass"
                    data-test="restriction-to"
            /></label>
            <fieldset
                class="flex flex-wrap items-center gap-2 text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                <legend class="mb-1">On (none ticked: every day)</legend>
                <label
                    v-for="day in weekdayOptions"
                    :key="day.value"
                    class="flex items-center gap-1 dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="editor.weekdays"
                        type="checkbox"
                        :value="day.value"
                        class="h-3.5 w-3.5"
                    />{{ day.label }}
                </label>
            </fieldset>
        </div>
        <fieldset class="flex flex-wrap items-center gap-3 text-sm">
            <legend class="mb-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                Room categories (none ticked: the whole property)
            </legend>
            <label
                v-for="category in roomCategories"
                :key="category.id"
                class="flex items-center gap-1 dark:text-[#EDEDEC]"
            >
                <input
                    v-model="editor.room_category_ids"
                    type="checkbox"
                    :value="category.id"
                    class="h-4 w-4"
                />{{ category.name }}
            </label>
        </fieldset>
        <div class="grid gap-3 sm:grid-cols-5">
            <div :class="labelClass">
                <label class="flex items-center gap-1"
                    ><input
                        v-model="editor.change_min"
                        type="checkbox"
                        class="h-3.5 w-3.5"
                        data-test="restriction-change-min"
                    />Minimum stay</label
                >
                <input
                    v-model.number="editor.min_stay"
                    data-test="restriction-min"
                    type="number"
                    min="1"
                    max="365"
                    :disabled="!editor.change_min"
                    placeholder="none"
                    :class="inputClass"
                />
            </div>
            <div :class="labelClass">
                <label class="flex items-center gap-1"
                    ><input
                        v-model="editor.change_max"
                        type="checkbox"
                        class="h-3.5 w-3.5"
                    />Maximum stay</label
                >
                <input
                    v-model.number="editor.max_stay"
                    type="number"
                    min="1"
                    max="365"
                    :disabled="!editor.change_max"
                    placeholder="none"
                    :class="inputClass"
                />
            </div>
            <label
                v-for="field in [
                    { key: 'closed_to_arrival', label: 'Closed to arrival' },
                    {
                        key: 'closed_to_departure',
                        label: 'Closed to departure',
                    },
                    { key: 'stop_sell', label: 'Stop sell' },
                ] as const"
                :key="field.key"
                :class="labelClass"
            >
                {{ field.label }}
                <select v-model="editor[field.key]" :class="inputClass">
                    <option value="keep">No change</option>
                    <option value="on">On</option>
                    <option value="off">Off</option>
                </select>
            </label>
        </div>
        <p
            v-for="(message, key) in form.errors"
            :key="key"
            class="text-xs text-red-600"
        >
            {{ message }}
        </p>
        <div class="flex gap-2">
            <button
                type="button"
                data-test="restriction-apply"
                :disabled="form.processing || nothingToChange"
                class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                @click="save('set')"
            >
                Apply
            </button>
            <button
                type="button"
                :disabled="form.processing"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#f53003] dark:border-[#3E3E3A]"
                @click="save('clear')"
            >
                {{
                    editor.room_category_ids.length
                        ? "Clear these categories' restrictions on these dates"
                        : 'Clear all restrictions on these dates'
                }}
            </button>
        </div>
    </section>

    <div class="mb-2 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">
            {{ dayLabel(from).day }} {{ dayLabel(from).month }} –
            {{ dayLabel(to).day }} {{ dayLabel(to).month }}
        </h2>
        <div class="flex gap-2">
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="go(addDays(from, -42))"
            >
                ‹ Earlier
            </button>
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="go(today)"
            >
                Today
            </button>
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="go(addDays(from, 42))"
            >
                Later ›
            </button>
        </div>
    </div>

    <div
        class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <table class="text-xs">
            <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                <tr>
                    <th
                        class="sticky left-0 z-10 min-w-40 bg-[#f5f5f4] px-3 py-2 text-left font-medium text-[#706f6c] dark:bg-[#1D1D1B] dark:text-[#A1A09A]"
                    >
                        Room category
                    </th>
                    <th
                        v-for="date in dates"
                        :key="date"
                        class="min-w-14 px-1 py-1 text-center font-medium"
                        :class="
                            date === today
                                ? 'text-[#f53003]'
                                : 'text-[#706f6c] dark:text-[#A1A09A]'
                        "
                    >
                        <div>{{ dayLabel(date).weekday }}</div>
                        <div class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                            {{ dayLabel(date).day }}
                        </div>
                        <div>{{ dayLabel(date).month }}</div>
                    </th>
                </tr>
            </thead>
            <tbody
                class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
            >
                <tr v-for="category in roomCategories" :key="category.id">
                    <td
                        class="sticky left-0 z-10 bg-white px-3 py-2 font-medium text-[#1b1b18] dark:bg-[#161615] dark:text-[#EDEDEC]"
                    >
                        {{ category.name }}
                    </td>
                    <td
                        v-for="date in dates"
                        :key="date"
                        class="h-14 cursor-pointer border-l border-[#e3e3e0] px-0.5 py-0.5 align-top hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:hover:bg-[#1D1D1B]"
                        :class="
                            cell(date, category.id)?.stop_sell
                                ? 'bg-red-50 dark:bg-red-950/40'
                                : ''
                        "
                        :title="`Edit ${category.name} on ${date}`"
                        @click="pick(date, category.id)"
                    >
                        <template v-if="cell(date, category.id)">
                            <span
                                v-if="cell(date, category.id)!.stop_sell"
                                class="block rounded bg-red-600 px-1 text-[10px] font-bold text-white"
                                >STOP</span
                            >
                            <span
                                v-if="cell(date, category.id)!.min_stay"
                                class="block text-[10px] text-[#1b1b18] dark:text-[#EDEDEC]"
                                >min
                                {{ cell(date, category.id)!.min_stay }}</span
                            >
                            <span
                                v-if="cell(date, category.id)!.max_stay"
                                class="block text-[10px] text-[#1b1b18] dark:text-[#EDEDEC]"
                                >max
                                {{ cell(date, category.id)!.max_stay }}</span
                            >
                            <span
                                v-if="
                                    cell(date, category.id)!.closed_to_arrival
                                "
                                class="block text-[10px] font-semibold text-amber-700"
                                >CTA</span
                            >
                            <span
                                v-if="
                                    cell(date, category.id)!.closed_to_departure
                                "
                                class="block text-[10px] font-semibold text-amber-700"
                                >CTD</span
                            >
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-[#706f6c] dark:text-[#A1A09A]">
        CTA closed to arrival · CTD closed to departure · STOP stop-sell. Click
        a date to edit it.
        <template v-if="Object.keys(propertyWide).length"
            >Property-wide restrictions are included in every row.</template
        >
    </p>
</template>
