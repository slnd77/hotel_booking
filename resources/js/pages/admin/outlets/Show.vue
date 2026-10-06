<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import OutletTablesPanel, {
    type OutletTableRow,
} from '@/components/OutletTablesPanel.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useCurrency } from '@/lib/useCurrency';
import { calendarDate, usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

defineOptions({ layout: AdminLayout });

type DisplayCategory = {
    id: number;
    name: string;
    display_order: number;
    items_count: number;
};
type Item = {
    id: number;
    name: string;
    outlet_display_category_id: number | null;
    display_category: string | null;
    tax_category_id: number | null;
    tax_category: string | null;
    outlet_tax_rate_id: number | null;
    tax_rate: string | null;
    sac_hsn_code: string | null;
    item_type: string;
    status: boolean;
    display_order: number;
};
type TaxRate = {
    id: number;
    name: string;
    rate: number;
    status: boolean;
    items_count: number;
};
type PricePlan = {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
    status: boolean;
    prices: Record<number, number>;
};

const props = defineProps<{
    property: { id: number; name: string };
    outlet: { id: number; name: string; type: string; qr_ordering: boolean };
    displayCategories: DisplayCategory[];
    items: Item[];
    pricePlans: PricePlan[];
    taxRates: TaxRate[];
    taxCategories: { id: number; name: string }[];
    itemTypes: { value: string; label: string }[];
    activityLogs: { action: string; user: string | null; at: string | null }[];
    tables: OutletTableRow[];
}>();

const { symbol } = useCurrency();
const propertyTime = usePropertyTime();
const scope = computed(() => ({
    property: props.property.id,
    outlet: props.outlet.id,
}));

type Tab = 'categories' | 'items' | 'plans' | 'taxes' | 'tables';
/** Tables (floor plan, QR ordering) belong to food & beverage outlets only. */
const hasTables = computed(() => props.outlet.type === 'FoodAndBeverages');
const tabs = computed<{ key: Tab; label: string }[]>(() => [
    { key: 'categories', label: 'Display Categories' },
    { key: 'items', label: 'Catalogue Items' },
    { key: 'plans', label: 'Price Plans' },
    { key: 'taxes', label: 'Tax Settings' },
    ...(hasTables.value ? [{ key: 'tables' as const, label: 'Tables' }] : []),
]);
const requestedTab = new URLSearchParams(
    typeof window === 'undefined' ? '' : window.location.search,
).get('tab') as Tab | null;
const activeTab = ref<Tab>(
    requestedTab && (requestedTab !== 'tables' || hasTables.value)
        ? requestedTab
        : 'categories',
);
const activityLogsOpen = ref(false);
const itemTypeLabel = (value: string): string =>
    props.itemTypes.find((type) => type.value === value)?.label ?? value;

// ── Display Categories ──
const categoryModalOpen = ref(false);
const editingCategoryId = ref<number | null>(null);
const categoryForm = useForm({
    name: '',
    display_order: null as number | null,
});

function openCategory(category: DisplayCategory | null): void {
    editingCategoryId.value = category?.id ?? null;
    categoryForm.clearErrors();
    Object.assign(categoryForm, {
        name: category?.name ?? '',
        display_order: category?.display_order ?? null,
    });
    categoryModalOpen.value = true;
}

function saveCategory(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (categoryModalOpen.value = false),
    };

    if (editingCategoryId.value === null) {
        categoryForm.post(
            admin.outlets.displayCategories.store(scope.value).url,
            options,
        );
    } else {
        categoryForm.put(
            admin.outlets.displayCategories.update({
                ...scope.value,
                displayCategory: editingCategoryId.value,
            }).url,
            options,
        );
    }
}

function deleteCategory(category: DisplayCategory): void {
    if (confirm(`Delete display category ${category.name}?`)) {
        router.delete(
            admin.outlets.displayCategories.destroy({
                ...scope.value,
                displayCategory: category.id,
            }).url,
            { preserveScroll: true },
        );
    }
}

// ── Catalogue Items: the tick is whether the outlet sells the item; Save stores the ticks ──
const search = ref('');
const page = ref(1);
const perPage = 10;
const activeItemIds = ref<number[]>(
    props.items.filter((item) => item.status).map((item) => item.id),
);
watch(
    () => props.items,
    (items) =>
        (activeItemIds.value = items
            .filter((item) => item.status)
            .map((item) => item.id)),
);
const filteredItems = computed(() => {
    const term = search.value.trim().toLowerCase();

    return term === ''
        ? props.items
        : props.items.filter((item) =>
              [item.name, item.display_category, item.tax_category].some(
                  (value) => value?.toLowerCase().includes(term),
              ),
          );
});
const pageCount = computed(() =>
    Math.max(1, Math.ceil(filteredItems.value.length / perPage)),
);
const pagedItems = computed(() =>
    filteredItems.value.slice((page.value - 1) * perPage, page.value * perPage),
);
watch(search, () => (page.value = 1));
const allOnPageActive = computed({
    get: () =>
        pagedItems.value.length > 0 &&
        pagedItems.value.every((item) => activeItemIds.value.includes(item.id)),
    set: (value: boolean) => {
        const ids = pagedItems.value.map((item) => item.id);
        activeItemIds.value = value
            ? [...new Set([...activeItemIds.value, ...ids])]
            : activeItemIds.value.filter((id) => !ids.includes(id));
    },
});

function saveItemStatuses(): void {
    router.put(
        admin.outlets.items.statuses(scope.value).url,
        { active_item_ids: activeItemIds.value },
        { preserveScroll: true },
    );
}

const itemModalOpen = ref(false);
const editingItemId = ref<number | null>(null);
const itemForm = useForm({
    name: '',
    outlet_display_category_id: null as number | null,
    tax_category_id: null as number | null,
    outlet_tax_rate_id: null as number | null,
    sac_hsn_code: '',
    item_type: 'none',
    status: true,
    display_order: null as number | null,
});
/** Rates offered for an item: the active ones, plus the item's own even if since switched off. */
const selectableTaxRates = computed(() =>
    props.taxRates.filter(
        (taxRate) =>
            taxRate.status || taxRate.id === itemForm.outlet_tax_rate_id,
    ),
);

function openItem(item: Item | null): void {
    editingItemId.value = item?.id ?? null;
    itemForm.clearErrors();
    Object.assign(itemForm, {
        name: item?.name ?? '',
        outlet_display_category_id:
            item?.outlet_display_category_id ??
            props.displayCategories[0]?.id ??
            null,
        tax_category_id: item?.tax_category_id ?? null,
        outlet_tax_rate_id: item?.outlet_tax_rate_id ?? null,
        sac_hsn_code: item?.sac_hsn_code ?? '',
        item_type: item?.item_type ?? 'none',
        status: item?.status ?? true,
        display_order: item?.display_order ?? null,
    });
    itemModalOpen.value = true;
}

function saveItem(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (itemModalOpen.value = false),
    };

    if (editingItemId.value === null) {
        itemForm.post(admin.outlets.items.store(scope.value).url, options);
    } else {
        itemForm.put(
            admin.outlets.items.update({
                ...scope.value,
                item: editingItemId.value,
            }).url,
            options,
        );
    }
}

function deleteItem(item: Item): void {
    if (
        confirm(
            `Delete item ${item.name}? Its prices in every price plan go too.`,
        )
    ) {
        router.delete(
            admin.outlets.items.destroy({ ...scope.value, item: item.id }).url,
            {
                preserveScroll: true,
                onSuccess: () => (itemModalOpen.value = false),
            },
        );
    }
}

// ── Tax Settings: the outlet's tax rates ──
const taxRateModalOpen = ref(false);
const editingTaxRateId = ref<number | null>(null);
const taxRateForm = useForm({
    name: '',
    rate: null as number | null,
    status: true,
});

function openTaxRate(taxRate: TaxRate | null): void {
    editingTaxRateId.value = taxRate?.id ?? null;
    taxRateForm.clearErrors();
    Object.assign(taxRateForm, {
        name: taxRate?.name ?? '',
        rate: taxRate?.rate ?? null,
        status: taxRate?.status ?? true,
    });
    taxRateModalOpen.value = true;
}

function saveTaxRate(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (taxRateModalOpen.value = false),
    };

    if (editingTaxRateId.value === null) {
        taxRateForm.post(
            admin.outlets.taxRates.store(scope.value).url,
            options,
        );
    } else {
        taxRateForm.put(
            admin.outlets.taxRates.update({
                ...scope.value,
                taxRate: editingTaxRateId.value,
            }).url,
            options,
        );
    }
}

function toggleTaxRateStatus(taxRate: TaxRate): void {
    router.put(
        admin.outlets.taxRates.update({ ...scope.value, taxRate: taxRate.id })
            .url,
        { name: taxRate.name, rate: taxRate.rate, status: !taxRate.status },
        { preserveScroll: true },
    );
}

function deleteTaxRate(taxRate: TaxRate): void {
    if (confirm(`Delete tax rate ${taxRate.name}?`)) {
        router.delete(
            admin.outlets.taxRates.destroy({
                ...scope.value,
                taxRate: taxRate.id,
            }).url,
            { preserveScroll: true },
        );
    }
}

// ── Price Plans ──
const planModalOpen = ref(false);
const editingPlanId = ref<number | null>(null);
const planForm = useForm({
    name: '',
    start_date: '',
    end_date: '2099-12-31',
    status: true,
});

function openPlan(plan: PricePlan | null): void {
    editingPlanId.value = plan?.id ?? null;
    planForm.clearErrors();
    Object.assign(planForm, {
        name: plan?.name ?? '',
        start_date: plan?.start_date ?? propertyTime.today(),
        end_date: plan?.end_date ?? '2099-12-31',
        status: plan?.status ?? true,
    });
    planModalOpen.value = true;
}

function savePlan(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (planModalOpen.value = false),
    };

    if (editingPlanId.value === null) {
        planForm.post(admin.outlets.pricePlans.store(scope.value).url, options);
    } else {
        planForm.put(
            admin.outlets.pricePlans.update({
                ...scope.value,
                pricePlan: editingPlanId.value,
            }).url,
            options,
        );
    }
}

function togglePlanStatus(plan: PricePlan): void {
    router.put(
        admin.outlets.pricePlans.update({ ...scope.value, pricePlan: plan.id })
            .url,
        {
            name: plan.name,
            start_date: plan.start_date,
            end_date: plan.end_date,
            status: !plan.status,
        },
        { preserveScroll: true },
    );
}

function deletePlan(plan: PricePlan): void {
    if (confirm(`Delete price plan ${plan.name}?`)) {
        router.delete(
            admin.outlets.pricePlans.destroy({
                ...scope.value,
                pricePlan: plan.id,
            }).url,
            { preserveScroll: true },
        );
    }
}

// View Items: the plan's price for each item (blank = not priced in this plan).
const pricesPlan = ref<PricePlan | null>(null);
const priceInputs = ref<Record<number, string>>({});
const pricesSaving = ref(false);
const pricesErrors = ref<Record<string, string>>({});

function openPrices(plan: PricePlan): void {
    pricesPlan.value = plan;
    pricesErrors.value = {};
    priceInputs.value = Object.fromEntries(
        props.items.map((item) => [
            item.id,
            plan.prices[item.id] === undefined
                ? ''
                : String(plan.prices[item.id]),
        ]),
    );
}

function savePrices(): void {
    if (!pricesPlan.value) {
        return;
    }

    pricesSaving.value = true;
    router.put(
        admin.outlets.pricePlans.prices({
            ...scope.value,
            pricePlan: pricesPlan.value.id,
        }).url,
        {
            prices: props.items.map((item) => ({
                outlet_item_id: item.id,
                price:
                    priceInputs.value[item.id] === ''
                        ? null
                        : priceInputs.value[item.id],
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => (pricesPlan.value = null),
            onError: (errors) => (pricesErrors.value = errors),
            onFinish: () => (pricesSaving.value = false),
        },
    );
}

const pricedCount = (plan: PricePlan): number =>
    Object.keys(plan.prices).length;

const inputClass =
    'w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const thClass = 'px-4 py-3';
const tdClass = 'px-4 py-2.5';
const primaryButton =
    'rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]';
const secondaryButton =
    'rounded-md border border-[#e3e3e0] px-4 py-2 text-sm dark:border-[#3E3E3A]';
</script>

<template>
    <Head :title="`Outlet: ${outlet.name}`" />

    <div class="mb-4 flex items-center justify-between">
        <div>
            <Link
                :href="admin.outlets.edit(property.id).url"
                class="text-sm text-[#706f6c] hover:underline dark:text-[#A1A09A]"
                >← Outlets</Link
            >
            <h1
                class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                {{ outlet.name }}
            </h1>
        </div>
        <button
            type="button"
            :class="secondaryButton"
            @click="activityLogsOpen = !activityLogsOpen"
        >
            Activity Logs
        </button>
    </div>

    <div
        v-if="activityLogsOpen"
        class="mb-6 flex flex-col gap-2 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
    >
        <p
            v-if="activityLogs.length === 0"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            No activity yet.
        </p>
        <ul
            v-else
            class="flex max-h-72 flex-col gap-1.5 overflow-y-auto text-sm"
        >
            <li v-for="(log, index) in activityLogs" :key="index">
                {{ log.action }}
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >— {{ log.user ?? 'System' }},
                    {{ propertyTime.dateTime(log.at) }}</span
                >
            </li>
        </ul>
    </div>

    <div
        class="mb-4 flex gap-1 border-b-2 border-[#1b1b18] dark:border-[#EDEDEC]"
        role="tablist"
    >
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            role="tab"
            :aria-selected="activeTab === tab.key"
            class="px-4 py-2 text-sm font-medium tracking-wide uppercase"
            :class="
                activeTab === tab.key
                    ? 'bg-[#1b1b18] text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                    : 'text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A]'
            "
            @click="activeTab = tab.key"
        >
            {{ tab.label }}
        </button>
    </div>

    <!-- Tables: floor plan and QR ordering -->
    <OutletTablesPanel
        v-if="activeTab === 'tables' && hasTables"
        :property="property"
        :outlet="outlet"
        :tables="tables"
    />

    <!-- Display Categories -->
    <section v-else-if="activeTab === 'categories'">
        <div class="mb-3 flex justify-end">
            <button
                type="button"
                :class="primaryButton"
                @click="openCategory(null)"
            >
                + Add
            </button>
        </div>
        <div
            class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="bg-[#FDFDFC] text-xs tracking-wide text-[#706f6c] uppercase dark:bg-[#161615] dark:text-[#A1A09A]"
                >
                    <tr>
                        <th :class="thClass">Name</th>
                        <th :class="thClass">Display Order</th>
                        <th :class="thClass">Items</th>
                        <th :class="thClass">Edit</th>
                        <th :class="thClass">Delete</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="displayCategories.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-6 text-center text-[#706f6c]"
                        >
                            No display categories yet.
                        </td>
                    </tr>
                    <tr
                        v-for="category in displayCategories"
                        :key="category.id"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <td :class="tdClass">{{ category.name }}</td>
                        <td :class="tdClass">{{ category.display_order }}</td>
                        <td :class="tdClass">{{ category.items_count }}</td>
                        <td :class="tdClass">
                            <button
                                type="button"
                                :aria-label="`Edit ${category.name}`"
                                @click="openCategory(category)"
                            >
                                ✎
                            </button>
                        </td>
                        <td :class="tdClass">
                            <button
                                type="button"
                                class="hover:text-red-600"
                                :aria-label="`Delete ${category.name}`"
                                @click="deleteCategory(category)"
                            >
                                🗑
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Catalogue Items -->
    <section v-else-if="activeTab === 'items'">
        <div class="mb-3 flex items-center justify-between gap-3">
            <input
                v-model="search"
                type="search"
                placeholder="Search Items"
                :class="[inputClass, 'max-w-xs']"
            />
            <button
                type="button"
                :class="primaryButton"
                @click="openItem(null)"
            >
                + Add Item
            </button>
        </div>
        <div
            class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="bg-[#FDFDFC] text-xs tracking-wide text-[#706f6c] uppercase dark:bg-[#161615] dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="w-10 px-4 py-3">
                            <input
                                v-model="allOnPageActive"
                                type="checkbox"
                                class="h-4 w-4"
                                aria-label="Sell every item on this page"
                            />
                        </th>
                        <th :class="thClass">Name</th>
                        <th :class="thClass">Display Category</th>
                        <th :class="thClass">Tax Category</th>
                        <th :class="thClass">Tax Rate</th>
                        <th :class="thClass">SAC/HSN</th>
                        <th :class="thClass">Item Type</th>
                        <th :class="thClass">Edit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="pagedItems.length === 0">
                        <td
                            colspan="8"
                            class="px-4 py-6 text-center text-[#706f6c]"
                        >
                            {{
                                items.length === 0
                                    ? 'No items yet.'
                                    : 'No items match.'
                            }}
                        </td>
                    </tr>
                    <tr
                        v-for="item in pagedItems"
                        :key="item.id"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <td class="px-4 py-2.5">
                            <input
                                v-model="activeItemIds"
                                :value="item.id"
                                type="checkbox"
                                class="h-4 w-4"
                                :aria-label="`Sell ${item.name}`"
                            />
                        </td>
                        <td :class="tdClass">{{ item.name }}</td>
                        <td :class="tdClass">
                            {{ item.display_category ?? '—' }}
                        </td>
                        <td :class="tdClass">{{ item.tax_category ?? '—' }}</td>
                        <td :class="tdClass">{{ item.tax_rate ?? '—' }}</td>
                        <td :class="tdClass">{{ item.sac_hsn_code ?? '—' }}</td>
                        <td :class="tdClass">
                            {{ itemTypeLabel(item.item_type) }}
                        </td>
                        <td :class="tdClass">
                            <button
                                type="button"
                                :aria-label="`Edit ${item.name}`"
                                @click="openItem(item)"
                            >
                                ✎
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-3 flex items-center justify-between">
            <div class="flex items-center gap-3 text-sm">
                <button
                    type="button"
                    class="text-[#f53003] disabled:text-[#706f6c]"
                    :disabled="page === 1"
                    @click="page--"
                >
                    &lt;&lt; Prev
                </button>
                <span class="text-[#706f6c]">{{ page }} / {{ pageCount }}</span>
                <button
                    type="button"
                    class="text-[#f53003] disabled:text-[#706f6c]"
                    :disabled="page >= pageCount"
                    @click="page++"
                >
                    Next &gt;&gt;
                </button>
            </div>
            <button
                type="button"
                :class="primaryButton"
                @click="saveItemStatuses"
            >
                Save
            </button>
        </div>
    </section>

    <!-- Price Plans -->
    <section v-else-if="activeTab === 'plans'">
        <div class="mb-3 flex justify-end">
            <button
                type="button"
                :class="primaryButton"
                @click="openPlan(null)"
            >
                + Add
            </button>
        </div>
        <div
            class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="bg-[#FDFDFC] text-xs tracking-wide text-[#706f6c] uppercase dark:bg-[#161615] dark:text-[#A1A09A]"
                >
                    <tr>
                        <th :class="thClass">Name</th>
                        <th :class="thClass">View Items</th>
                        <th :class="thClass">Start Date</th>
                        <th :class="thClass">End Date</th>
                        <th :class="thClass">Status</th>
                        <th :class="thClass">Edit</th>
                        <th :class="thClass">Delete</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="pricePlans.length === 0">
                        <td
                            colspan="7"
                            class="px-4 py-6 text-center text-[#706f6c]"
                        >
                            No price plans yet.
                        </td>
                    </tr>
                    <tr
                        v-for="plan in pricePlans"
                        :key="plan.id"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <td :class="tdClass">{{ plan.name }}</td>
                        <td :class="tdClass">
                            <button
                                type="button"
                                class="text-[#f53003] hover:underline dark:text-[#FF4433]"
                                @click="openPrices(plan)"
                            >
                                View Items
                            </button>
                            <span class="ml-1 text-xs text-[#706f6c]"
                                >({{ pricedCount(plan) }}/{{
                                    items.length
                                }})</span
                            >
                        </td>
                        <td :class="tdClass">
                            {{ calendarDate(plan.start_date) }}
                        </td>
                        <td :class="tdClass">
                            {{ calendarDate(plan.end_date) }}
                        </td>
                        <td :class="tdClass">
                            <input
                                type="checkbox"
                                class="h-4 w-4"
                                :checked="plan.status"
                                :aria-label="`${plan.name} active`"
                                @change="togglePlanStatus(plan)"
                            />
                        </td>
                        <td :class="tdClass">
                            <button
                                type="button"
                                :aria-label="`Edit ${plan.name}`"
                                @click="openPlan(plan)"
                            >
                                ✎
                            </button>
                        </td>
                        <td :class="tdClass">
                            <button
                                type="button"
                                class="hover:text-red-600"
                                :aria-label="`Delete ${plan.name}`"
                                @click="deletePlan(plan)"
                            >
                                🗑
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-[#706f6c] dark:text-[#A1A09A]">
            On a given day an item is charged at its price in the active plan
            covering that day; when plans overlap, the one starting latest wins.
        </p>
    </section>

    <!-- Tax Settings -->
    <section v-else>
        <div class="mb-3 flex justify-end">
            <button
                type="button"
                :class="primaryButton"
                @click="openTaxRate(null)"
            >
                + Add
            </button>
        </div>
        <div
            class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="bg-[#FDFDFC] text-xs tracking-wide text-[#706f6c] uppercase dark:bg-[#161615] dark:text-[#A1A09A]"
                >
                    <tr>
                        <th :class="thClass">Name</th>
                        <th :class="thClass">Rate</th>
                        <th :class="thClass">Items</th>
                        <th :class="thClass">Status</th>
                        <th :class="thClass">Edit</th>
                        <th :class="thClass">Delete</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="taxRates.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-6 text-center text-[#706f6c]"
                        >
                            No tax rates yet. Add one (e.g. GST 5%) to pick it
                            on items.
                        </td>
                    </tr>
                    <tr
                        v-for="taxRate in taxRates"
                        :key="taxRate.id"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <td :class="tdClass">{{ taxRate.name }}</td>
                        <td :class="tdClass">{{ taxRate.rate }}%</td>
                        <td :class="tdClass">{{ taxRate.items_count }}</td>
                        <td :class="tdClass">
                            <input
                                type="checkbox"
                                class="h-4 w-4"
                                :checked="taxRate.status"
                                :aria-label="`${taxRate.name} active`"
                                @change="toggleTaxRateStatus(taxRate)"
                            />
                        </td>
                        <td :class="tdClass">
                            <button
                                type="button"
                                :aria-label="`Edit ${taxRate.name}`"
                                @click="openTaxRate(taxRate)"
                            >
                                ✎
                            </button>
                        </td>
                        <td :class="tdClass">
                            <button
                                type="button"
                                class="hover:text-red-600"
                                :aria-label="`Delete ${taxRate.name}`"
                                @click="deleteTaxRate(taxRate)"
                            >
                                🗑
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-[#706f6c] dark:text-[#A1A09A]">
            The rate is charged on the item's price-plan price. A rate that's
            switched off isn't charged and can't be picked for new items.
        </p>
    </section>

    <Modal
        :show="taxRateModalOpen"
        title="Tax Rate"
        @close="taxRateModalOpen = false"
    >
        <form class="flex flex-col gap-3" @submit.prevent="saveTaxRate">
            <label class="flex flex-col gap-1 text-sm"
                >Name<input
                    v-model="taxRateForm.name"
                    type="text"
                    :class="inputClass"
                    placeholder="GST 5%"
                    required
                /><span
                    v-if="taxRateForm.errors.name"
                    class="text-xs text-red-600"
                    >{{ taxRateForm.errors.name }}</span
                ></label
            >
            <label class="flex flex-col gap-1 text-sm">
                Rate (%)
                <input
                    v-model="taxRateForm.rate"
                    type="number"
                    min="0"
                    max="100"
                    step="0.001"
                    :class="inputClass"
                    required
                />
                <span
                    v-if="taxRateForm.errors.rate"
                    class="text-xs text-red-600"
                    >{{ taxRateForm.errors.rate }}</span
                >
            </label>
            <label class="flex items-center gap-2 text-sm"
                ><input
                    v-model="taxRateForm.status"
                    type="checkbox"
                    class="h-4 w-4"
                />
                Active</label
            >
            <div class="mt-2 flex justify-end gap-2">
                <button
                    type="button"
                    :class="secondaryButton"
                    @click="taxRateModalOpen = false"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :class="primaryButton"
                    :disabled="taxRateForm.processing"
                >
                    Save
                </button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="categoryModalOpen"
        title="Display Category"
        @close="categoryModalOpen = false"
    >
        <form class="flex flex-col gap-3" @submit.prevent="saveCategory">
            <label class="flex flex-col gap-1 text-sm"
                >Name<input
                    v-model="categoryForm.name"
                    type="text"
                    :class="inputClass"
                    required
                /><span
                    v-if="categoryForm.errors.name"
                    class="text-xs text-red-600"
                    >{{ categoryForm.errors.name }}</span
                ></label
            >
            <label class="flex flex-col gap-1 text-sm"
                >Display Order<input
                    v-model="categoryForm.display_order"
                    type="number"
                    min="0"
                    :class="inputClass"
                    placeholder="Last"
            /></label>
            <div class="mt-2 flex justify-end gap-2">
                <button
                    type="button"
                    :class="secondaryButton"
                    @click="categoryModalOpen = false"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :class="primaryButton"
                    :disabled="categoryForm.processing"
                >
                    Save
                </button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="itemModalOpen"
        :title="editingItemId === null ? 'Add Item' : 'Edit Item'"
        @close="itemModalOpen = false"
    >
        <form class="flex flex-col gap-3" @submit.prevent="saveItem">
            <label class="flex flex-col gap-1 text-sm"
                >Name<input
                    v-model="itemForm.name"
                    type="text"
                    :class="inputClass"
                    required
                /><span
                    v-if="itemForm.errors.name"
                    class="text-xs text-red-600"
                    >{{ itemForm.errors.name }}</span
                ></label
            >
            <label class="flex flex-col gap-1 text-sm">
                Display Category
                <select
                    v-model="itemForm.outlet_display_category_id"
                    :class="inputClass"
                >
                    <option :value="null">—</option>
                    <option
                        v-for="category in displayCategories"
                        :key="category.id"
                        :value="category.id"
                    >
                        {{ category.name }}
                    </option>
                </select>
                <span
                    v-if="itemForm.errors.outlet_display_category_id"
                    class="text-xs text-red-600"
                    >{{ itemForm.errors.outlet_display_category_id }}</span
                >
            </label>
            <label class="flex flex-col gap-1 text-sm">
                Tax Category
                <select v-model="itemForm.tax_category_id" :class="inputClass">
                    <option :value="null">—</option>
                    <option
                        v-for="category in taxCategories"
                        :key="category.id"
                        :value="category.id"
                    >
                        {{ category.name }}
                    </option>
                </select>
                <span
                    v-if="taxCategories.length === 0"
                    class="text-xs text-[#706f6c]"
                    >Add tax categories under Settings → Tax settings.</span
                >
            </label>
            <label class="flex flex-col gap-1 text-sm">
                Tax Rate
                <select
                    v-model="itemForm.outlet_tax_rate_id"
                    :class="inputClass"
                >
                    <option :value="null">No tax</option>
                    <option
                        v-for="taxRate in selectableTaxRates"
                        :key="taxRate.id"
                        :value="taxRate.id"
                    >
                        {{ taxRate.name }} ({{ taxRate.rate }}%){{
                            taxRate.status ? '' : ' — off'
                        }}
                    </option>
                </select>
                <span
                    v-if="itemForm.errors.outlet_tax_rate_id"
                    class="text-xs text-red-600"
                    >{{ itemForm.errors.outlet_tax_rate_id }}</span
                >
                <button
                    v-if="taxRates.length === 0"
                    type="button"
                    class="self-start text-xs text-[#f53003] hover:underline"
                    @click="((itemModalOpen = false), (activeTab = 'taxes'))"
                >
                    Add tax rates under Tax Settings
                </button>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                SAC/HSN Code
                <input
                    v-model="itemForm.sac_hsn_code"
                    type="text"
                    inputmode="numeric"
                    maxlength="8"
                    :class="inputClass"
                    placeholder="e.g. 996331"
                />
                <span
                    v-if="itemForm.errors.sac_hsn_code"
                    class="text-xs text-red-600"
                    >{{ itemForm.errors.sac_hsn_code }}</span
                >
            </label>
            <label class="flex flex-col gap-1 text-sm">
                Item Type
                <select v-model="itemForm.item_type" :class="inputClass">
                    <option
                        v-for="type in itemTypes"
                        :key="type.value"
                        :value="type.value"
                    >
                        {{ type.label }}
                    </option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm"
                >Display Order<input
                    v-model="itemForm.display_order"
                    type="number"
                    min="0"
                    :class="inputClass"
                    placeholder="Last"
            /></label>
            <label class="flex items-center gap-2 text-sm"
                ><input
                    v-model="itemForm.status"
                    type="checkbox"
                    class="h-4 w-4"
                />
                Sold at this outlet</label
            >
            <div class="mt-2 flex items-center justify-between gap-2">
                <button
                    v-if="editingItemId !== null"
                    type="button"
                    class="text-sm text-red-600 hover:underline"
                    @click="
                        deleteItem(
                            items.find((item) => item.id === editingItemId)!,
                        )
                    "
                >
                    Delete item
                </button>
                <span v-else />
                <div class="flex gap-2">
                    <button
                        type="button"
                        :class="secondaryButton"
                        @click="itemModalOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :class="primaryButton"
                        :disabled="itemForm.processing"
                    >
                        Save
                    </button>
                </div>
            </div>
        </form>
    </Modal>

    <Modal
        :show="planModalOpen"
        title="Price Plans"
        @close="planModalOpen = false"
    >
        <form class="flex flex-col gap-3" @submit.prevent="savePlan">
            <label class="flex flex-col gap-1 text-sm"
                >Name<input
                    v-model="planForm.name"
                    type="text"
                    :class="inputClass"
                    required
                /><span
                    v-if="planForm.errors.name"
                    class="text-xs text-red-600"
                    >{{ planForm.errors.name }}</span
                ></label
            >
            <div class="flex flex-col gap-1 text-sm">
                Date Range
                <div class="flex gap-2">
                    <input
                        v-model="planForm.start_date"
                        type="date"
                        :class="inputClass"
                        aria-label="Start date"
                        required
                    />
                    <input
                        v-model="planForm.end_date"
                        type="date"
                        :class="inputClass"
                        aria-label="End date"
                        required
                    />
                </div>
                <span
                    v-if="
                        planForm.errors.start_date || planForm.errors.end_date
                    "
                    class="text-xs text-red-600"
                    >{{
                        planForm.errors.start_date ?? planForm.errors.end_date
                    }}</span
                >
            </div>
            <label class="flex items-center gap-2 text-sm"
                ><input
                    v-model="planForm.status"
                    type="checkbox"
                    class="h-4 w-4"
                />
                Status</label
            >
            <div class="mt-2 flex justify-end gap-2">
                <button
                    type="button"
                    :class="secondaryButton"
                    @click="planModalOpen = false"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :class="primaryButton"
                    :disabled="planForm.processing"
                >
                    Save
                </button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="pricesPlan !== null"
        :title="pricesPlan ? `${pricesPlan.name} — Items` : ''"
        @close="pricesPlan = null"
    >
        <form class="flex flex-col gap-3" @submit.prevent="savePrices">
            <p v-if="items.length === 0" class="text-sm text-[#706f6c]">
                This outlet has no items yet. Add them under Catalogue Items.
            </p>
            <div v-else class="max-h-96 overflow-y-auto">
                <table class="w-full text-left text-sm">
                    <thead
                        class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        <tr>
                            <th class="py-1.5">Item</th>
                            <th class="py-1.5">Category</th>
                            <th class="py-1.5 text-right">
                                Price ({{ symbol }})
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(item, index) in items"
                            :key="item.id"
                            class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                        >
                            <td
                                class="py-1.5"
                                :class="
                                    item.status
                                        ? ''
                                        : 'text-[#706f6c] line-through'
                                "
                            >
                                {{ item.name }}
                            </td>
                            <td class="py-1.5 text-xs text-[#706f6c]">
                                {{ item.display_category ?? '—' }}
                            </td>
                            <td class="py-1.5 text-right">
                                <input
                                    v-model="priceInputs[item.id]"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="w-28 rounded-md border border-[#e3e3e0] px-2 py-1 text-right text-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
                                    :aria-label="`Price of ${item.name}`"
                                />
                                <p
                                    v-if="pricesErrors[`prices.${index}.price`]"
                                    class="text-xs text-red-600"
                                >
                                    {{ pricesErrors[`prices.${index}.price`] }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                Prices are before tax. Leave an item blank to leave it out of
                this plan.
            </p>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :class="secondaryButton"
                    @click="pricesPlan = null"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :class="primaryButton"
                    :disabled="pricesSaving || items.length === 0"
                >
                    Save
                </button>
            </div>
        </form>
    </Modal>
</template>
