<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import { computed, ref } from 'vue';

export type PackageDayKey =
    | 'mon'
    | 'tue'
    | 'wed'
    | 'thu'
    | 'fri'
    | 'sat'
    | 'sun';

type ToggleNumber = { enabled: boolean; value: number | null };
type ToggleDateRange = {
    enabled: boolean;
    start: string | null;
    end: string | null;
};

/** A package: a rate plan sold for chosen room categories and meal plans, under its own conditions. */
export type RatePackage = {
    id: number;
    name: string;
    color: string;
    prorata: boolean;
    length_of_stay_linked: boolean;
    min_nights: number | null;
    max_nights: number | null;
    pay_at_hotel: boolean;
    override_inclusions: boolean;
    inclusions: string;
    override_terms: boolean;
    terms: string;
    show_at_booking_engine: boolean;
    booking_creation_from: string | null;
    booking_creation_to: string | null;
    /** Room category id => the meal plans the package is sold with. */
    rooms: Record<string, string[]>;
    cut_off_days: ToggleNumber;
    last_minute: ToggleNumber;
    usage_limit: ToggleNumber;
    checkin_date_range: ToggleDateRange;
    checkout_date_range: ToggleDateRange;
    stay_days_of_week: { enabled: boolean; days: PackageDayKey[] };
    applicable_on_pay_at_hotel: boolean;
    day_wise_breakup: boolean;
    rates: Record<string, Record<string, Record<PackageDayKey, number>>>;
};

const props = defineProps<{
    roomCategories: { id: number; name: string; meal_plans: string[] | null }[];
}>();

const packages = defineModel<RatePackage[]>({ required: true });

const emit = defineEmits<{
    /** The packages changed: save them. */
    save: [];
    /** Open the rate grid for this package. */
    manageRates: [id: number];
    deleted: [id: number];
}>();

const dayKeys: PackageDayKey[] = [
    'mon',
    'tue',
    'wed',
    'thu',
    'fri',
    'sat',
    'sun',
];
const dayLabels: Record<PackageDayKey, string> = {
    mon: 'Mon',
    tue: 'Tue',
    wed: 'Wed',
    thu: 'Thu',
    fri: 'Fri',
    sat: 'Sat',
    sun: 'Sun',
};

const PER_PAGE = 10;
const page = ref(1);
const pageCount = computed(() =>
    Math.max(1, Math.ceil(packages.value.length / PER_PAGE)),
);
const pagedPackages = computed(() =>
    packages.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE),
);

function emptyPackage(): RatePackage {
    return {
        id: packages.value.reduce((max, item) => Math.max(max, item.id), 0) + 1,
        name: '',
        color: '#7c3aed',
        prorata: false,
        length_of_stay_linked: false,
        min_nights: null,
        max_nights: null,
        pay_at_hotel: true,
        override_inclusions: false,
        inclusions: '',
        override_terms: false,
        terms: '',
        show_at_booking_engine: true,
        booking_creation_from: null,
        booking_creation_to: null,
        rooms: {},
        cut_off_days: { enabled: false, value: null },
        last_minute: { enabled: false, value: null },
        usage_limit: { enabled: false, value: null },
        checkin_date_range: { enabled: false, start: null, end: null },
        checkout_date_range: { enabled: false, start: null, end: null },
        stay_days_of_week: { enabled: false, days: [] },
        applicable_on_pay_at_hotel: false,
        day_wise_breakup: true,
        rates: {},
    };
}

function packageType(item: RatePackage): string {
    return item.length_of_stay_linked ? 'Length of Stay' : 'Standard';
}

const modalOpen = ref(false);
const editingId = ref<number | null>(null);
const packageForm = ref<RatePackage>(emptyPackage());
const formError = ref<string | null>(null);

function openAdd(): void {
    editingId.value = null;
    packageForm.value = emptyPackage();
    formError.value = null;
    modalOpen.value = true;
}

function openEdit(item: RatePackage): void {
    editingId.value = item.id;
    packageForm.value = {
        ...emptyPackage(),
        ...JSON.parse(JSON.stringify(item)),
    };
    formError.value = null;
    modalOpen.value = true;
}

function mealPlansOf(category: { meal_plans: string[] | null }): string[] {
    return category.meal_plans?.length ? category.meal_plans : ['EP'];
}

function isCategorySelected(categoryId: number): boolean {
    return packageForm.value.rooms[categoryId] !== undefined;
}

function toggleCategory(category: {
    id: number;
    meal_plans: string[] | null;
}): void {
    if (isCategorySelected(category.id)) {
        delete packageForm.value.rooms[category.id];
    } else {
        packageForm.value.rooms[category.id] = [...mealPlansOf(category)];
    }
}

function toggleMealPlan(categoryId: number, mealPlan: string): void {
    const plans = packageForm.value.rooms[categoryId] ?? [];
    packageForm.value.rooms[categoryId] = plans.includes(mealPlan)
        ? plans.filter((plan) => plan !== mealPlan)
        : [...plans, mealPlan];
}

function toggleStayDay(day: PackageDayKey): void {
    const days = packageForm.value.stay_days_of_week.days;
    packageForm.value.stay_days_of_week.days = days.includes(day)
        ? days.filter((item) => item !== day)
        : [...days, day];
}

function validationError(item: RatePackage): string | null {
    if (item.name.trim() === '') {
        return 'Enter the package name.';
    }

    if (
        packages.value.some(
            (other) =>
                other.id !== item.id &&
                other.name.trim().toLowerCase() ===
                    item.name.trim().toLowerCase(),
        )
    ) {
        return 'Another package already has this name.';
    }

    if (!Object.values(item.rooms).some((mealPlans) => mealPlans.length > 0)) {
        return 'Select at least one room category with a meal plan.';
    }

    if (
        item.length_of_stay_linked &&
        item.min_nights !== null &&
        item.max_nights !== null &&
        item.min_nights > item.max_nights
    ) {
        return "Min # of nights can't be more than Max # of nights.";
    }

    const ranges: [string | null, string | null, string][] = [
        [
            item.booking_creation_from,
            item.booking_creation_to,
            'Booking creation date range',
        ],
        ...(item.checkin_date_range.enabled
            ? [
                  [
                      item.checkin_date_range.start,
                      item.checkin_date_range.end,
                      'Check-in date range',
                  ] as [string | null, string | null, string],
              ]
            : []),
        ...(item.checkout_date_range.enabled
            ? [
                  [
                      item.checkout_date_range.start,
                      item.checkout_date_range.end,
                      'Check-out date range',
                  ] as [string | null, string | null, string],
              ]
            : []),
    ];

    for (const [from, to, label] of ranges) {
        if (from && to && from > to) {
            return `${label}: the end date is before the start date.`;
        }
    }

    if (
        item.stay_days_of_week.enabled &&
        item.stay_days_of_week.days.length === 0
    ) {
        return 'Pick at least one stay day of the week, or switch it off.';
    }

    return null;
}

function savePackage(): void {
    const item = packageForm.value;
    item.name = item.name.trim();

    // Categories with no meal plan left aren't sold.
    Object.keys(item.rooms).forEach((categoryId) => {
        if (!item.rooms[categoryId].length) {
            delete item.rooms[categoryId];
        }
    });

    formError.value = validationError(item);

    if (formError.value) {
        return;
    }

    if (!item.length_of_stay_linked) {
        item.min_nights = null;
        item.max_nights = null;
    }

    const index = packages.value.findIndex(
        (existing) => existing.id === editingId.value,
    );

    if (index === -1) {
        packages.value.push(item);
    } else {
        packages.value[index] = { ...item, rates: packages.value[index].rates };
    }

    modalOpen.value = false;
    emit('save');
}

const deletingPackage = ref<RatePackage | null>(null);

function confirmDelete(): void {
    const item = deletingPackage.value;

    if (!item) {
        return;
    }

    packages.value = packages.value.filter(
        (existing) => existing.id !== item.id,
    );
    page.value = Math.min(page.value, pageCount.value);
    deletingPackage.value = null;
    emit('deleted', item.id);
    emit('save');
}

const numberConditions: {
    key: 'cut_off_days' | 'last_minute' | 'usage_limit';
    label: string;
    info: string;
    offText: string;
    placeholder: string;
    unit: string;
}[] = [
    {
        key: 'cut_off_days',
        label: 'Cut Off Days',
        info: 'Stop selling the package this many days before check-in.',
        offText: 'Not applicable.',
        placeholder: 'Days',
        unit: 'days before check-in',
    },
    {
        key: 'last_minute',
        label: 'Last Minute',
        info: 'Sell the package only within this many days of check-in.',
        offText: 'Not applicable.',
        placeholder: 'Days',
        unit: 'days before check-in',
    },
    {
        key: 'usage_limit',
        label: 'No. of Times Package Can Be Booked',
        info: 'Stop selling the package after this many bookings.',
        offText: 'Infinite.',
        placeholder: 'Count',
        unit: 'total bookings',
    },
];

const dateConditions: {
    key: 'checkin_date_range' | 'checkout_date_range';
    label: string;
    info: string;
    offText: string;
}[] = [
    {
        key: 'checkin_date_range',
        label: 'Check-in date range',
        info: 'Sell the package only for check-ins in this range.',
        offText: 'Applied to all check-in dates.',
    },
    {
        key: 'checkout_date_range',
        label: 'Check-out date range',
        info: 'Sell the package only for check-outs in this range.',
        offText: 'Applied to all check-out dates.',
    },
];

const inputClass =
    'block rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]';
const checkboxClass =
    'h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]';
const labelClass =
    'flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]';
const thClass =
    'px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]';
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between">
            <div>
                <h2
                    class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Packages
                </h2>
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    Map packages to dates in Rate Planner → Packages Calendar.
                </p>
            </div>
            <button
                type="button"
                class="inline-flex items-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                @click="openAdd"
            >
                + Add
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
                        <th :class="thClass">Color</th>
                        <th :class="thClass">Name</th>
                        <th :class="thClass">Packages Type</th>
                        <th :class="thClass">Rates</th>
                        <th :class="thClass">Edit</th>
                        <th :class="thClass">Delete</th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                >
                    <tr v-for="item in pagedPackages" :key="item.id">
                        <td class="px-4 py-3">
                            <span
                                class="inline-block h-5 w-5 rounded border border-[#e3e3e0] dark:border-[#3E3E3A]"
                                :style="{ backgroundColor: item.color }"
                            />
                        </td>
                        <td
                            class="px-4 py-3 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ item.name }}
                        </td>
                        <td
                            class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{ packageType(item) }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <button
                                type="button"
                                class="font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                                @click="emit('manageRates', item.id)"
                            >
                                Manage Rates
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <button
                                type="button"
                                class="text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                                :title="`Edit ${item.name}`"
                                @click="openEdit(item)"
                            >
                                ✎
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <button
                                type="button"
                                class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A]"
                                :title="`Delete ${item.name}`"
                                @click="deletingPackage = item"
                            >
                                🗑
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!packages.length">
                        <td
                            colspan="6"
                            class="px-4 py-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            No packages yet. Add one to sell a stay with its own
                            rates and conditions.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="pageCount > 1"
            class="flex items-center justify-end gap-3 text-sm"
        >
            <button
                type="button"
                class="text-[#1b1b18] disabled:opacity-40 dark:text-[#EDEDEC]"
                :disabled="page === 1"
                @click="page--"
            >
                &laquo; Prev
            </button>
            <span class="text-[#706f6c] dark:text-[#A1A09A]"
                >{{ page }} / {{ pageCount }}</span
            >
            <button
                type="button"
                class="text-[#1b1b18] disabled:opacity-40 dark:text-[#EDEDEC]"
                :disabled="page === pageCount"
                @click="page++"
            >
                Next &raquo;
            </button>
        </div>

        <Modal
            :show="modalOpen"
            :title="editingId === null ? 'Add Package' : 'Edit Package'"
            max-width="max-w-2xl"
            @close="modalOpen = false"
        >
            <form class="flex flex-col gap-4" @submit.prevent="savePackage">
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    Please select the occupancies and meal plans for the Rates
                    need to be defined:
                </p>

                <div
                    class="grid grid-cols-1 items-center gap-3 sm:grid-cols-[120px_1fr]"
                >
                    <label
                        for="package_name"
                        class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Name</label
                    >
                    <input
                        id="package_name"
                        v-model="packageForm.name"
                        type="text"
                        maxlength="100"
                        :class="[inputClass, 'w-full sm:w-72']"
                    />

                    <label
                        for="package_color"
                        class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Color Code</label
                    >
                    <input
                        id="package_color"
                        v-model="packageForm.color"
                        type="color"
                        class="h-9 w-14 rounded-md border border-[#e3e3e0] dark:border-[#3E3E3A]"
                    />
                </div>

                <div class="flex flex-col gap-2">
                    <label
                        :class="labelClass"
                        title="Charge the package rate pro rata for stays shorter or longer than the package."
                    >
                        <input
                            v-model="packageForm.prorata"
                            type="checkbox"
                            :class="checkboxClass"
                        />
                        Prorata
                        <span class="cursor-help text-[#706f6c]">ⓘ</span>
                    </label>
                    <label
                        :class="labelClass"
                        title="Sell the package only for stays within a number of nights."
                    >
                        <input
                            v-model="packageForm.length_of_stay_linked"
                            type="checkbox"
                            :class="checkboxClass"
                        />
                        Length of Stay Linked Page
                        <span class="cursor-help text-[#706f6c]">ⓘ</span>
                    </label>
                    <label
                        :class="labelClass"
                        title="Guests can book the package and pay at the hotel."
                    >
                        <input
                            v-model="packageForm.pay_at_hotel"
                            type="checkbox"
                            :class="checkboxClass"
                        />
                        Enable Pay At Hotel
                        <span class="cursor-help text-[#706f6c]">ⓘ</span>
                    </label>
                </div>

                <div
                    v-if="packageForm.length_of_stay_linked"
                    class="flex flex-wrap items-center gap-4"
                >
                    <label
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        title="The shortest stay the package is sold for."
                    >
                        Min # Of nights
                        <input
                            v-model.number="packageForm.min_nights"
                            type="number"
                            min="1"
                            :class="[inputClass, 'w-20']"
                        />
                    </label>
                    <label
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        title="The longest stay the package is sold for."
                    >
                        Max # Of nights
                        <input
                            v-model.number="packageForm.max_nights"
                            type="number"
                            min="1"
                            :class="[inputClass, 'w-20']"
                        />
                    </label>
                </div>

                <div class="flex flex-col gap-2">
                    <label
                        :class="labelClass"
                        title="Show this package's own inclusions instead of the property's standard ones."
                    >
                        <input
                            v-model="packageForm.override_inclusions"
                            type="checkbox"
                            :class="checkboxClass"
                        />
                        Override other inclusions
                        <span class="cursor-help text-[#706f6c]">ⓘ</span>
                    </label>
                    <textarea
                        v-if="packageForm.override_inclusions"
                        v-model="packageForm.inclusions"
                        rows="3"
                        placeholder="What the package includes"
                        :class="inputClass"
                    />

                    <label
                        :class="labelClass"
                        title="Show this package's own terms and conditions instead of the property's standard ones."
                    >
                        <input
                            v-model="packageForm.override_terms"
                            type="checkbox"
                            :class="checkboxClass"
                        />
                        Override other T&amp;Cs
                        <span class="cursor-help text-[#706f6c]">ⓘ</span>
                    </label>
                    <textarea
                        v-if="packageForm.override_terms"
                        v-model="packageForm.terms"
                        rows="3"
                        placeholder="The package's terms and conditions"
                        :class="inputClass"
                    />

                    <label
                        :class="labelClass"
                        title="List the package on the booking engine."
                    >
                        <input
                            v-model="packageForm.show_at_booking_engine"
                            type="checkbox"
                            :class="checkboxClass"
                        />
                        Show At Booking Engine
                        <span class="cursor-help text-[#706f6c]">ⓘ</span>
                    </label>
                </div>

                <div
                    class="flex flex-wrap items-center gap-2"
                    title="Sell the package only to bookings made in this range."
                >
                    <span class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Booking creation date range
                        <span class="cursor-help text-[#706f6c]">ⓘ</span>:</span
                    >
                    <input
                        v-model="packageForm.booking_creation_from"
                        type="date"
                        aria-label="Booking creation from"
                        :class="inputClass"
                    />
                    <input
                        v-model="packageForm.booking_creation_to"
                        type="date"
                        aria-label="Booking creation to"
                        :class="inputClass"
                    />
                </div>

                <div
                    class="flex flex-col divide-y divide-[#e3e3e0] border-y border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
                >
                    <div
                        v-for="category in props.roomCategories"
                        :key="category.id"
                        class="flex flex-col gap-1.5 py-2"
                    >
                        <label
                            class="flex items-center gap-2 text-sm text-[#337ab7]"
                        >
                            <input
                                type="checkbox"
                                :checked="isCategorySelected(category.id)"
                                :class="checkboxClass"
                                @change="toggleCategory(category)"
                            />
                            {{ category.name }}
                        </label>
                        <div
                            v-if="isCategorySelected(category.id)"
                            class="flex flex-wrap items-center gap-3 pl-6"
                        >
                            <span
                                class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Meal Plan:</span
                            >
                            <label
                                v-for="mealPlan in mealPlansOf(category)"
                                :key="mealPlan"
                                class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                <input
                                    type="checkbox"
                                    :checked="
                                        packageForm.rooms[
                                            category.id
                                        ]?.includes(mealPlan)
                                    "
                                    :class="checkboxClass"
                                    @change="
                                        toggleMealPlan(category.id, mealPlan)
                                    "
                                />
                                {{ mealPlan }}
                            </label>
                        </div>
                    </div>
                    <p
                        v-if="!props.roomCategories.length"
                        class="py-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        This property has no room categories yet.
                    </p>
                </div>

                <div class="flex flex-col gap-4">
                    <h3
                        class="text-base text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
                    >
                        Additional Conditions
                    </h3>

                    <div
                        v-for="field in numberConditions"
                        :key="field.key"
                        class="grid grid-cols-1 items-center gap-3 sm:grid-cols-[200px_44px_1fr]"
                    >
                        <label
                            class="text-sm font-medium text-[#1b1b18] sm:text-right dark:text-[#EDEDEC]"
                            :title="field.info"
                            >{{ field.label }}
                            <span class="cursor-help text-[#706f6c]"
                                >ⓘ</span
                            ></label
                        >
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="packageForm[field.key].enabled"
                            :aria-label="field.label"
                            class="relative h-5 w-9 rounded-full transition-colors"
                            :class="
                                packageForm[field.key].enabled
                                    ? 'bg-[#337ab7]'
                                    : 'bg-[#e3e3e0] dark:bg-[#3E3E3A]'
                            "
                            @click="
                                packageForm[field.key].enabled =
                                    !packageForm[field.key].enabled
                            "
                        >
                            <span
                                class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-transform"
                                :class="
                                    packageForm[field.key].enabled
                                        ? 'translate-x-4'
                                        : 'translate-x-0.5'
                                "
                            />
                        </button>
                        <div
                            v-if="packageForm[field.key].enabled"
                            class="flex items-center gap-2"
                        >
                            <input
                                v-model.number="packageForm[field.key].value"
                                type="number"
                                min="0"
                                :placeholder="field.placeholder"
                                :class="[inputClass, 'w-24']"
                            />
                            <span
                                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >{{ field.unit }}</span
                            >
                        </div>
                        <span
                            v-else
                            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >{{ field.offText }}</span
                        >
                    </div>

                    <div
                        v-for="field in dateConditions"
                        :key="field.key"
                        class="grid grid-cols-1 items-center gap-3 sm:grid-cols-[200px_44px_1fr]"
                    >
                        <label
                            class="text-sm font-medium text-[#1b1b18] sm:text-right dark:text-[#EDEDEC]"
                            :title="field.info"
                            >{{ field.label }}
                            <span class="cursor-help text-[#706f6c]"
                                >ⓘ</span
                            ></label
                        >
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="packageForm[field.key].enabled"
                            :aria-label="field.label"
                            class="relative h-5 w-9 rounded-full transition-colors"
                            :class="
                                packageForm[field.key].enabled
                                    ? 'bg-[#337ab7]'
                                    : 'bg-[#e3e3e0] dark:bg-[#3E3E3A]'
                            "
                            @click="
                                packageForm[field.key].enabled =
                                    !packageForm[field.key].enabled
                            "
                        >
                            <span
                                class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-transform"
                                :class="
                                    packageForm[field.key].enabled
                                        ? 'translate-x-4'
                                        : 'translate-x-0.5'
                                "
                            />
                        </button>
                        <div
                            v-if="packageForm[field.key].enabled"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <input
                                v-model="packageForm[field.key].start"
                                type="date"
                                :aria-label="`${field.label} from`"
                                :class="inputClass"
                            />
                            <input
                                v-model="packageForm[field.key].end"
                                type="date"
                                :aria-label="`${field.label} to`"
                                :class="inputClass"
                            />
                        </div>
                        <span
                            v-else
                            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >{{ field.offText }}</span
                        >
                    </div>

                    <div
                        class="grid grid-cols-1 items-center gap-3 sm:grid-cols-[200px_44px_1fr]"
                    >
                        <label
                            class="text-sm font-medium text-[#1b1b18] sm:text-right dark:text-[#EDEDEC]"
                            title="Sell the package only for stays on these days of the week."
                        >
                            Stay days of the week
                            <span class="cursor-help text-[#706f6c]">ⓘ</span>
                        </label>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="
                                packageForm.stay_days_of_week.enabled
                            "
                            aria-label="Stay days of the week"
                            class="relative h-5 w-9 rounded-full transition-colors"
                            :class="
                                packageForm.stay_days_of_week.enabled
                                    ? 'bg-[#337ab7]'
                                    : 'bg-[#e3e3e0] dark:bg-[#3E3E3A]'
                            "
                            @click="
                                packageForm.stay_days_of_week.enabled =
                                    !packageForm.stay_days_of_week.enabled
                            "
                        >
                            <span
                                class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-transform"
                                :class="
                                    packageForm.stay_days_of_week.enabled
                                        ? 'translate-x-4'
                                        : 'translate-x-0.5'
                                "
                            />
                        </button>
                        <div
                            v-if="packageForm.stay_days_of_week.enabled"
                            class="flex flex-wrap gap-1"
                        >
                            <button
                                v-for="day in dayKeys"
                                :key="day"
                                type="button"
                                class="rounded border px-3 py-1.5 text-sm"
                                :class="
                                    packageForm.stay_days_of_week.days.includes(
                                        day,
                                    )
                                        ? 'border-[#337ab7] bg-[#337ab7] text-white'
                                        : 'border-[#e3e3e0] text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]'
                                "
                                :aria-pressed="
                                    packageForm.stay_days_of_week.days.includes(
                                        day,
                                    )
                                "
                                @click="toggleStayDay(day)"
                            >
                                {{ dayLabels[day] }}
                            </button>
                        </div>
                        <span
                            v-else
                            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >Applied to all days of the week.</span
                        >
                    </div>

                    <div
                        class="grid grid-cols-1 items-center gap-3 sm:grid-cols-[200px_44px_1fr]"
                    >
                        <label
                            class="text-sm font-medium text-[#1b1b18] sm:text-right dark:text-[#EDEDEC]"
                            title="Whether the package applies to Pay at Hotel bookings."
                        >
                            Applicable on Pay@Hotel
                            <span class="cursor-help text-[#706f6c]">ⓘ</span>
                        </label>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="
                                packageForm.applicable_on_pay_at_hotel
                            "
                            aria-label="Applicable on Pay@Hotel"
                            class="relative h-5 w-9 rounded-full transition-colors"
                            :class="
                                packageForm.applicable_on_pay_at_hotel
                                    ? 'bg-[#337ab7]'
                                    : 'bg-[#e3e3e0] dark:bg-[#3E3E3A]'
                            "
                            @click="
                                packageForm.applicable_on_pay_at_hotel =
                                    !packageForm.applicable_on_pay_at_hotel
                            "
                        >
                            <span
                                class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-transform"
                                :class="
                                    packageForm.applicable_on_pay_at_hotel
                                        ? 'translate-x-4'
                                        : 'translate-x-0.5'
                                "
                            />
                        </button>
                        <span
                            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{
                                packageForm.applicable_on_pay_at_hotel
                                    ? 'Applicable on Pay at Hotel bookings.'
                                    : 'Not applicable.'
                            }}
                        </span>
                    </div>
                </div>

                <p
                    v-if="formError"
                    class="text-sm text-[#f53003] dark:text-[#FF4433]"
                >
                    {{ formError }}
                </p>

                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="modalOpen = false"
                    >
                        Close
                    </button>
                    <button
                        type="submit"
                        class="rounded-md bg-[#337ab7] px-4 py-2 text-sm font-medium text-white hover:bg-[#286090]"
                    >
                        {{ editingId === null ? 'Create' : 'Update' }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="deletingPackage !== null"
            title="Delete package"
            @close="deletingPackage = null"
        >
            <p class="mb-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                Delete <strong>{{ deletingPackage?.name }}</strong
                >? Its rates and its dates on the Packages Calendar are removed
                too.
            </p>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="deletingPackage = null"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md bg-[#f53003] px-4 py-2 text-sm font-medium text-white"
                    @click="confirmDelete"
                >
                    Delete
                </button>
            </div>
        </Modal>
    </div>
</template>
