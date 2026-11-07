<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import RatePackages, { type RatePackage } from '@/components/RatePackages.vue';
import OccupancyPricingSettings, {
    type OccupancyPricingSettings as OccupancyPricingState,
} from '@/components/OccupancyPricingSettings.vue';
import RateRoundingSettings, {
    type RoundingSettings,
} from '@/components/RateRoundingSettings.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import { useCurrency } from '@/lib/useCurrency';
import { useUrlTab } from '@/lib/useUrlTab';
import type { MetaField, MetaFieldValue, RoomCategory } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    property: { id: number; name: string };
    metaFields: MetaField[];
    metaFieldValues: MetaFieldValue[];
    roomCategories: Pick<
        RoomCategory,
        'id' | 'name' | 'max_adults' | 'meal_plans'
    >[];
}>();

function fieldFor(key: string) {
    return props.metaFields.find((field) => field.key === key);
}

// The rate plan menus teleport to <body>, in the browser only: SSR and hydration render the same placeholder.
const isClientMounted = ref(false);
onMounted(() => (isClientMounted.value = true));

const metaFieldValues: Record<number, { enabled: boolean; data: string }> = {};
props.metaFields.forEach((field) => {
    metaFieldValues[field.id] = { enabled: false, data: '' };
});
props.metaFieldValues.forEach((value) => {
    metaFieldValues[value.meta_fields_key_id] = {
        enabled: value.enabled,
        data: value.data ?? '',
    };
});

const form = useForm({
    meta_fields: metaFieldValues,
});

function rawDataFor(key: string): string {
    const field = fieldFor(key);

    return field ? (form.meta_fields[field.id]?.data ?? '') : '';
}

function parseOrDefault<T>(key: string, defaults: T): T {
    const raw = rawDataFor(key);

    if (!raw) {
        return defaults;
    }

    try {
        return { ...defaults, ...(JSON.parse(raw) as Partial<T>) };
    } catch {
        return defaults;
    }
}

function bindSettings<T>(key: string, state: { value: T }): void {
    const field = fieldFor(key);

    if (field) {
        form.meta_fields[field.id].enabled = true;
    }

    watch(
        state,
        (value) => {
            if (field) {
                form.meta_fields[field.id].data = JSON.stringify(value);
            }
        },
        { deep: true, immediate: true },
    );
}

// Rate plan
type RatePlanMappingStatus = 'default' | 'unmapped' | 'mapped';

type DayKey = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

const dayKeys: DayKey[] = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const dayLabels: Record<DayKey, string> = {
    mon: 'Mon',
    tue: 'Tue',
    wed: 'Wed',
    thu: 'Thu',
    fri: 'Fri',
    sat: 'Sat',
    sun: 'Sun',
};

type DayRates = Record<DayKey, number>;

function emptyDayRates(): DayRates {
    return { mon: 0, tue: 0, wed: 0, thu: 0, fri: 0, sat: 0, sun: 0 };
}

const rateChannels = [
    { key: 'direct', label: 'Direct' },
    { key: 'ota', label: 'OTA' },
    { key: 'agent1', label: 'Agent1' },
    { key: 'booking_engine', label: 'BookingEngine' },
    { key: 'meta', label: 'Meta' },
    { key: 'ota_b2b', label: 'OTA-B2B' },
] as const;

type RateChannel = (typeof rateChannels)[number]['key'];

type RatePlanRow = {
    id: number;
    color: string;
    name: string;
    mapping_status: RatePlanMappingStatus;
    active: boolean;
    day_wise_breakup: boolean;
    rates: Partial<Record<RateChannel, Record<string, DayRates>>>;
};

type RatePlanSettings = {
    plans: RatePlanRow[];
};

const ratePlanSettings = ref<RatePlanSettings>(
    parseOrDefault<RatePlanSettings>('rate_settings_rate_plan', { plans: [] }),
);
bindSettings('rate_settings_rate_plan', ratePlanSettings);

// Older saved plans may not have day_wise_breakup/rates yet — backfill in place.
ratePlanSettings.value.plans.forEach((plan) => {
    if (plan.day_wise_breakup === undefined) {
        plan.day_wise_breakup = true;
    }
    if (!plan.rates) {
        plan.rates = {};
    }
});

const ratePlanMappingStatusOptions: {
    value: RatePlanMappingStatus;
    label: string;
}[] = [
    { value: 'default', label: 'Default' },
    { value: 'unmapped', label: 'Unmapped' },
    { value: 'mapped', label: 'Mapped' },
];

function ratePlanMappingBadgeClass(status: RatePlanMappingStatus): string {
    if (status === 'default') {
        return 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-400';
    }

    if (status === 'mapped') {
        return 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-400';
    }

    return 'bg-[#f5f5f4] text-[#706f6c] dark:bg-[#1D1D1B] dark:text-[#A1A09A]';
}

const ratePlanStatusFilter = ref<'active' | 'inactive'>('active');
const ratePlanMappingFilter = ref<'all' | RatePlanMappingStatus>('all');
const ratePlanActionMenuId = ref<number | null>(null);
const ratePlanActionMenuPosition = ref({ top: 0, left: 0 });

function toggleRatePlanActionMenu(plan: RatePlanRow, event: MouseEvent): void {
    if (ratePlanActionMenuId.value === plan.id) {
        ratePlanActionMenuId.value = null;
        return;
    }

    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    ratePlanActionMenuPosition.value = {
        top: rect.bottom + 4,
        left: rect.right - 160,
    };
    ratePlanActionMenuId.value = plan.id;
}

function closeRatePlanActionMenu(): void {
    ratePlanActionMenuId.value = null;
}

// Listened for once mounted: there is no document while rendering on the server.
onMounted(() => document.addEventListener('click', closeRatePlanActionMenu));
onBeforeUnmount(() =>
    document.removeEventListener('click', closeRatePlanActionMenu),
);

function isPlanUsedInPlanner(planId: number): boolean {
    return Object.values(ratePlannerSettings.value.assignments).includes(
        planId,
    );
}

function effectiveMappingStatus(plan: RatePlanRow): RatePlanMappingStatus {
    if (plan.mapping_status === 'default') {
        return 'default';
    }

    return isPlanUsedInPlanner(plan.id) ? 'mapped' : 'unmapped';
}

const filteredRatePlans = computed(() =>
    ratePlanSettings.value.plans.filter((plan) => {
        if (plan.active !== (ratePlanStatusFilter.value === 'active')) {
            return false;
        }

        return (
            ratePlanMappingFilter.value === 'all' ||
            effectiveMappingStatus(plan) === ratePlanMappingFilter.value
        );
    }),
);

const ratePlanModalOpen = ref(false);
const editingRatePlanId = ref<number | null>(null);

function emptyRatePlan(): RatePlanRow {
    const nextId =
        ratePlanSettings.value.plans.reduce(
            (max, plan) => Math.max(max, plan.id),
            0,
        ) + 1;

    return {
        id: nextId,
        color: '#1b9cfc',
        name: '',
        mapping_status: 'unmapped',
        active: true,
        day_wise_breakup: true,
        rates: {},
    };
}

const ratePlanForm = ref<RatePlanRow>(emptyRatePlan());

const hasOtherDefaultPlan = computed(() =>
    ratePlanSettings.value.plans.some(
        (plan) =>
            plan.mapping_status === 'default' &&
            plan.id !== ratePlanForm.value.id,
    ),
);

const ratePlanFormMappingOptions: {
    value: RatePlanMappingStatus;
    label: string;
}[] = [{ value: 'default', label: 'Default' }];

function openAddRatePlanModal(): void {
    editingRatePlanId.value = null;
    ratePlanForm.value = emptyRatePlan();
    ratePlanModalOpen.value = true;
}

function openEditRatePlanModal(plan: RatePlanRow): void {
    ratePlanActionMenuId.value = null;
    editingRatePlanId.value = plan.id;
    ratePlanForm.value = { ...plan };
    ratePlanModalOpen.value = true;
}

function saveRatePlanMeta(): void {
    if (editingRatePlanId.value === null) {
        ratePlanSettings.value.plans.push(ratePlanForm.value);
    } else {
        const index = ratePlanSettings.value.plans.findIndex(
            (plan) => plan.id === editingRatePlanId.value,
        );

        if (index !== -1) {
            ratePlanSettings.value.plans[index] = {
                ...ratePlanSettings.value.plans[index],
                ...ratePlanForm.value,
            };
        }
    }

    ratePlanModalOpen.value = false;
}

function toggleRatePlanActive(plan: RatePlanRow): void {
    plan.active = !plan.active;
    ratePlanActionMenuId.value = null;
}

// Rate grid (per rate plan)
/** Whose rates the grid shows: a rate plan (Rate Plan tab) or a package (Package tab). */
const managingTarget = ref<{ kind: 'plan' | 'package'; id: number } | null>(
    null,
);
const managingChannel = ref<RateChannel>('direct');

type RateHolder = {
    name: string;
    day_wise_breakup: boolean;
    rates: Partial<Record<RateChannel, Record<string, DayRates>>>;
};

const managingPlan = computed<RateHolder | null>(() => {
    const target = managingTarget.value;

    if (target === null) {
        return null;
    }

    return target.kind === 'plan'
        ? (ratePlanSettings.value.plans.find((plan) => plan.id === target.id) ??
              null)
        : (packageSettings.value.packages.find(
              (item) => item.id === target.id,
          ) ?? null);
});

/** A package is priced only for the categories and meal plans it is sold with. */
const managingPackage = computed(() =>
    managingTarget.value?.kind === 'package'
        ? (packageSettings.value.packages.find(
              (item) => item.id === managingTarget.value?.id,
          ) ?? null)
        : null,
);

type RateRow = {
    rowKey: string;
    categoryName: string;
    mealPlan: string;
    occupancy: string;
    isFirstOfCategory: boolean;
    rowSpan: number;
};

const occupancyLabelsByAdults: Record<number, string[]> = {
    1: ['Single'],
    2: ['Single', 'Double'],
    3: ['Single', 'Double', 'Triple'],
    4: ['Single', 'Double', 'Triple', 'Quad'],
};

function occupancyLabelsFor(maxAdults: number): string[] {
    const labels =
        occupancyLabelsByAdults[Math.min(Math.max(maxAdults, 1), 4)] ??
        occupancyLabelsByAdults[1];

    return [...labels, 'Extra Person', 'Extra Child'];
}

const rateRows = computed<RateRow[]>(() => {
    const rows: RateRow[] = [];

    props.roomCategories.forEach((category) => {
        const allMealPlans = category.meal_plans?.length
            ? category.meal_plans
            : ['EP'];
        const packageMealPlans = managingPackage.value?.rooms[category.id];
        const mealPlans = managingPackage.value
            ? allMealPlans.filter((mealPlan) =>
                  packageMealPlans?.includes(mealPlan),
              )
            : allMealPlans;

        mealPlans.forEach((mealPlan) => {
            const occupancies = occupancyLabelsFor(category.max_adults);
            let firstOfCategory = true;

            occupancies.forEach((occupancy) => {
                rows.push({
                    rowKey: `${category.id}:${mealPlan}:${occupancy}`,
                    categoryName: category.name,
                    mealPlan,
                    occupancy,
                    isFirstOfCategory: firstOfCategory,
                    rowSpan: occupancies.length,
                });
                firstOfCategory = false;
            });
        });
    });

    return rows;
});

function ratesFor(rowKey: string): DayRates {
    const plan = managingPlan.value;

    if (!plan) {
        return emptyDayRates();
    }

    const channelRates =
        plan.rates[managingChannel.value] ??
        (plan.rates[managingChannel.value] = {});

    return channelRates[rowKey] ?? (channelRates[rowKey] = emptyDayRates());
}

function setAllDays(rowKey: string, value: number): void {
    const rates = ratesFor(rowKey);
    dayKeys.forEach((day) => {
        rates[day] = value;
    });
}

function openRatePlanGrid(plan: RatePlanRow): void {
    ratePlanActionMenuId.value = null;
    managingTarget.value = { kind: 'plan', id: plan.id };
    managingChannel.value = 'direct';
}

function openPackageRates(packageId: number): void {
    managingTarget.value = { kind: 'package', id: packageId };
    managingChannel.value = 'direct';
}

function closeRatePlanGrid(): void {
    managingTarget.value = null;
}

function applyToAllChannels(): void {
    const plan = managingPlan.value;

    if (!plan) {
        return;
    }

    const current = plan.rates[managingChannel.value] ?? {};

    rateChannels.forEach((channel) => {
        plan.rates[channel.key] = JSON.parse(JSON.stringify(current));
    });

    submit();
}

function applyToCurrentChannelOnly(): void {
    submit();
}

function resetChannelRates(): void {
    const plan = managingPlan.value;

    if (!plan) {
        return;
    }

    plan.rates[managingChannel.value] = {};
}

function submit(): void {
    form.put(admin.rateSettings.update(props.property).url, {
        preserveScroll: true,
        preserveState: true,
    });
}

// Top-level tabs
const topTabs = [
    'rate-plan',
    'package',
    'rate-planner',
    'deals-promo',
    'occupancy-pricing',
    'rounding',
] as const;
const topTab = useUrlTab(topTabs, 'rate-plan', 'tab');

// The rate grid belongs to the tab it was opened from.
watch(topTab, () => {
    managingTarget.value = null;
});

const plannerSubTabs = ['bar-calendar', 'packages-calendar'] as const;
const plannerSubTab = useUrlTab(plannerSubTabs, 'bar-calendar', 'planner_tab');

// Rate planner (BAR calendar)
type RatePlannerSettings = {
    assignments: Record<string, number>;
};

const ratePlannerSettings = ref<RatePlannerSettings>(
    parseOrDefault<RatePlannerSettings>('rate_settings_rate_planner', {
        assignments: {},
    }),
);
bindSettings('rate_settings_rate_planner', ratePlannerSettings);

const ratePlannerSnapshot = ref<RatePlannerSettings>(
    JSON.parse(JSON.stringify(ratePlannerSettings.value)),
);

const currentYear = new Date().getFullYear();
const plannerYearOptions = [
    currentYear - 1,
    currentYear,
    currentYear + 1,
    currentYear + 2,
    currentYear + 3,
];
const plannerYear = ref(currentYear);

const activeRatePlans = computed(() =>
    ratePlanSettings.value.plans.filter((plan) => plan.active),
);
const selectedBrushPlanId = ref<number | null>(null);

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
const weekDayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

type CalendarCell = { day: number; iso: string } | null;

function isoDate(year: number, month: number, day: number): string {
    return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function monthWeeks(year: number, month: number): CalendarCell[][] {
    const firstDay = new Date(year, month, 1);
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const leadingBlanks = (firstDay.getDay() + 6) % 7;

    const cells: CalendarCell[] = [];
    for (let i = 0; i < leadingBlanks; i++) {
        cells.push(null);
    }
    for (let day = 1; day <= daysInMonth; day++) {
        cells.push({ day, iso: isoDate(year, month, day) });
    }
    while (cells.length % 7 !== 0) {
        cells.push(null);
    }

    const weeks: CalendarCell[][] = [];
    for (let i = 0; i < cells.length; i += 7) {
        weeks.push(cells.slice(i, i + 7));
    }

    return weeks;
}

const plannerMonths = computed(() =>
    monthNames.map((name, index) => ({
        name,
        weeks: monthWeeks(plannerYear.value, index),
    })),
);

const todayIso = isoDate(
    new Date().getFullYear(),
    new Date().getMonth(),
    new Date().getDate(),
);

function isPastDate(iso: string): boolean {
    return iso < todayIso;
}

function planColorFor(iso: string): string | null {
    const planId = ratePlannerSettings.value.assignments[iso];

    if (planId === undefined) {
        return null;
    }

    return (
        ratePlanSettings.value.plans.find((plan) => plan.id === planId)
            ?.color ?? null
    );
}

function paintDay(iso: string): void {
    if (selectedBrushPlanId.value === null || isPastDate(iso)) {
        return;
    }

    ratePlannerSettings.value.assignments[iso] = selectedBrushPlanId.value;
}

function applyRatePlanner(): void {
    ratePlannerSnapshot.value = JSON.parse(
        JSON.stringify(ratePlannerSettings.value),
    );
    submit();
}

function cancelRatePlanner(): void {
    ratePlannerSettings.value.assignments = JSON.parse(
        JSON.stringify(ratePlannerSnapshot.value.assignments),
    );
}

function resetRatePlannerYear(): void {
    Object.keys(ratePlannerSettings.value.assignments).forEach((iso) => {
        if (iso.startsWith(String(plannerYear.value))) {
            delete ratePlannerSettings.value.assignments[iso];
        }
    });
}

// Prices on the calendar: the Single rate (direct channel) of a category and meal plan, or the lowest
// of them, from the plan painted on the date, else the default plan — so the calendar reads as a price grid.
const { symbol: currencySymbol } = useCurrency();
const plannerPriceRows = computed(() =>
    props.roomCategories.flatMap((category) =>
        (category.meal_plans?.length ? category.meal_plans : ['EP']).map(
            (mealPlan) => ({
                key: `${category.id}:${mealPlan}:Single`,
                label: `${category.name} · ${mealPlan}`,
            }),
        ),
    ),
);
const plannerPriceRow = ref('lowest');
const defaultRatePlan = computed(
    () =>
        activeRatePlans.value.find(
            (plan) => plan.mapping_status === 'default',
        ) ??
        activeRatePlans.value[0] ??
        null,
);

function plannerPriceFor(iso: string): number | null {
    const assigned = ratePlannerSettings.value.assignments[iso];
    const plan =
        (assigned === undefined
            ? null
            : ratePlanSettings.value.plans.find(
                  (item) => item.id === assigned,
              )) ?? defaultRatePlan.value;

    if (!plan) {
        return null;
    }

    const [year, month, day] = iso.split('-').map(Number);
    const dayKey = dayKeys[(new Date(year, month - 1, day).getDay() + 6) % 7];
    const rows = plan.rates.direct ?? {};
    const rowKeys =
        plannerPriceRow.value === 'lowest'
            ? plannerPriceRows.value.map((row) => row.key)
            : [plannerPriceRow.value];
    const prices = rowKeys
        .map((key) => Number(rows[key]?.[dayKey] ?? 0))
        .filter((price) => price > 0);

    return prices.length ? Math.min(...prices) : null;
}

function plannerPriceLabel(iso: string): string {
    const price = plannerPriceFor(iso);

    return price === null
        ? '—'
        : `${currencySymbol.value} ${Number.isInteger(price) ? price : price.toFixed(2)}`;
}

// Rounding (applied to rates on the server: RateRounding)
const roundingSettings = ref<RoundingSettings>({
    enabled: false,
    method: 'nearest',
    multiple: 1,
    ending: null,
    channels: ['direct', 'ota'],
    ...parseOrDefault<Partial<RoundingSettings>>('rate_settings_rounding', {}),
});
bindSettings('rate_settings_rounding', roundingSettings);

// Occupancy pricing (applied to rates on the server: OccupancyPricing)
const occupancyPricingSettings = ref<OccupancyPricingState>({
    enabled: false,
    basis: 'property',
    channels: ['direct', 'ota'],
    tiers: [],
    ...parseOrDefault<Partial<OccupancyPricingState>>(
        'rate_settings_occupancy_pricing',
        {},
    ),
});
bindSettings('rate_settings_occupancy_pricing', occupancyPricingSettings);

// Packages
type PackageSettings = { packages: RatePackage[] };

const packageSettings = ref<PackageSettings>(
    parseOrDefault<PackageSettings>('rate_settings_packages', { packages: [] }),
);
bindSettings('rate_settings_packages', packageSettings);

// Packages calendar: the packages sold on each date (several may share a date).
type PackagePlannerSettings = { assignments: Record<string, number[]> };

const packagePlannerSettings = ref<PackagePlannerSettings>(
    parseOrDefault<PackagePlannerSettings>('rate_settings_packages_planner', {
        assignments: {},
    }),
);
bindSettings('rate_settings_packages_planner', packagePlannerSettings);

const packagePlannerSnapshot = ref<PackagePlannerSettings>(
    JSON.parse(JSON.stringify(packagePlannerSettings.value)),
);
const selectedBrushPackageId = ref<number | null>(null);

function packagesOn(iso: string): RatePackage[] {
    const ids = packagePlannerSettings.value.assignments[iso] ?? [];

    return packageSettings.value.packages.filter((item) =>
        ids.includes(item.id),
    );
}

/** One colour band per package mapped to the date. */
function packageBackground(iso: string): string | undefined {
    const colors = packagesOn(iso).map((item) => item.color);

    if (!colors.length) {
        return undefined;
    }

    if (colors.length === 1) {
        return colors[0];
    }

    const step = 100 / colors.length;

    return `linear-gradient(135deg, ${colors.map((color, index) => `${color} ${index * step}% ${(index + 1) * step}%`).join(', ')})`;
}

/** Clicking a date adds the selected package to it, or takes it off when already there. */
function paintPackageDay(iso: string): void {
    const packageId = selectedBrushPackageId.value;

    if (packageId === null || isPastDate(iso)) {
        return;
    }

    const ids = packagePlannerSettings.value.assignments[iso] ?? [];
    const next = ids.includes(packageId)
        ? ids.filter((id) => id !== packageId)
        : [...ids, packageId];

    if (next.length) {
        packagePlannerSettings.value.assignments[iso] = next;
    } else {
        delete packagePlannerSettings.value.assignments[iso];
    }
}

function applyPackagePlanner(): void {
    packagePlannerSnapshot.value = JSON.parse(
        JSON.stringify(packagePlannerSettings.value),
    );
    submit();
}

function cancelPackagePlanner(): void {
    packagePlannerSettings.value.assignments = JSON.parse(
        JSON.stringify(packagePlannerSnapshot.value.assignments),
    );
}

function resetPackagePlannerYear(): void {
    Object.keys(packagePlannerSettings.value.assignments).forEach((iso) => {
        if (iso.startsWith(String(plannerYear.value))) {
            delete packagePlannerSettings.value.assignments[iso];
        }
    });
}

/** A deleted package leaves the calendar too. */
function forgetPackage(packageId: number): void {
    Object.entries(packagePlannerSettings.value.assignments).forEach(
        ([iso, ids]) => {
            const rest = ids.filter((id) => id !== packageId);

            if (rest.length) {
                packagePlannerSettings.value.assignments[iso] = rest;
            } else {
                delete packagePlannerSettings.value.assignments[iso];
            }
        },
    );
    packagePlannerSnapshot.value = JSON.parse(
        JSON.stringify(packagePlannerSettings.value),
    );

    if (selectedBrushPackageId.value === packageId) {
        selectedBrushPackageId.value = null;
    }
}

// Deals & Promo
const dealsPromoSubTabs = [
    'public-promo-codes',
    'secret-promo-codes',
    'booking-dot-com',
    'payment-coupons',
    'go-mmt',
    'mg-bed-bank',
] as const;
const dealsPromoSubTab = useUrlTab(
    dealsPromoSubTabs,
    'public-promo-codes',
    'deals_tab',
);

const dealsPromoTabLabels: Record<(typeof dealsPromoSubTabs)[number], string> =
    {
        'public-promo-codes': 'Public Promo Codes',
        'secret-promo-codes': 'Secret Promo Codes',
        'booking-dot-com': 'Booking Dot Com',
        'payment-coupons': 'Payment Coupons',
        'go-mmt': 'Go-MMT',
        'mg-bed-bank': 'MG Bed Bank',
    };

type PromoDealType = 'basic' | 'free_room_night';
type PromoDiscountType = 'percentage' | 'fixed';
type PromoChannel = 'booking_engine' | 'agent_connekt';
type PromoFreeNightAvailability =
    | 'check_in'
    | 'max_room_rate'
    | 'min_room_rate'
    | 'check_out';
type PromoListKey = 'public_promo_codes' | 'secret_promo_codes';

type PromoToggleNumber = { enabled: boolean; value: number | null };
type PromoToggleDateRange = {
    enabled: boolean;
    start: string | null;
    end: string | null;
};
type PromoToggleAmountRange = {
    enabled: boolean;
    min: number | null;
    max: number | null;
};
type PromoToggleStayDays = { enabled: boolean; days: DayKey[] };
type PromoToggleOnly = { enabled: boolean };

function emptyToggleNumber(): PromoToggleNumber {
    return { enabled: false, value: null };
}

function emptyToggleDateRange(): PromoToggleDateRange {
    return { enabled: false, start: null, end: null };
}

type PromoCode = {
    id: number;
    deal_type: PromoDealType;
    code: string;
    tag_line: string;
    be_carousel_label: string;
    description: string;
    discount_type: PromoDiscountType;
    discount_value: number;
    required_specific_payment_gateway: boolean;
    channels: PromoChannel[];
    min_length_of_stay: number | null;
    free_night_available_is: PromoFreeNightAvailability;
    booking_creation_date_range: PromoToggleDateRange;
    checkin_date_range: PromoToggleDateRange;
    checkout_date_range: PromoToggleDateRange;
    stay_days_of_week: PromoToggleStayDays;
    exclusion_date_range: PromoToggleDateRange;
    bar_rate_plans: PromoToggleOnly;
    package_rate_plans: PromoToggleOnly;
    cut_off_days: PromoToggleNumber;
    last_minute: PromoToggleNumber;
    length_of_stay: PromoToggleNumber;
    booking_amount_range: PromoToggleAmountRange;
    usage_limit: PromoToggleNumber;
    daily_room_count_budget: PromoToggleNumber;
    max_discount_per_booking: PromoToggleNumber;
    max_total_budget: PromoToggleNumber;
    applicable_on_pay_at_hotel: boolean;
    terms_and_conditions: string;
    status: boolean;
    deleted: boolean;
};

type PaymentCouponSettings = {
    id: number;
    code: string;
    description: string;
    amount: number | null;
    max_total_count: number | null;
    sold_count: number;
    valid_from: string | null;
    valid_to: string | null;
    notes: string;
    deleted: boolean;
};

type DealsPromotionSettings = {
    auto_apply_public_promo: boolean;
    public_promo_codes: PromoCode[];
    secret_promo_codes: PromoCode[];
    payment_coupons: PaymentCouponSettings[];
};

const dealsPromotionSettings = ref<DealsPromotionSettings>(
    parseOrDefault<DealsPromotionSettings>('rate_settings_deals_promotion', {
        auto_apply_public_promo: true,
        public_promo_codes: [],
        secret_promo_codes: [],
        payment_coupons: [],
    }),
);
bindSettings('rate_settings_deals_promotion', dealsPromotionSettings);

dealsPromotionSettings.value.secret_promo_codes ??= [];

// Older saved settings may have stored a single coupon object instead of a list — wrap it in place.
if (!Array.isArray(dealsPromotionSettings.value.payment_coupons)) {
    const legacyCoupon = dealsPromotionSettings.value
        .payment_coupons as unknown as Partial<PaymentCouponSettings>;
    dealsPromotionSettings.value.payment_coupons = legacyCoupon?.code
        ? [{ ...legacyCoupon, id: 1, deleted: false } as PaymentCouponSettings]
        : [];
}
dealsPromotionSettings.value.payment_coupons.forEach((coupon) => {
    coupon.deleted ??= false;
});

function remainingCouponCount(coupon: PaymentCouponSettings): number | null {
    return coupon.max_total_count === null
        ? null
        : Math.max(0, coupon.max_total_count - coupon.sold_count);
}

const promoActivityLogStaff = ref('');
const promoActivityLogDateFrom = ref<string | null>(null);
const promoActivityLogDateTo = ref<string | null>(null);

function resetPromoActivityLogFilter(): void {
    promoActivityLogStaff.value = '';
    promoActivityLogDateFrom.value = null;
    promoActivityLogDateTo.value = null;
}

const couponActivityLogStaff = ref('');
const couponActivityLogDateFrom = ref<string | null>(null);
const couponActivityLogDateTo = ref<string | null>(null);

function resetCouponActivityLogFilter(): void {
    couponActivityLogStaff.value = '';
    couponActivityLogDateFrom.value = null;
    couponActivityLogDateTo.value = null;
}

// Older saved promo codes may predate the full deal-builder shape — backfill missing fields in place.
function backfillPromoCode(promo: PromoCode): void {
    promo.deal_type ??= 'basic';
    promo.tag_line ??= '';
    promo.be_carousel_label ??= '';
    promo.description ??= '';
    promo.required_specific_payment_gateway ??= false;
    promo.channels ??= [];
    promo.min_length_of_stay ??= null;
    promo.free_night_available_is ??= 'check_in';
    promo.booking_creation_date_range ??= emptyToggleDateRange();
    promo.checkin_date_range ??= emptyToggleDateRange();
    promo.checkout_date_range ??= emptyToggleDateRange();
    promo.stay_days_of_week ??= { enabled: false, days: [] };
    promo.exclusion_date_range ??= emptyToggleDateRange();
    promo.bar_rate_plans ??= { enabled: false };
    promo.package_rate_plans ??= { enabled: false };
    promo.cut_off_days ??= emptyToggleNumber();
    promo.last_minute ??= emptyToggleNumber();
    promo.length_of_stay ??= emptyToggleNumber();
    promo.booking_amount_range ??= { enabled: false, min: null, max: null };
    promo.usage_limit ??= emptyToggleNumber();
    promo.daily_room_count_budget ??= emptyToggleNumber();
    promo.max_discount_per_booking ??= emptyToggleNumber();
    promo.max_total_budget ??= emptyToggleNumber();
    promo.applicable_on_pay_at_hotel ??= true;
    promo.terms_and_conditions ??= '';
}

dealsPromotionSettings.value.public_promo_codes.forEach(backfillPromoCode);
dealsPromotionSettings.value.secret_promo_codes.forEach(backfillPromoCode);

const promoDiscountTypeOptions: { value: PromoDiscountType; label: string }[] =
    [
        { value: 'percentage', label: 'Percentage' },
        { value: 'fixed', label: 'Fixed' },
    ];

const promoChannelOptions: { value: PromoChannel; label: string }[] = [
    { value: 'booking_engine', label: 'Booking Engine' },
    { value: 'agent_connekt', label: 'Agent Connekt' },
];

const promoFreeNightAvailabilityOptions: {
    value: PromoFreeNightAvailability;
    label: string;
}[] = [
    { value: 'check_in', label: 'Check-In Night' },
    { value: 'max_room_rate', label: 'Max Room rate Night' },
    { value: 'min_room_rate', label: 'Min Room rate Night' },
    { value: 'check_out', label: 'Check-Out Night' },
];

const promoDateRangeFields: {
    key:
        | 'booking_creation_date_range'
        | 'checkin_date_range'
        | 'checkout_date_range';
    label: string;
    offText: string;
}[] = [
    {
        key: 'booking_creation_date_range',
        label: 'Booking creation date range',
        offText: 'Applied to all booking dates',
    },
    {
        key: 'checkin_date_range',
        label: 'Check-in date range',
        offText: 'Applied to all check-in dates',
    },
    {
        key: 'checkout_date_range',
        label: 'Check-out date range',
        offText: 'Applied to all check-out dates',
    },
];

const promoCategoryToggleFields: {
    key: 'bar_rate_plans' | 'package_rate_plans';
    label: string;
    offText: string;
}[] = [
    {
        key: 'bar_rate_plans',
        label: 'BAR rate plans',
        offText: 'Applied to all BAR rate plans',
    },
    {
        key: 'package_rate_plans',
        label: 'Package rate plans',
        offText: 'Applied to all package rate plans',
    },
];

const promoAdditionalConditionFields: {
    key:
        | 'cut_off_days'
        | 'last_minute'
        | 'length_of_stay'
        | 'usage_limit'
        | 'daily_room_count_budget'
        | 'max_discount_per_booking'
        | 'max_total_budget';
    label: string;
    info: string;
    offText: string;
    unit: string;
}[] = [
    {
        key: 'cut_off_days',
        label: 'Cut Off days',
        info: 'Block bookings made within this many days of check-in.',
        offText: 'Not applicable.',
        unit: 'days',
    },
    {
        key: 'last_minute',
        label: 'Last Minute',
        info: 'Only allow this promo within this many hours of check-in.',
        offText: 'Not applicable.',
        unit: 'hours',
    },
    {
        key: 'length_of_stay',
        label: 'Length of stay',
        info: 'Minimum number of nights required to use this promo.',
        offText: 'Not applicable',
        unit: 'nights',
    },
    {
        key: 'usage_limit',
        label: 'Number of times this promo code can be used',
        info: 'Caps how many times this promo code can be redeemed in total.',
        offText: 'Infinite',
        unit: 'uses',
    },
    {
        key: 'daily_room_count_budget',
        label: 'Daily room count budget',
        info: 'Caps how many rooms per day this promo can be applied to.',
        offText: 'Infinite',
        unit: 'rooms/day',
    },
    {
        key: 'max_discount_per_booking',
        label: 'Max discount per booking',
        info: 'Caps the discount amount allowed on a single booking.',
        offText: 'Not applicable',
        unit: 'amount',
    },
    {
        key: 'max_total_budget',
        label: 'Max total budget of this promocode',
        info: 'Caps the total discount budget for this promo code.',
        offText: 'Not applicable',
        unit: 'amount',
    },
];

function formatPromoDiscount(promo: PromoCode): string {
    if (promo.discount_type === 'fixed') {
        return promo.discount_value.toFixed(3);
    }

    return `${promo.discount_value.toFixed(3)}%`;
}

const promoFormOpen = ref(false);
const editingPromoId = ref<number | null>(null);
const promoListKey = ref<PromoListKey>('public_promo_codes');

function emptyPromoCode(listKey: PromoListKey): PromoCode {
    const nextId =
        dealsPromotionSettings.value[listKey].reduce(
            (max, promo) => Math.max(max, promo.id),
            0,
        ) + 1;

    return {
        id: nextId,
        deal_type: 'basic',
        code: '',
        tag_line: '',
        be_carousel_label: '',
        description: '',
        discount_type: 'percentage',
        discount_value: 0,
        required_specific_payment_gateway: false,
        channels: [],
        min_length_of_stay: null,
        free_night_available_is: 'check_in',
        booking_creation_date_range: emptyToggleDateRange(),
        checkin_date_range: emptyToggleDateRange(),
        checkout_date_range: emptyToggleDateRange(),
        stay_days_of_week: { enabled: false, days: [] },
        exclusion_date_range: emptyToggleDateRange(),
        bar_rate_plans: { enabled: false },
        package_rate_plans: { enabled: false },
        cut_off_days: emptyToggleNumber(),
        last_minute: emptyToggleNumber(),
        length_of_stay: emptyToggleNumber(),
        booking_amount_range: { enabled: false, min: null, max: null },
        usage_limit: emptyToggleNumber(),
        daily_room_count_budget: emptyToggleNumber(),
        max_discount_per_booking: emptyToggleNumber(),
        max_total_budget: emptyToggleNumber(),
        applicable_on_pay_at_hotel: true,
        terms_and_conditions: '',
        status: true,
        deleted: false,
    };
}

const promoForm = ref<PromoCode>(emptyPromoCode('public_promo_codes'));

function openAddPromoForm(listKey: PromoListKey): void {
    promoListKey.value = listKey;
    editingPromoId.value = null;
    promoForm.value = emptyPromoCode(listKey);
    promoFormOpen.value = true;
}

function openEditPromoForm(listKey: PromoListKey, promo: PromoCode): void {
    promoListKey.value = listKey;
    editingPromoId.value = promo.id;
    promoForm.value = JSON.parse(JSON.stringify(promo));
    promoFormOpen.value = true;
}

function togglePromoChannel(channel: PromoChannel): void {
    const index = promoForm.value.channels.indexOf(channel);

    if (index === -1) {
        promoForm.value.channels.push(channel);
    } else {
        promoForm.value.channels.splice(index, 1);
    }
}

function toggleStayDay(day: DayKey): void {
    const days = promoForm.value.stay_days_of_week.days;
    const index = days.indexOf(day);

    if (index === -1) {
        days.push(day);
    } else {
        days.splice(index, 1);
    }
}

function savePromoCode(): void {
    const list = dealsPromotionSettings.value[promoListKey.value];

    if (editingPromoId.value === null) {
        list.push(promoForm.value);
    } else {
        const index = list.findIndex(
            (promo) => promo.id === editingPromoId.value,
        );

        if (index !== -1) {
            list[index] = { ...list[index], ...promoForm.value };
        }
    }

    promoFormOpen.value = false;
}

function deletePromoCode(promo: PromoCode): void {
    promo.deleted = true;
}

const paymentCouponFormOpen = ref(false);
const editingPaymentCouponId = ref<number | null>(null);

function emptyPaymentCoupon(): PaymentCouponSettings {
    const nextId =
        dealsPromotionSettings.value.payment_coupons.reduce(
            (max, coupon) => Math.max(max, coupon.id),
            0,
        ) + 1;

    return {
        id: nextId,
        code: '',
        description: '',
        amount: null,
        max_total_count: null,
        sold_count: 0,
        valid_from: null,
        valid_to: null,
        notes: '',
        deleted: false,
    };
}

const paymentCouponForm = ref<PaymentCouponSettings>(emptyPaymentCoupon());

function openAddPaymentCouponForm(): void {
    editingPaymentCouponId.value = null;
    paymentCouponForm.value = emptyPaymentCoupon();
    paymentCouponFormOpen.value = true;
}

function openEditPaymentCouponForm(coupon: PaymentCouponSettings): void {
    editingPaymentCouponId.value = coupon.id;
    paymentCouponForm.value = JSON.parse(JSON.stringify(coupon));
    paymentCouponFormOpen.value = true;
}

function savePaymentCoupon(): void {
    if (editingPaymentCouponId.value === null) {
        dealsPromotionSettings.value.payment_coupons.push(
            paymentCouponForm.value,
        );
    } else {
        const index = dealsPromotionSettings.value.payment_coupons.findIndex(
            (coupon) => coupon.id === editingPaymentCouponId.value,
        );

        if (index !== -1) {
            dealsPromotionSettings.value.payment_coupons[index] = {
                ...dealsPromotionSettings.value.payment_coupons[index],
                ...paymentCouponForm.value,
            };
        }
    }

    paymentCouponFormOpen.value = false;
}

function deletePaymentCoupon(coupon: PaymentCouponSettings): void {
    coupon.deleted = true;
}
</script>

<template>
    <Head :title="`Rate Settings: ${property.name}`" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Rate Settings: {{ property.name }}
        </h1>
        <Link
            :href="admin.rateSettings.index().url"
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
        >
            Close
        </Link>
    </div>

    <div
        class="mb-6 flex flex-wrap gap-1 border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <button
            v-for="tab in [
                { key: 'rate-plan', label: 'Rate Plan' },
                { key: 'package', label: 'Package' },
                { key: 'rate-planner', label: 'Rate Planner' },
                { key: 'deals-promo', label: 'Deals & Promo' },
                { key: 'occupancy-pricing', label: 'Occupancy Pricing' },
                { key: 'rounding', label: 'Rounding' },
            ]"
            :key="tab.key"
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                topTab === tab.key
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="topTab = tab.key as typeof topTab"
        >
            {{ tab.label }}
        </button>
        <Link
            :href="admin.rateRestrictions.edit(property.id).url"
            class="border-b-2 border-transparent px-4 py-2 text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
        >
            Restrictions
        </Link>
    </div>

    <div
        v-if="managingPlan && (topTab === 'rate-plan' || topTab === 'package')"
        class="mb-6 flex flex-col gap-4"
    >
        <div
            class="flex items-center justify-between border-b border-[#e3e3e0] pb-4 dark:border-[#3E3E3A]"
        >
            <div class="flex flex-wrap gap-1">
                <button
                    v-for="channel in rateChannels"
                    :key="channel.key"
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-medium"
                    :class="
                        managingChannel === channel.key
                            ? 'bg-[#1b1b18] text-white dark:bg-[#eeeeec] dark:text-[#1C1C1A]'
                            : 'text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]'
                    "
                    @click="managingChannel = channel.key"
                >
                    {{ channel.label }}
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-3 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="applyToAllChannels"
                >
                    Save &amp; apply to all channels
                </button>
                <button
                    type="button"
                    class="rounded-md border border-black bg-[#1b1b18] px-3 py-2 text-sm font-medium text-white dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A]"
                    @click="applyToCurrentChannelOnly"
                >
                    Apply to current channel only
                </button>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-3 py-2 text-sm font-medium text-[#f53003] dark:border-[#3E3E3A] dark:text-[#FF4433]"
                    @click="closeRatePlanGrid"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-3 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="resetChannelRates"
                >
                    Reset
                </button>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <label
                class="flex items-center gap-2 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                <input
                    v-model="managingPlan.day_wise_breakup"
                    type="checkbox"
                />
                Day wise Breakup
            </label>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Managing rates for
                <span class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{
                    managingPlan.name
                }}</span>
            </p>
        </div>

        <div
            class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <table
                class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
            >
                <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                    <tr>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Category
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Meal Plan
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Occupancy
                        </th>
                        <template v-if="managingPlan.day_wise_breakup">
                            <th
                                v-for="day in dayKeys"
                                :key="day"
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                {{ dayLabels[day] }}
                            </th>
                        </template>
                        <th
                            v-else
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Rate
                        </th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                >
                    <tr v-for="row in rateRows" :key="row.rowKey">
                        <td
                            v-if="row.isFirstOfCategory"
                            :rowspan="row.rowSpan"
                            class="border-r border-[#e3e3e0] px-4 py-3 align-top text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        >
                            {{ row.categoryName }}
                        </td>
                        <td
                            v-if="row.isFirstOfCategory"
                            :rowspan="row.rowSpan"
                            class="border-r border-[#e3e3e0] px-4 py-3 align-top text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]"
                        >
                            {{ row.mealPlan }}
                        </td>
                        <td
                            class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{ row.occupancy }}
                        </td>
                        <template v-if="managingPlan.day_wise_breakup">
                            <td
                                v-for="day in dayKeys"
                                :key="day"
                                class="px-2 py-2"
                            >
                                <input
                                    v-model.number="ratesFor(row.rowKey)[day]"
                                    type="number"
                                    min="0"
                                    class="block w-24 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                />
                            </td>
                        </template>
                        <td v-else class="px-2 py-2">
                            <input
                                :value="ratesFor(row.rowKey).mon"
                                type="number"
                                min="0"
                                class="block w-24 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                @input="
                                    setAllDays(
                                        row.rowKey,
                                        Number(
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        ),
                                    )
                                "
                            />
                        </td>
                    </tr>
                    <tr v-if="!rateRows.length">
                        <td
                            colspan="10"
                            class="px-4 py-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            No room categories found for this property.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <form
        v-show="topTab === 'rate-plan'"
        class="flex flex-col gap-6"
        @submit.prevent="submit"
    >
        <div v-if="!managingPlan" class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
                <div
                    class="flex gap-1 rounded-md border border-[#e3e3e0] p-1 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="rounded px-3 py-1.5 text-sm font-medium"
                        :class="
                            ratePlanStatusFilter === 'active'
                                ? 'bg-[#1b1b18] text-white dark:bg-[#eeeeec] dark:text-[#1C1C1A]'
                                : 'text-[#706f6c] dark:text-[#A1A09A]'
                        "
                        @click="ratePlanStatusFilter = 'active'"
                    >
                        Active Plans
                    </button>
                    <button
                        type="button"
                        class="rounded px-3 py-1.5 text-sm font-medium"
                        :class="
                            ratePlanStatusFilter === 'inactive'
                                ? 'bg-[#1b1b18] text-white dark:bg-[#eeeeec] dark:text-[#1C1C1A]'
                                : 'text-[#706f6c] dark:text-[#A1A09A]'
                        "
                        @click="ratePlanStatusFilter = 'inactive'"
                    >
                        Inactive Plans
                    </button>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    @click="openAddRatePlanModal"
                >
                    + Add
                </button>
            </div>

            <div class="flex items-center gap-3">
                <label
                    class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Filter by Mapping Status:</label
                >
                <select
                    v-model="ratePlanMappingFilter"
                    class="block w-48 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                >
                    <option value="all">All Rate Plans</option>
                    <option
                        v-for="option in ratePlanMappingStatusOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div
                class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
            >
                <table
                    class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
                >
                    <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                        <tr>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Color
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Name
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Mapping Status
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                    >
                        <tr v-for="plan in filteredRatePlans" :key="plan.id">
                            <td class="px-4 py-3">
                                <span
                                    class="inline-block h-5 w-5 rounded border border-[#e3e3e0] dark:border-[#3E3E3A]"
                                    :style="{ backgroundColor: plan.color }"
                                />
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <button
                                    type="button"
                                    class="font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                                    @click="openRatePlanGrid(plan)"
                                >
                                    {{ plan.name }}
                                </button>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :class="
                                        ratePlanMappingBadgeClass(
                                            effectiveMappingStatus(plan),
                                        )
                                    "
                                >
                                    {{
                                        ratePlanMappingStatusOptions.find(
                                            (o) =>
                                                o.value ===
                                                effectiveMappingStatus(plan),
                                        )?.label
                                    }}
                                </span>
                            </td>
                            <td class="relative px-4 py-3">
                                <button
                                    type="button"
                                    class="rounded-md px-2 py-1 text-sm font-bold text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]"
                                    @click.stop="
                                        toggleRatePlanActionMenu(plan, $event)
                                    "
                                >
                                    ...
                                </button>
                                <Teleport v-if="isClientMounted" to="body">
                                    <div
                                        v-if="ratePlanActionMenuId === plan.id"
                                        class="fixed z-50 w-40 rounded-md border border-[#e3e3e0] bg-white py-1 shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615]"
                                        :style="{
                                            top: `${ratePlanActionMenuPosition.top}px`,
                                            left: `${ratePlanActionMenuPosition.left}px`,
                                        }"
                                        @click.stop
                                    >
                                        <button
                                            type="button"
                                            class="block w-full px-4 py-2 text-left text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                                            @click="openRatePlanGrid(plan)"
                                        >
                                            Manage Rates
                                        </button>
                                        <button
                                            type="button"
                                            class="block w-full px-4 py-2 text-left text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                                            @click="openEditRatePlanModal(plan)"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            class="block w-full px-4 py-2 text-left text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                                            @click="toggleRatePlanActive(plan)"
                                        >
                                            {{
                                                plan.active
                                                    ? 'Mark as Inactive'
                                                    : 'Mark as Active'
                                            }}
                                        </button>
                                    </div>
                                </Teleport>
                            </td>
                        </tr>
                        <tr v-if="!filteredRatePlans.length">
                            <td
                                colspan="4"
                                class="px-4 py-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                No rate plans found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Modal
                :show="ratePlanModalOpen"
                :title="
                    editingRatePlanId === null
                        ? 'Add Rate Plan'
                        : 'Edit Rate Plan'
                "
                @close="ratePlanModalOpen = false"
            >
                <div class="flex flex-col gap-4">
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Name</label
                        >
                        <input
                            v-model="ratePlanForm.name"
                            type="text"
                            placeholder="Please enter the rate plan name"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Color</label
                        >
                        <input
                            v-model="ratePlanForm.color"
                            type="color"
                            class="block h-10 w-16 rounded-md border border-[#e3e3e0] dark:border-[#3E3E3A]"
                        />
                    </div>
                    <div v-if="!hasOtherDefaultPlan">
                        <label
                            class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Mapping Status</label
                        >
                        <select
                            v-model="ratePlanForm.mapping_status"
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option
                                v-for="option in ratePlanFormMappingOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                            @click="saveRatePlanMeta"
                        >
                            Save
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                            @click="ratePlanModalOpen = false"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </Modal>
        </div>

        <div class="flex items-center gap-3">
            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
            >
                {{ form.processing ? 'Saving...' : 'Save changes' }}
            </button>
            <Link
                :href="admin.rateSettings.index()"
                class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
            >
                Cancel
            </Link>
        </div>
    </form>

    <div v-show="topTab === 'rounding'">
        <RateRoundingSettings v-model="roundingSettings" @save="submit" />
    </div>

    <div v-show="topTab === 'occupancy-pricing'">
        <OccupancyPricingSettings
            v-model="occupancyPricingSettings"
            @save="submit"
        />
    </div>

    <div v-show="topTab === 'package'">
        <RatePackages
            v-if="!managingPlan"
            v-model="packageSettings.packages"
            :room-categories="roomCategories"
            @save="submit"
            @manage-rates="openPackageRates"
            @deleted="forgetPackage"
        />
    </div>

    <div v-show="topTab === 'rate-planner'" class="flex flex-col gap-4">
        <div
            class="flex flex-wrap gap-1 border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <button
                type="button"
                class="border-b-2 px-4 py-2 text-sm font-medium"
                :class="
                    plannerSubTab === 'bar-calendar'
                        ? 'border-[#1b1b18] text-[#1b1b18] dark:border-[#EDEDEC] dark:text-[#EDEDEC]'
                        : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
                "
                @click="plannerSubTab = 'bar-calendar'"
            >
                BAR Calendar
            </button>
            <button
                type="button"
                class="border-b-2 px-4 py-2 text-sm font-medium"
                :class="
                    plannerSubTab === 'packages-calendar'
                        ? 'border-[#1b1b18] text-[#1b1b18] dark:border-[#EDEDEC] dark:text-[#EDEDEC]'
                        : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
                "
                @click="plannerSubTab = 'packages-calendar'"
            >
                Packages Calendar
            </button>
        </div>

        <template v-if="plannerSubTab === 'bar-calendar'">
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Select Year &amp; Apply Rate plans!
            </p>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <select
                        v-model.number="plannerYear"
                        class="block w-32 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >
                        <option
                            v-for="year in plannerYearOptions"
                            :key="year"
                            :value="year"
                        >
                            {{ year }}
                        </option>
                    </select>
                    <select
                        v-model="plannerPriceRow"
                        class="block w-56 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        title="The price shown on each date: single occupancy, direct rate"
                    >
                        <option value="lowest">Lowest rate</option>
                        <option
                            v-for="row in plannerPriceRows"
                            :key="row.key"
                            :value="row.key"
                        >
                            {{ row.label }}
                        </option>
                    </select>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="applyRatePlanner"
                    >
                        Apply
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#f53003] dark:border-[#3E3E3A] dark:text-[#FF4433]"
                        @click="cancelRatePlanner"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="resetRatePlannerYear"
                    >
                        Reset
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <span
                    class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Active Rate Plans:</span
                >
                <button
                    v-for="plan in activeRatePlans"
                    :key="plan.id"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium"
                    :class="
                        selectedBrushPlanId === plan.id
                            ? 'border-[#1b1b18] dark:border-[#EDEDEC]'
                            : 'border-transparent'
                    "
                    :title="`Select ${plan.name} to paint dates`"
                    @click="
                        selectedBrushPlanId =
                            selectedBrushPlanId === plan.id ? null : plan.id
                    "
                >
                    <span
                        class="inline-block h-3 w-3 rounded"
                        :style="{ backgroundColor: plan.color }"
                    />
                    <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                        plan.name
                    }}</span>
                </button>
                <span
                    v-if="!activeRatePlans.length"
                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    No active rate plans. Add one in the Rate Plan tab first.
                </span>
                <span v-else class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    (click a plan above, then click dates below to paint them)
                </span>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="month in plannerMonths"
                    :key="month.name"
                    class="rounded-lg border border-[#e3e3e0] p-3 dark:border-[#3E3E3A]"
                >
                    <h3
                        class="mb-2 text-center text-sm font-semibold tracking-wide text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
                    >
                        {{ month.name }}
                    </h3>
                    <table
                        class="w-full table-fixed border-collapse text-center text-xs"
                    >
                        <thead>
                            <tr>
                                <th
                                    v-for="label in weekDayLabels"
                                    :key="label"
                                    class="pb-1 font-medium text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    {{ label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(week, weekIndex) in month.weeks"
                                :key="weekIndex"
                            >
                                <td
                                    v-for="(cell, cellIndex) in week"
                                    :key="cellIndex"
                                    class="p-0.5"
                                >
                                    <button
                                        v-if="cell"
                                        type="button"
                                        class="flex h-10 w-full flex-col items-center justify-center rounded border border-[#e3e3e0] text-[11px] leading-tight dark:border-[#3E3E3A]"
                                        :class="
                                            isPastDate(cell.iso)
                                                ? 'cursor-not-allowed line-through opacity-40'
                                                : 'cursor-pointer'
                                        "
                                        :style="{
                                            backgroundColor:
                                                planColorFor(cell.iso) ??
                                                undefined,
                                        }"
                                        :disabled="isPastDate(cell.iso)"
                                        :title="`${cell.iso}: ${plannerPriceLabel(cell.iso)}`"
                                        @click="paintDay(cell.iso)"
                                    >
                                        <span>{{
                                            String(cell.day).padStart(2, '0')
                                        }}</span>
                                        <span
                                            class="text-[9px] text-[#706f6c] dark:text-[#A1A09A]"
                                            data-test="planner-price"
                                            >{{
                                                plannerPriceLabel(cell.iso)
                                            }}</span
                                        >
                                    </button>
                                    <div v-else class="h-10 w-full" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <template v-else>
            <p
                v-if="!packageSettings.packages.length"
                class="text-center text-sm text-[#f53003] italic dark:text-[#FF4433]"
            >
                Please create at least one package (Package tab).
            </p>
            <p v-else class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Select Year &amp; Apply Packages!
            </p>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <select
                    v-model.number="plannerYear"
                    aria-label="Year"
                    class="block w-32 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                >
                    <option
                        v-for="year in plannerYearOptions"
                        :key="year"
                        :value="year"
                    >
                        {{ year }}
                    </option>
                </select>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        :disabled="!packageSettings.packages.length"
                        class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="applyPackagePlanner"
                    >
                        Apply
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#f53003] dark:border-[#3E3E3A] dark:text-[#FF4433]"
                        @click="cancelPackagePlanner"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="resetPackagePlannerYear"
                    >
                        Reset
                    </button>
                </div>
            </div>

            <div
                v-if="packageSettings.packages.length"
                class="flex flex-wrap items-center gap-3"
            >
                <span
                    class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Packages:</span
                >
                <button
                    v-for="item in packageSettings.packages"
                    :key="item.id"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium"
                    :class="
                        selectedBrushPackageId === item.id
                            ? 'border-[#1b1b18] dark:border-[#EDEDEC]'
                            : 'border-transparent'
                    "
                    :title="`Select ${item.name} to add it to dates, or take it off them`"
                    @click="
                        selectedBrushPackageId =
                            selectedBrushPackageId === item.id ? null : item.id
                    "
                >
                    <span
                        class="inline-block h-3 w-3 rounded"
                        :style="{ backgroundColor: item.color }"
                    />
                    <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                        item.name
                    }}</span>
                </button>
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    (click a package above, then click dates below to add it;
                    click a date again to take it off)
                </span>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="month in plannerMonths"
                    :key="month.name"
                    class="rounded-lg border border-[#e3e3e0] p-3 dark:border-[#3E3E3A]"
                >
                    <h3
                        class="mb-2 text-center text-sm font-semibold tracking-wide text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
                    >
                        {{ month.name }}
                    </h3>
                    <table
                        class="w-full table-fixed border-collapse text-center text-xs"
                    >
                        <thead>
                            <tr>
                                <th
                                    v-for="label in weekDayLabels"
                                    :key="label"
                                    class="pb-1 font-medium text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    {{ label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(week, weekIndex) in month.weeks"
                                :key="weekIndex"
                            >
                                <td
                                    v-for="(cell, cellIndex) in week"
                                    :key="cellIndex"
                                    class="p-0.5"
                                >
                                    <button
                                        v-if="cell"
                                        type="button"
                                        class="flex h-7 w-full items-center justify-center rounded border border-[#e3e3e0] text-[11px] dark:border-[#3E3E3A]"
                                        :class="
                                            isPastDate(cell.iso)
                                                ? 'cursor-not-allowed line-through opacity-40'
                                                : 'cursor-pointer'
                                        "
                                        :style="{
                                            background: packageBackground(
                                                cell.iso,
                                            ),
                                        }"
                                        :title="
                                            packagesOn(cell.iso)
                                                .map((item) => item.name)
                                                .join(', ') || undefined
                                        "
                                        :disabled="isPastDate(cell.iso)"
                                        @click="paintPackageDay(cell.iso)"
                                    >
                                        {{ String(cell.day).padStart(2, '0') }}
                                    </button>
                                    <div v-else class="h-7 w-full" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>

    <div v-show="topTab === 'deals-promo'" class="flex flex-col gap-4">
        <h2
            class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Promo Codes
        </h2>

        <div
            class="flex flex-wrap gap-1 border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <button
                v-for="subTab in dealsPromoSubTabs"
                :key="subTab"
                type="button"
                class="border-b-2 px-4 py-2 text-sm font-medium"
                :class="
                    dealsPromoSubTab === subTab
                        ? 'border-[#1b1b18] text-[#1b1b18] dark:border-[#EDEDEC] dark:text-[#EDEDEC]'
                        : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
                "
                @click="dealsPromoSubTab = subTab"
            >
                {{ dealsPromoTabLabels[subTab] }}
            </button>
        </div>

        <template
            v-if="
                dealsPromoSubTab === 'public-promo-codes' ||
                dealsPromoSubTab === 'secret-promo-codes'
            "
        >
            <template v-if="!promoFormOpen">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label
                        v-if="dealsPromoSubTab === 'public-promo-codes'"
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <span>Auto-Apply Public Promo</span>
                        <span
                            title="Automatically apply the best eligible public promo code at checkout."
                            class="cursor-help text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            ⓘ
                        </span>
                        <span>:</span>
                        <input
                            v-model="
                                dealsPromotionSettings.auto_apply_public_promo
                            "
                            type="checkbox"
                            class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                        />
                    </label>
                    <span v-else />

                    <button
                        type="button"
                        class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="
                            openAddPromoForm(
                                dealsPromoSubTab === 'secret-promo-codes'
                                    ? 'secret_promo_codes'
                                    : 'public_promo_codes',
                            )
                        "
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
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Promo Code
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Booking Start Date
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Booking End Date
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Percentage/Fixed
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Status
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Discount
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Edit
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Delete
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                        >
                            <tr
                                v-for="promo in dealsPromoSubTab ===
                                'secret-promo-codes'
                                    ? dealsPromotionSettings.secret_promo_codes
                                    : dealsPromotionSettings.public_promo_codes"
                                :key="promo.id"
                                :class="
                                    promo.deleted
                                        ? 'text-[#a1a09a] line-through'
                                        : ''
                                "
                            >
                                <td class="px-4 py-3 text-sm">
                                    {{ promo.code }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    {{
                                        promo.booking_creation_date_range
                                            .start ?? 'None'
                                    }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    {{
                                        promo.booking_creation_date_range.end ??
                                        'None'
                                    }}
                                </td>
                                <td class="px-4 py-3 text-sm capitalize">
                                    {{ promo.discount_type }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span v-if="promo.deleted">{{
                                        promo.status
                                    }}</span>
                                    <input
                                        v-else
                                        v-model="promo.status"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                    />
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    {{ formatPromoDiscount(promo) }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <button
                                        type="button"
                                        class="text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                                        @click="
                                            openEditPromoForm(
                                                dealsPromoSubTab ===
                                                    'secret-promo-codes'
                                                    ? 'secret_promo_codes'
                                                    : 'public_promo_codes',
                                                promo,
                                            )
                                        "
                                    >
                                        ✎
                                    </button>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span v-if="promo.deleted">Deleted</span>
                                    <button
                                        v-else
                                        type="button"
                                        class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                                        @click="deletePromoCode(promo)"
                                    >
                                        🗑
                                    </button>
                                </td>
                            </tr>
                            <tr
                                v-if="
                                    !(
                                        dealsPromoSubTab ===
                                        'secret-promo-codes'
                                            ? dealsPromotionSettings.secret_promo_codes
                                            : dealsPromotionSettings.public_promo_codes
                                    ).length
                                "
                            >
                                <td
                                    colspan="8"
                                    class="px-4 py-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    No promo codes found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <div v-else class="flex flex-col gap-6">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-[#e3e3e0] pb-4 dark:border-[#3E3E3A]"
                >
                    <div class="flex items-center gap-6">
                        <label
                            class="flex items-center gap-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            <input
                                v-model="promoForm.deal_type"
                                type="radio"
                                value="basic"
                                class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                            />
                            Basic Deal
                        </label>
                        <label
                            class="flex items-center gap-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            <input
                                v-model="promoForm.deal_type"
                                type="radio"
                                value="free_room_night"
                                class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                            />
                            Free Room Night Deal
                        </label>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                            @click="savePromoCode"
                        >
                            Save
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                            @click="promoFormOpen = false"
                        >
                            Back
                        </button>
                    </div>
                </div>

                <p
                    class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Promo Code : {{ promoForm.code }}
                </p>

                <div class="flex flex-col gap-4">
                    <h3
                        class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Basic Details
                    </h3>

                    <div
                        class="grid grid-cols-1 items-start gap-4 md:grid-cols-[220px_1fr] md:items-center"
                    >
                        <label
                            class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                            >Add a Promo Code</label
                        >
                        <input
                            v-model="promoForm.code"
                            type="text"
                            placeholder="EX: SUMMER25"
                            class="block w-full max-w-md rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />

                        <label
                            class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                            >Add a Tag Line</label
                        >
                        <input
                            v-model="promoForm.tag_line"
                            type="text"
                            placeholder="Tagline..."
                            class="block w-full max-w-md rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />

                        <label
                            class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                            >Tag Line BE Carousel Label</label
                        >
                        <input
                            v-model="promoForm.be_carousel_label"
                            type="text"
                            placeholder="Booking engine carousel label"
                            class="block w-full max-w-md rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />

                        <label
                            class="text-sm text-[#706f6c] md:self-start md:text-right dark:text-[#A1A09A]"
                            >Description</label
                        >
                        <textarea
                            v-model="promoForm.description"
                            rows="3"
                            placeholder="Description"
                            class="block w-full max-w-md rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />

                        <template v-if="promoForm.deal_type === 'basic'">
                            <label
                                class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                                >Discount</label
                            >
                            <div class="flex flex-wrap items-center gap-3">
                                <label
                                    v-for="option in promoDiscountTypeOptions"
                                    :key="option.value"
                                    class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    <input
                                        v-model="promoForm.discount_type"
                                        type="radio"
                                        :value="option.value"
                                        class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                                    />
                                    {{ option.label }}
                                </label>
                                <input
                                    v-model.number="promoForm.discount_value"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    class="block w-32 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                />
                                <span
                                    v-if="
                                        promoForm.discount_type === 'percentage'
                                    "
                                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                    >%</span
                                >
                            </div>
                        </template>
                        <template v-else>
                            <p
                                class="text-sm text-[#706f6c] md:col-span-2 dark:text-[#A1A09A]"
                            >
                                How many paid nights will a customer need to
                                book in order to receive 1 free night?
                            </p>

                            <label
                                class="flex items-center gap-1 text-sm text-[#706f6c] md:justify-end md:text-right dark:text-[#A1A09A]"
                            >
                                Min Length Of Stay
                                <span
                                    title="Minimum number of paid nights required to earn the free night."
                                    class="cursor-help"
                                    >ⓘ</span
                                >
                            </label>
                            <input
                                v-model.number="promoForm.min_length_of_stay"
                                type="number"
                                min="0"
                                class="block w-32 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />

                            <label
                                class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                                >Free Night Available is</label
                            >
                            <div class="flex flex-wrap items-center gap-4">
                                <label
                                    v-for="option in promoFreeNightAvailabilityOptions"
                                    :key="option.value"
                                    class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    <input
                                        v-model="
                                            promoForm.free_night_available_is
                                        "
                                        type="radio"
                                        :value="option.value"
                                        class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                                    />
                                    {{ option.label }}
                                </label>
                            </div>
                        </template>

                        <div />
                        <label
                            class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            <input
                                v-model="
                                    promoForm.required_specific_payment_gateway
                                "
                                type="checkbox"
                                class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                            />
                            Required Specific Payment Gateway
                        </label>
                    </div>
                </div>

                <div
                    class="flex flex-col gap-4 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
                >
                    <h3
                        class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Channels
                    </h3>
                    <div
                        class="grid grid-cols-1 items-center gap-4 md:grid-cols-[220px_1fr]"
                    >
                        <label
                            class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                            >Select Channels</label
                        >
                        <div class="flex flex-wrap gap-4">
                            <label
                                v-for="option in promoChannelOptions"
                                :key="option.value"
                                class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                <input
                                    type="checkbox"
                                    :checked="
                                        promoForm.channels.includes(
                                            option.value,
                                        )
                                    "
                                    class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                    @change="togglePromoChannel(option.value)"
                                />
                                {{ option.label }}
                            </label>
                        </div>
                    </div>
                </div>

                <div
                    class="flex flex-col gap-4 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
                >
                    <h3
                        class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Applicable Dates
                    </h3>

                    <div
                        v-for="field in promoDateRangeFields"
                        :key="field.key"
                        class="grid grid-cols-1 items-center gap-4 md:grid-cols-[220px_44px_1fr]"
                    >
                        <label
                            class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                            >{{ field.label }}</label
                        >
                        <button
                            type="button"
                            class="relative h-5 w-9 rounded-full transition-colors"
                            :class="
                                promoForm[field.key].enabled
                                    ? 'bg-[#1b1b18] dark:bg-[#eeeeec]'
                                    : 'bg-[#e3e3e0] dark:bg-[#3E3E3A]'
                            "
                            @click="
                                promoForm[field.key].enabled =
                                    !promoForm[field.key].enabled
                            "
                        >
                            <span
                                class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-transform dark:bg-[#161615]"
                                :class="
                                    promoForm[field.key].enabled
                                        ? 'translate-x-4'
                                        : 'translate-x-0.5'
                                "
                            />
                        </button>
                        <div
                            v-if="promoForm[field.key].enabled"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <input
                                v-model="promoForm[field.key].start"
                                type="date"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <span
                                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >to</span
                            >
                            <input
                                v-model="promoForm[field.key].end"
                                type="date"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </div>
                        <span
                            v-else
                            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >{{ field.offText }}</span
                        >
                    </div>

                    <div
                        class="grid grid-cols-1 items-center gap-4 md:grid-cols-[220px_44px_1fr]"
                    >
                        <label
                            class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                            >Stay days of the week</label
                        >
                        <button
                            type="button"
                            class="relative h-5 w-9 rounded-full transition-colors"
                            :class="
                                promoForm.stay_days_of_week.enabled
                                    ? 'bg-[#1b1b18] dark:bg-[#eeeeec]'
                                    : 'bg-[#e3e3e0] dark:bg-[#3E3E3A]'
                            "
                            @click="
                                promoForm.stay_days_of_week.enabled =
                                    !promoForm.stay_days_of_week.enabled
                            "
                        >
                            <span
                                class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-transform dark:bg-[#161615]"
                                :class="
                                    promoForm.stay_days_of_week.enabled
                                        ? 'translate-x-4'
                                        : 'translate-x-0.5'
                                "
                            />
                        </button>
                        <div
                            v-if="promoForm.stay_days_of_week.enabled"
                            class="flex flex-wrap gap-3"
                        >
                            <label
                                v-for="day in dayKeys"
                                :key="day"
                                class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                <input
                                    type="checkbox"
                                    :checked="
                                        promoForm.stay_days_of_week.days.includes(
                                            day,
                                        )
                                    "
                                    class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                    @change="toggleStayDay(day)"
                                />
                                {{ dayLabels[day] }}
                            </label>
                        </div>
                        <span
                            v-else
                            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >Applied to all days of the week</span
                        >
                    </div>

                    <div
                        v-if="promoForm.deal_type === 'basic'"
                        class="grid grid-cols-1 items-center gap-4 md:grid-cols-[220px_44px_1fr]"
                    >
                        <label
                            class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                            >Exclusion Date range</label
                        >
                        <button
                            type="button"
                            class="relative h-5 w-9 rounded-full transition-colors"
                            :class="
                                promoForm.exclusion_date_range.enabled
                                    ? 'bg-[#1b1b18] dark:bg-[#eeeeec]'
                                    : 'bg-[#e3e3e0] dark:bg-[#3E3E3A]'
                            "
                            @click="
                                promoForm.exclusion_date_range.enabled =
                                    !promoForm.exclusion_date_range.enabled
                            "
                        >
                            <span
                                class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-transform dark:bg-[#161615]"
                                :class="
                                    promoForm.exclusion_date_range.enabled
                                        ? 'translate-x-4'
                                        : 'translate-x-0.5'
                                "
                            />
                        </button>
                        <div
                            v-if="promoForm.exclusion_date_range.enabled"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <input
                                v-model="promoForm.exclusion_date_range.start"
                                type="date"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <span
                                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >to</span
                            >
                            <input
                                v-model="promoForm.exclusion_date_range.end"
                                type="date"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </div>
                        <span
                            v-else
                            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >Applied to all dates</span
                        >
                    </div>
                </div>

                <div
                    class="flex flex-col gap-4 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
                >
                    <h3
                        class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Applicable Categories &amp; Rate Plans
                    </h3>

                    <div
                        v-for="field in promoCategoryToggleFields"
                        :key="field.key"
                        class="grid grid-cols-1 items-center gap-4 md:grid-cols-[220px_44px_1fr]"
                    >
                        <label
                            class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                            >{{ field.label }}</label
                        >
                        <button
                            type="button"
                            class="relative h-5 w-9 rounded-full transition-colors"
                            :class="
                                promoForm[field.key].enabled
                                    ? 'bg-[#1b1b18] dark:bg-[#eeeeec]'
                                    : 'bg-[#e3e3e0] dark:bg-[#3E3E3A]'
                            "
                            @click="
                                promoForm[field.key].enabled =
                                    !promoForm[field.key].enabled
                            "
                        >
                            <span
                                class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-transform dark:bg-[#161615]"
                                :class="
                                    promoForm[field.key].enabled
                                        ? 'translate-x-4'
                                        : 'translate-x-0.5'
                                "
                            />
                        </button>
                        <span
                            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{
                                promoForm[field.key].enabled
                                    ? 'Restricted to selected rate plans'
                                    : field.offText
                            }}
                        </span>
                    </div>
                </div>

                <div
                    class="flex flex-col gap-4 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
                >
                    <h3
                        class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Additional Conditions
                    </h3>

                    <template v-if="promoForm.deal_type === 'basic'">
                        <div
                            v-for="field in promoAdditionalConditionFields"
                            :key="field.key"
                            class="grid grid-cols-1 items-center gap-4 md:grid-cols-[220px_20px_44px_1fr]"
                        >
                            <label
                                class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                                >{{ field.label }}</label
                            >
                            <span
                                :title="field.info"
                                class="cursor-help text-[#706f6c] dark:text-[#A1A09A]"
                                >ⓘ</span
                            >
                            <button
                                type="button"
                                class="relative h-5 w-9 rounded-full transition-colors"
                                :class="
                                    promoForm[field.key].enabled
                                        ? 'bg-[#1b1b18] dark:bg-[#eeeeec]'
                                        : 'bg-[#e3e3e0] dark:bg-[#3E3E3A]'
                                "
                                @click="
                                    promoForm[field.key].enabled =
                                        !promoForm[field.key].enabled
                                "
                            >
                                <span
                                    class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-transform dark:bg-[#161615]"
                                    :class="
                                        promoForm[field.key].enabled
                                            ? 'translate-x-4'
                                            : 'translate-x-0.5'
                                    "
                                />
                            </button>
                            <div
                                v-if="promoForm[field.key].enabled"
                                class="flex items-center gap-2"
                            >
                                <input
                                    v-model.number="promoForm[field.key].value"
                                    type="number"
                                    min="0"
                                    class="block w-32 rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
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
                            class="grid grid-cols-1 items-center gap-4 md:grid-cols-[220px_20px_44px_1fr]"
                        >
                            <label
                                class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                                >Booking amount range</label
                            >
                            <span
                                title="Restrict this promo to bookings within an amount range."
                                class="cursor-help text-[#706f6c] dark:text-[#A1A09A]"
                                >ⓘ</span
                            >
                            <button
                                type="button"
                                class="relative h-5 w-9 rounded-full transition-colors"
                                :class="
                                    promoForm.booking_amount_range.enabled
                                        ? 'bg-[#1b1b18] dark:bg-[#eeeeec]'
                                        : 'bg-[#e3e3e0] dark:bg-[#3E3E3A]'
                                "
                                @click="
                                    promoForm.booking_amount_range.enabled =
                                        !promoForm.booking_amount_range.enabled
                                "
                            >
                                <span
                                    class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-transform dark:bg-[#161615]"
                                    :class="
                                        promoForm.booking_amount_range.enabled
                                            ? 'translate-x-4'
                                            : 'translate-x-0.5'
                                    "
                                />
                            </button>
                            <div
                                v-if="promoForm.booking_amount_range.enabled"
                                class="flex items-center gap-2"
                            >
                                <input
                                    v-model.number="
                                        promoForm.booking_amount_range.min
                                    "
                                    type="number"
                                    min="0"
                                    placeholder="Min"
                                    class="block w-28 rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                />
                                <span
                                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                    >to</span
                                >
                                <input
                                    v-model.number="
                                        promoForm.booking_amount_range.max
                                    "
                                    type="number"
                                    min="0"
                                    placeholder="Max"
                                    class="block w-28 rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                />
                            </div>
                            <span
                                v-else
                                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >Not applicable</span
                            >
                        </div>
                    </template>

                    <div
                        class="grid grid-cols-1 items-center gap-4 md:grid-cols-[220px_20px_44px_1fr]"
                    >
                        <label
                            class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                            >Applicable on Pay@hotel</label
                        >
                        <span
                            title="Whether this promo can be used on Pay-at-hotel bookings."
                            class="cursor-help text-[#706f6c] dark:text-[#A1A09A]"
                            >ⓘ</span
                        >
                        <button
                            type="button"
                            class="relative h-5 w-9 rounded-full transition-colors"
                            :class="
                                promoForm.applicable_on_pay_at_hotel
                                    ? 'bg-[#1b1b18] dark:bg-[#eeeeec]'
                                    : 'bg-[#e3e3e0] dark:bg-[#3E3E3A]'
                            "
                            @click="
                                promoForm.applicable_on_pay_at_hotel =
                                    !promoForm.applicable_on_pay_at_hotel
                            "
                        >
                            <span
                                class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-transform dark:bg-[#161615]"
                                :class="
                                    promoForm.applicable_on_pay_at_hotel
                                        ? 'translate-x-4'
                                        : 'translate-x-0.5'
                                "
                            />
                        </button>
                        <span
                            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >{{
                                promoForm.applicable_on_pay_at_hotel
                                    ? 'Applied'
                                    : 'Not applicable'
                            }}</span
                        >
                    </div>

                    <div
                        class="grid grid-cols-1 gap-2 md:grid-cols-[220px_1fr] md:items-start"
                    >
                        <label
                            class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                            >Common Terms &amp; Conditions</label
                        >
                        <textarea
                            v-model="promoForm.terms_and_conditions"
                            rows="5"
                            placeholder="Terms & conditions shown to guests for this promo code."
                            class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                </div>

                <div
                    class="flex flex-col gap-4 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
                >
                    <h3
                        class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Activity Logs
                    </h3>

                    <template v-if="editingPromoId !== null">
                        <div class="flex flex-wrap items-end gap-4">
                            <div class="flex flex-col gap-1">
                                <label
                                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                    >Staff:</label
                                >
                                <select
                                    v-model="promoActivityLogStaff"
                                    class="block w-48 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                >
                                    <option value="">All Staff</option>
                                </select>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label
                                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                    >Action Date Range:</label
                                >
                                <div class="flex items-center gap-2">
                                    <input
                                        v-model="promoActivityLogDateFrom"
                                        type="date"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                    <span
                                        class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                        >to</span
                                    >
                                    <input
                                        v-model="promoActivityLogDateTo"
                                        type="date"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </div>
                            </div>

                            <div class="ml-auto flex items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700"
                                >
                                    Apply
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                                    @click="resetPromoActivityLogFilter"
                                >
                                    Reset
                                </button>
                            </div>
                        </div>

                        <div
                            class="border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
                        >
                            <p
                                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                No activity yet.
                            </p>
                        </div>
                    </template>
                </div>

                <div
                    class="flex items-center gap-2 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="savePromoCode"
                    >
                        Save
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="promoFormOpen = false"
                    >
                        Back
                    </button>
                </div>
            </div>
        </template>

        <template v-else-if="dealsPromoSubTab === 'payment-coupons'">
            <template v-if="!paymentCouponFormOpen">
                <div class="flex items-center justify-end">
                    <button
                        type="button"
                        class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="openAddPaymentCouponForm"
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
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Coupon Code
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Amount
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Valid From
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Valid To
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Sold
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Remaining
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Edit
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                                >
                                    Delete
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                        >
                            <tr
                                v-for="coupon in dealsPromotionSettings.payment_coupons"
                                :key="coupon.id"
                                :class="
                                    coupon.deleted
                                        ? 'text-[#a1a09a] line-through'
                                        : ''
                                "
                            >
                                <td class="px-4 py-3 text-sm">
                                    {{ coupon.code }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    {{ coupon.amount ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    {{ coupon.valid_from ?? 'None' }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    {{ coupon.valid_to ?? 'None' }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    {{ coupon.sold_count }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    {{ remainingCouponCount(coupon) ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <button
                                        type="button"
                                        class="text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                                        @click="
                                            openEditPaymentCouponForm(coupon)
                                        "
                                    >
                                        ✎
                                    </button>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span v-if="coupon.deleted">Deleted</span>
                                    <button
                                        v-else
                                        type="button"
                                        class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                                        @click="deletePaymentCoupon(coupon)"
                                    >
                                        🗑
                                    </button>
                                </td>
                            </tr>
                            <tr
                                v-if="
                                    !dealsPromotionSettings.payment_coupons
                                        .length
                                "
                            >
                                <td
                                    colspan="8"
                                    class="px-4 py-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    No payment coupons found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <div v-else class="flex flex-col gap-6">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-[#e3e3e0] pb-4 dark:border-[#3E3E3A]"
                >
                    <h3
                        class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Basic Details
                    </h3>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                            @click="savePaymentCoupon"
                        >
                            Save
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                            @click="paymentCouponFormOpen = false"
                        >
                            Back
                        </button>
                    </div>
                </div>

                <div
                    class="grid grid-cols-1 items-start gap-4 md:grid-cols-[220px_1fr] md:items-center"
                >
                    <label
                        class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                        >Coupon Code</label
                    >
                    <input
                        v-model="paymentCouponForm.code"
                        type="text"
                        placeholder="EX: SUMMER25"
                        class="block w-full max-w-md rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />

                    <label
                        class="text-sm text-[#706f6c] md:self-start md:text-right dark:text-[#A1A09A]"
                        >Coupon Description</label
                    >
                    <textarea
                        v-model="paymentCouponForm.description"
                        rows="3"
                        placeholder="Description"
                        class="block w-full max-w-md rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />

                    <label
                        class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                        >Coupon Amount</label
                    >
                    <input
                        v-model.number="paymentCouponForm.amount"
                        type="number"
                        min="0"
                        class="block w-full max-w-md rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />

                    <label
                        class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                        >Max Total Coupon Count</label
                    >
                    <input
                        v-model.number="paymentCouponForm.max_total_count"
                        type="number"
                        min="0"
                        class="block w-full max-w-md rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />

                    <label
                        class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                        >Sold Coupon Count</label
                    >
                    <p class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ paymentCouponForm.sold_count }}
                    </p>

                    <label
                        class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                        >Remaining Coupon Count</label
                    >
                    <p class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ remainingCouponCount(paymentCouponForm) ?? '—' }}
                    </p>

                    <label
                        class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                        >Coupon Valid</label
                    >
                    <div class="flex flex-wrap items-center gap-2">
                        <input
                            v-model="paymentCouponForm.valid_from"
                            type="date"
                            class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <button
                            type="button"
                            title="Clear start date"
                            class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                            @click="paymentCouponForm.valid_from = null"
                        >
                            🗑
                        </button>
                        <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >to</span
                        >
                        <input
                            v-model="paymentCouponForm.valid_to"
                            type="date"
                            class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <button
                            type="button"
                            title="Clear end date"
                            class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                            @click="paymentCouponForm.valid_to = null"
                        >
                            🗑
                        </button>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <label
                        class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Notes:</label
                    >
                    <textarea
                        v-model="paymentCouponForm.notes"
                        rows="6"
                        class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </div>

                <div
                    class="flex flex-col gap-4 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
                >
                    <h3
                        class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Activity Logs
                    </h3>

                    <template v-if="editingPaymentCouponId !== null">
                        <div class="flex flex-wrap items-end gap-4">
                            <div class="flex flex-col gap-1">
                                <label
                                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                    >Staff:</label
                                >
                                <select
                                    v-model="couponActivityLogStaff"
                                    class="block w-48 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                >
                                    <option value="">All Staff</option>
                                </select>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label
                                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                    >Action Date Range:</label
                                >
                                <div class="flex items-center gap-2">
                                    <input
                                        v-model="couponActivityLogDateFrom"
                                        type="date"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                    <span
                                        class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                        >to</span
                                    >
                                    <input
                                        v-model="couponActivityLogDateTo"
                                        type="date"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </div>
                            </div>

                            <div class="ml-auto flex items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700"
                                >
                                    Apply
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                                    @click="resetCouponActivityLogFilter"
                                >
                                    Reset
                                </button>
                            </div>
                        </div>

                        <div
                            class="border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
                        >
                            <p
                                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                No activity yet.
                            </p>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <template v-else>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                No
                {{ dealsPromoTabLabels[dealsPromoSubTab].toLowerCase() }}
                configured yet.
            </p>
        </template>

        <div
            v-if="
                !(
                    (dealsPromoSubTab === 'public-promo-codes' ||
                        dealsPromoSubTab === 'secret-promo-codes') &&
                    promoFormOpen
                ) &&
                !(
                    dealsPromoSubTab === 'payment-coupons' &&
                    paymentCouponFormOpen
                )
            "
            class="flex items-center gap-3"
        >
            <button
                type="button"
                :disabled="form.processing"
                class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                @click="submit"
            >
                {{ form.processing ? 'Saving...' : 'Save changes' }}
            </button>
            <Link
                :href="admin.rateSettings.index()"
                class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
            >
                Cancel
            </Link>
        </div>
    </div>
</template>
