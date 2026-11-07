<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import { usePropertyTime } from '@/lib/usePropertyTime';
import { useUrlTab } from '@/lib/useUrlTab';
import type { MetaField, MetaFieldValue } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

defineOptions({ layout: AdminLayout });

const propertyTime = usePropertyTime();

const props = defineProps<{
    property: { id: number; name: string };
    metaFields: MetaField[];
    metaFieldValues: MetaFieldValue[];
}>();

function fieldFor(key: string) {
    return props.metaFields.find((field) => field.key === key);
}

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

function submit(): void {
    form.put(admin.taxSettings.update(props.property).url, {
        preserveScroll: true,
        preserveState: true,
    });
}

/** The property's business date (not the device's): tax rules start on the property's calendar. */
function todayIso(): string {
    return propertyTime.today();
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.split('-');

    return `${day}/${month}/${year}`;
}

// Top-level tabs
const topTabs = [
    'tax-category',
    'tax-components',
    'tax-rule',
    'general-tax-setting',
] as const;
const topTab = useUrlTab(topTabs, 'tax-category', 'tab');

// Tax Category
type TaxCategory = {
    id: number;
    name: string;
    description: string;
    is_default: boolean;
    deleted: boolean;
};
type TaxCategorySettings = { categories: TaxCategory[] };

const taxCategorySettings = ref<TaxCategorySettings>(
    parseOrDefault<TaxCategorySettings>('tax_settings_tax_category', {
        categories: [],
    }),
);
bindSettings('tax_settings_tax_category', taxCategorySettings);

taxCategorySettings.value.categories.forEach((category) => {
    category.deleted ??= false;
    category.is_default ??= false;
});

const activeTaxCategories = computed(() =>
    taxCategorySettings.value.categories.filter(
        (category) => !category.deleted,
    ),
);

const taxCategoryModalOpen = ref(false);
const editingTaxCategoryId = ref<number | null>(null);
const taxCategoryActivityLogsOpen = ref(false);

function emptyTaxCategory(): TaxCategory {
    const nextId =
        taxCategorySettings.value.categories.reduce(
            (max, category) => Math.max(max, category.id),
            0,
        ) + 1;

    return {
        id: nextId,
        name: '',
        description: '',
        is_default: false,
        deleted: false,
    };
}

const taxCategoryForm = ref<TaxCategory>(emptyTaxCategory());

function openAddTaxCategoryModal(): void {
    editingTaxCategoryId.value = null;
    taxCategoryForm.value = emptyTaxCategory();
    taxCategoryModalOpen.value = true;
}

function openEditTaxCategoryModal(category: TaxCategory): void {
    if (category.is_default) {
        return;
    }

    editingTaxCategoryId.value = category.id;
    taxCategoryForm.value = { ...category };
    taxCategoryModalOpen.value = true;
}

function saveTaxCategory(): void {
    if (editingTaxCategoryId.value === null) {
        taxCategorySettings.value.categories.push(taxCategoryForm.value);
    } else {
        const index = taxCategorySettings.value.categories.findIndex(
            (category) => category.id === editingTaxCategoryId.value,
        );

        if (index !== -1) {
            taxCategorySettings.value.categories[index] = {
                ...taxCategorySettings.value.categories[index],
                ...taxCategoryForm.value,
            };
        }
    }

    taxCategoryModalOpen.value = false;
}

function deleteTaxCategory(category: TaxCategory): void {
    if (category.is_default) {
        return;
    }

    category.deleted = true;
}

function goAddTaxCategoryFromRuleForm(): void {
    topTab.value = 'tax-category';
    openAddTaxCategoryModal();
}

// Tax Rule
type TaxValueType = 'percentage' | 'fixed';
type TaxSlab = {
    id: number;
    start_range: number;
    end_range: number;
    tax_value_type: TaxValueType;
    tax: number;
};
type TaxAppliedOn = 'discounted_net_rate' | 'rack_rate';
type TaxCalculatedOn = 'per_person_per_room_night' | 'per_room_per_room_night';

type TaxComponent = {
    id: number;
    component_choice: string;
    applied_on: TaxAppliedOn | '';
    calculated_on: TaxCalculatedOn;
    tax_value_type: TaxValueType;
    tax_value: number;
    define_tax_slab: boolean;
    define_los_slab: boolean;
    slabs: TaxSlab[];
};

type TaxRule = {
    id: number;
    tax_category_id: number | null;
    name: string;
    description: string;
    start_date: string | null;
    end_date: string | null;
    components: TaxComponent[];
    deleted: boolean;
};

type TaxComponentChoice = {
    id: number;
    name: string;
    description: string;
};

type TaxRuleSettings = {
    component_choices: TaxComponentChoice[];
    rules: TaxRule[];
};

function emptyTaxSlab(id: number, endRange: number): TaxSlab {
    return {
        id,
        start_range: 0,
        end_range: endRange,
        tax_value_type: 'percentage',
        tax: 0,
    };
}

// Slabs are allowed to touch at a small currency-precision boundary (e.g. 999.00 -> 999.01)
// without counting as a "break" — anything wider than this is a genuine gap in coverage.
const SLAB_GAP_TOLERANCE = 0.01;

function componentSlabGapError(component: TaxComponent): string | null {
    if (!component.define_tax_slab || component.slabs.length < 2) {
        return null;
    }

    const sorted = [...component.slabs].sort(
        (a, b) => a.start_range - b.start_range,
    );

    for (let i = 1; i < sorted.length; i++) {
        const gap = sorted[i].start_range - sorted[i - 1].end_range;

        if (gap > SLAB_GAP_TOLERANCE + 1e-9) {
            return `There is a break between slabs: ends at ${sorted[i - 1].end_range}, next starts at ${sorted[i].start_range}.`;
        }
    }

    return null;
}

// The last slab always covers up to infinity, so its end range is fixed and not user-editable.
function lockLastSlabEndRange(component: TaxComponent): void {
    const lastSlab = component.slabs[component.slabs.length - 1];

    if (lastSlab) {
        lastSlab.end_range = 999999999;
    }
}

function backfillTaxComponent(component: TaxComponent): void {
    component.component_choice ??= '';
    component.applied_on ??= '';
    component.calculated_on ??= 'per_room_per_room_night';
    component.tax_value_type ??= 'percentage';
    component.tax_value ??= 0;
    component.define_tax_slab ??= true;
    component.define_los_slab ??= false;
    component.slabs ??= [emptyTaxSlab(1, 999999999)];
    lockLastSlabEndRange(component);
}

const taxRuleSettings = ref<TaxRuleSettings>(
    parseOrDefault<TaxRuleSettings>('tax_settings_tax_rule', {
        component_choices: [
            { id: 1, name: 'Default Tax Choice', description: '' },
            { id: 2, name: 'CGST', description: '' },
            { id: 3, name: 'SGST', description: '' },
        ],
        rules: [],
    }),
);
bindSettings('tax_settings_tax_rule', taxRuleSettings);

// Older saved settings may have plain string choices, or choices without an id — backfill to the current shape.
taxRuleSettings.value.component_choices = (
    taxRuleSettings.value.component_choices as (
        | Partial<TaxComponentChoice>
        | string
    )[]
).map((choice, index) =>
    typeof choice === 'string'
        ? { id: index + 1, name: choice, description: '' }
        : {
              id: choice.id ?? index + 1,
              name: choice.name ?? '',
              description: choice.description ?? '',
          },
);

taxRuleSettings.value.rules.forEach((rule) => {
    rule.deleted ??= false;
    rule.components ??= [];
    rule.components.forEach(backfillTaxComponent);
});

const taxAppliedOnOptions: { value: TaxAppliedOn; label: string }[] = [
    { value: 'discounted_net_rate', label: 'Discounted Net Rate' },
    { value: 'rack_rate', label: 'Rack Rate' },
];

const taxCalculatedOnOptions: { value: TaxCalculatedOn; label: string }[] = [
    { value: 'per_person_per_room_night', label: 'Per Person Per Room Night' },
    { value: 'per_room_per_room_night', label: 'Per Room Per Room Night' },
];

const taxRuleCategoryFilter = ref<number | 'all'>('all');

const filteredTaxRules = computed(() =>
    taxRuleSettings.value.rules.filter(
        (rule) =>
            taxRuleCategoryFilter.value === 'all' ||
            rule.tax_category_id === taxRuleCategoryFilter.value,
    ),
);

const taxRuleActivityLogsOpen = ref(false);

const taxRuleFormOpen = ref(false);
const editingTaxRuleId = ref<number | null>(null);

function emptyTaxRule(): TaxRule {
    const nextId =
        taxRuleSettings.value.rules.reduce(
            (max, rule) => Math.max(max, rule.id),
            0,
        ) + 1;

    return {
        id: nextId,
        tax_category_id: activeTaxCategories.value[0]?.id ?? null,
        name: '',
        description: '',
        start_date: todayIso(),
        end_date: '2099-12-31',
        components: [],
        deleted: false,
    };
}

const taxRuleForm = ref<TaxRule>(emptyTaxRule());

function openAddTaxRuleForm(): void {
    editingTaxRuleId.value = null;
    taxRuleForm.value = emptyTaxRule();
    taxRuleFormOpen.value = true;
}

function openEditTaxRuleForm(rule: TaxRule): void {
    editingTaxRuleId.value = rule.id;
    taxRuleForm.value = JSON.parse(JSON.stringify(rule));
    taxRuleFormOpen.value = true;
}

const taxRuleFormSlabError = computed(
    () =>
        taxRuleForm.value.components
            .map(componentSlabGapError)
            .find((error) => error !== null) ?? null,
);

function saveTaxRule(): void {
    if (taxRuleFormSlabError.value) {
        return;
    }

    if (editingTaxRuleId.value === null) {
        taxRuleSettings.value.rules.push(taxRuleForm.value);
    } else {
        const index = taxRuleSettings.value.rules.findIndex(
            (rule) => rule.id === editingTaxRuleId.value,
        );

        if (index !== -1) {
            taxRuleSettings.value.rules[index] = {
                ...taxRuleSettings.value.rules[index],
                ...taxRuleForm.value,
            };
        }
    }

    taxRuleFormOpen.value = false;
}

function deleteTaxRule(rule: TaxRule): void {
    rule.deleted = true;
}

function addTaxComponent(): void {
    const nextId =
        taxRuleForm.value.components.reduce(
            (max, component) => Math.max(max, component.id),
            0,
        ) + 1;

    taxRuleForm.value.components.push({
        id: nextId,
        component_choice: '',
        applied_on: '',
        calculated_on: 'per_room_per_room_night',
        tax_value_type: 'percentage',
        tax_value: 0,
        define_tax_slab: true,
        define_los_slab: false,
        slabs: [emptyTaxSlab(1, 999999999)],
    });
}

function removeTaxComponent(component: TaxComponent): void {
    taxRuleForm.value.components = taxRuleForm.value.components.filter(
        (item) => item.id !== component.id,
    );
}

function addTaxSlab(component: TaxComponent): void {
    const nextId =
        component.slabs.reduce((max, slab) => Math.max(max, slab.id), 0) + 1;
    component.slabs.push(emptyTaxSlab(nextId, 999999999));
    lockLastSlabEndRange(component);
}

function removeTaxSlab(component: TaxComponent, slab: TaxSlab): void {
    if (component.slabs.length <= 1) {
        return;
    }

    component.slabs = component.slabs.filter((item) => item.id !== slab.id);
    lockLastSlabEndRange(component);
}

const componentChoiceModalOpen = ref(false);
const editingComponentChoiceId = ref<number | null>(null);
const componentChoiceForm = ref<TaxComponentChoice>({
    id: 0,
    name: '',
    description: '',
});

function openAddComponentChoiceModal(): void {
    editingComponentChoiceId.value = null;
    componentChoiceForm.value = { id: 0, name: '', description: '' };
    componentChoiceModalOpen.value = true;
}

function openEditComponentChoiceModal(choice: TaxComponentChoice): void {
    editingComponentChoiceId.value = choice.id;
    componentChoiceForm.value = { ...choice };
    componentChoiceModalOpen.value = true;
}

function closeComponentChoiceModal(): void {
    componentChoiceModalOpen.value = false;
}

function saveComponentChoice(): void {
    const name = componentChoiceForm.value.name.trim();

    if (!name) {
        return;
    }

    const duplicate = taxRuleSettings.value.component_choices.some(
        (choice) =>
            choice.name === name &&
            choice.id !== editingComponentChoiceId.value,
    );

    if (duplicate) {
        return;
    }

    if (editingComponentChoiceId.value === null) {
        const nextId =
            taxRuleSettings.value.component_choices.reduce(
                (max, choice) => Math.max(max, choice.id),
                0,
            ) + 1;
        taxRuleSettings.value.component_choices.push({
            id: nextId,
            name,
            description: componentChoiceForm.value.description.trim(),
        });
    } else {
        const index = taxRuleSettings.value.component_choices.findIndex(
            (choice) => choice.id === editingComponentChoiceId.value,
        );

        if (index !== -1) {
            taxRuleSettings.value.component_choices[index] = {
                id: editingComponentChoiceId.value,
                name,
                description: componentChoiceForm.value.description.trim(),
            };
        }
    }

    componentChoiceModalOpen.value = false;
}

function deleteComponentChoice(choice: TaxComponentChoice): void {
    taxRuleSettings.value.component_choices =
        taxRuleSettings.value.component_choices.filter(
            (item) => item.id !== choice.id,
        );
}
</script>

<template>
    <Head :title="`Tax Settings: ${property.name}`" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Tax Settings: {{ property.name }}
        </h1>
        <Link
            :href="admin.taxSettings.index().url"
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
                { key: 'tax-category', label: 'Tax Category' },
                { key: 'tax-components', label: 'Tax Components' },
                { key: 'tax-rule', label: 'Tax Rule' },
                { key: 'general-tax-setting', label: 'General Tax Setting' },
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
    </div>

    <!-- Tax Category -->
    <div v-show="topTab === 'tax-category'" class="flex flex-col gap-4">
        <div class="flex items-center justify-between">
            <h2
                class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Tax Category
            </h2>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="
                        taxCategoryActivityLogsOpen =
                            !taxCategoryActivityLogsOpen
                    "
                >
                    Activity Logs
                </button>
                <button
                    type="button"
                    class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    @click="openAddTaxCategoryModal"
                >
                    + Add
                </button>
            </div>
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
                            Name
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Description
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
                        v-for="category in taxCategorySettings.categories"
                        :key="category.id"
                        :class="
                            category.deleted
                                ? 'text-[#a1a09a] line-through'
                                : ''
                        "
                    >
                        <td class="px-4 py-3 text-sm">
                            {{ category.name }}
                            <span
                                v-if="category.is_default"
                                class="ml-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >(default)</span
                            >
                        </td>
                        <td class="px-4 py-3 text-sm">
                            {{ category.description }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span
                                v-if="category.is_default"
                                title="Default categories can't be edited"
                                class="cursor-not-allowed text-[#a1a09a] dark:text-[#3E3E3A]"
                            >
                                ✎
                            </span>
                            <button
                                v-else
                                type="button"
                                class="text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                                @click="openEditTaxCategoryModal(category)"
                            >
                                ✎
                            </button>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span v-if="category.deleted">Deleted</span>
                            <span
                                v-else-if="category.is_default"
                                title="Default categories can't be deleted"
                                class="cursor-not-allowed text-[#a1a09a] dark:text-[#3E3E3A]"
                            >
                                🗑
                            </span>
                            <button
                                v-else
                                type="button"
                                class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                                @click="deleteTaxCategory(category)"
                            >
                                🗑
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!taxCategorySettings.categories.length">
                        <td
                            colspan="4"
                            class="px-4 py-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            No tax categories found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="taxCategoryActivityLogsOpen"
            class="flex flex-col gap-2 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
        >
            <h3
                class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
            >
                Activity Logs
            </h3>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                No activity yet.
            </p>
        </div>

        <Modal
            :show="taxCategoryModalOpen"
            title="Tax Category"
            @close="taxCategoryModalOpen = false"
        >
            <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Please enter details here
            </p>
            <div class="flex flex-col gap-4">
                <div>
                    <label
                        class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Name</label
                    >
                    <input
                        v-model="taxCategoryForm.name"
                        type="text"
                        class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </div>
                <div>
                    <label
                        class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Description</label
                    >
                    <textarea
                        v-model="taxCategoryForm.description"
                        rows="4"
                        class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </div>
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="saveTaxCategory"
                    >
                        Save
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="taxCategoryModalOpen = false"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </Modal>
    </div>

    <!-- Tax Components -->
    <div v-show="topTab === 'tax-components'" class="flex flex-col gap-4">
        <div class="flex items-center justify-between">
            <h2
                class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Tax Components
            </h2>
            <button
                type="button"
                class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                @click="openAddComponentChoiceModal"
            >
                + Add
            </button>
        </div>
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Rates are set per tax rule: a component is named here and given its
            rate in each rule that applies it.
        </p>

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
                            Name
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            Description
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
                        v-for="choice in taxRuleSettings.component_choices"
                        :key="choice.id"
                    >
                        <td class="px-4 py-3 text-sm">{{ choice.name }}</td>
                        <td
                            class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{ choice.description }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <button
                                type="button"
                                class="text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                                @click="openEditComponentChoiceModal(choice)"
                            >
                                ✎
                            </button>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <button
                                type="button"
                                class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                                @click="deleteComponentChoice(choice)"
                            >
                                🗑
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!taxRuleSettings.component_choices.length">
                        <td
                            colspan="4"
                            class="px-4 py-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            No tax components found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tax Rule -->
    <div v-show="topTab === 'tax-rule'" class="flex flex-col gap-4">
        <template v-if="!taxRuleFormOpen">
            <div class="flex items-center justify-between">
                <h2
                    class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Tax Rule
                </h2>
                <div class="flex flex-wrap items-center gap-2">
                    <label
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Tax Category :
                        <select
                            v-model="taxRuleCategoryFilter"
                            class="block w-40 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="all">All</option>
                            <option
                                v-for="category in activeTaxCategories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                    </label>
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="
                            taxRuleActivityLogsOpen = !taxRuleActivityLogsOpen
                        "
                    >
                        Activity Logs
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="openAddTaxRuleForm"
                    >
                        + Add
                    </button>
                </div>
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
                                Name
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Desc
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Start Date
                            </th>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                End Date
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
                            v-for="rule in filteredTaxRules"
                            :key="rule.id"
                            :class="
                                rule.deleted
                                    ? 'text-[#a1a09a] line-through'
                                    : ''
                            "
                        >
                            <td class="px-4 py-3 text-sm">{{ rule.name }}</td>
                            <td class="px-4 py-3 text-sm">
                                {{ rule.description }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                {{ formatDate(rule.start_date) }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                {{ formatDate(rule.end_date) }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <button
                                    type="button"
                                    class="text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                                    @click="openEditTaxRuleForm(rule)"
                                >
                                    ✎
                                </button>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <span v-if="rule.deleted">Deleted</span>
                                <button
                                    v-else
                                    type="button"
                                    class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                                    @click="deleteTaxRule(rule)"
                                >
                                    🗑
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!filteredTaxRules.length">
                            <td
                                colspan="6"
                                class="px-4 py-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                No tax rules found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="taxRuleActivityLogsOpen"
                class="flex flex-col gap-2 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
            >
                <h3
                    class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Activity Logs
                </h3>
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    No activity yet.
                </p>
            </div>
        </template>

        <div v-else class="flex flex-col gap-6">
            <div class="flex items-center justify-between">
                <h2
                    class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Tax Rule
                </h2>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="taxRuleFormOpen = false"
                >
                    Back
                </button>
            </div>

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
                        class="flex items-center gap-1 text-sm text-[#706f6c] md:justify-end md:text-right dark:text-[#A1A09A]"
                    >
                        Applicable tax category
                        <span
                            title="The tax category this rule applies to."
                            class="cursor-help"
                            >ⓘ</span
                        >
                    </label>
                    <div class="flex flex-wrap items-center gap-3">
                        <select
                            v-model.number="taxRuleForm.tax_category_id"
                            class="block w-64 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option :value="null" disabled>
                                Select a category…
                            </option>
                            <option
                                v-for="category in activeTaxCategories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                        <button
                            type="button"
                            class="text-sm font-medium text-[#f53003] hover:underline dark:text-[#FF4433]"
                            @click="goAddTaxCategoryFromRuleForm"
                        >
                            + Add a new tax category
                        </button>
                    </div>

                    <label
                        class="flex items-center gap-1 text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                    >
                        Name
                        <span
                            title="A name to identify this tax rule."
                            class="cursor-help"
                            >ⓘ</span
                        >
                    </label>
                    <input
                        v-model="taxRuleForm.name"
                        type="text"
                        class="block w-full max-w-md rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />

                    <label
                        class="text-sm text-[#706f6c] md:self-start md:text-right dark:text-[#A1A09A]"
                        >Description</label
                    >
                    <textarea
                        v-model="taxRuleForm.description"
                        rows="3"
                        placeholder="Optional"
                        class="block w-full max-w-md rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />

                    <label
                        class="text-sm text-[#706f6c] md:text-right dark:text-[#A1A09A]"
                        >Applicable date range</label
                    >
                    <div class="flex flex-wrap items-center gap-2">
                        <input
                            v-model="taxRuleForm.start_date"
                            type="date"
                            class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <input
                            v-model="taxRuleForm.end_date"
                            type="date"
                            class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                </div>
            </div>

            <div
                class="flex flex-col gap-4 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]"
            >
                <h3
                    class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Tax Components
                </h3>

                <button
                    type="button"
                    class="self-start text-sm font-medium text-[#f53003] hover:underline dark:text-[#FF4433]"
                    @click="addTaxComponent"
                >
                    + Add a new component
                </button>

                <div
                    v-for="component in taxRuleForm.components"
                    :key="component.id"
                    class="flex flex-col gap-4 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
                >
                    <div class="flex justify-end">
                        <button
                            type="button"
                            class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                            @click="removeTaxComponent(component)"
                        >
                            🗑
                        </button>
                    </div>

                    <div
                        class="grid grid-cols-1 items-center gap-4 md:grid-cols-[160px_1fr]"
                    >
                        <label
                            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >Tax component</label
                        >
                        <div class="flex flex-wrap items-center gap-3">
                            <select
                                v-model="component.component_choice"
                                class="block w-56 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option value="" />
                                <option
                                    v-for="choice in taxRuleSettings.component_choices"
                                    :key="choice.name"
                                    :value="choice.name"
                                >
                                    {{ choice.name }}
                                </option>
                            </select>
                            <button
                                type="button"
                                class="text-sm font-medium text-[#f53003] hover:underline dark:text-[#FF4433]"
                                @click="openAddComponentChoiceModal"
                            >
                                + Add a new component choice
                            </button>
                        </div>

                        <label
                            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >Applied on</label
                        >
                        <div class="flex flex-wrap items-center gap-6">
                            <select
                                v-model="component.applied_on"
                                class="block w-56 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option value="" />
                                <option
                                    v-for="option in taxAppliedOnOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                            <label
                                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >Calculated on</label
                            >
                            <select
                                v-model="component.calculated_on"
                                class="block w-56 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option
                                    v-for="option in taxCalculatedOnOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <template v-if="!component.define_tax_slab">
                            <label
                                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >Tax value type</label
                            >
                            <div class="flex items-center gap-4">
                                <label
                                    class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    <input
                                        v-model="component.tax_value_type"
                                        type="radio"
                                        value="percentage"
                                        class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                                    />
                                    Percentage
                                </label>
                                <label
                                    class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    <input
                                        v-model="component.tax_value_type"
                                        type="radio"
                                        value="fixed"
                                        class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                                    />
                                    Fixed
                                </label>
                            </div>

                            <label
                                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >Tax value</label
                            >
                            <input
                                v-model.number="component.tax_value"
                                type="number"
                                step="0.001"
                                min="0"
                                class="block w-28 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </template>

                        <label
                            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >Slabs</label
                        >
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div class="flex items-center gap-4">
                                <label
                                    class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    <input
                                        v-model="component.define_tax_slab"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                    />
                                    Define Tax Slab
                                </label>
                                <button
                                    v-if="component.define_tax_slab"
                                    type="button"
                                    class="text-sm font-medium text-[#f53003] hover:underline dark:text-[#FF4433]"
                                    @click="addTaxSlab(component)"
                                >
                                    + Add a new slab
                                </button>
                            </div>
                            <!-- LOS (length of stay) slabs: hidden until they're built; define_los_slab stays false. -->
                        </div>
                    </div>

                    <div
                        v-if="component.define_tax_slab"
                        class="flex flex-col gap-3"
                    >
                        <div
                            v-for="(slab, slabIndex) in component.slabs"
                            :key="slab.id"
                            class="flex flex-col gap-3 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                                    >SLAB {{ slabIndex + 1 }}</span
                                >
                                <button
                                    v-if="component.slabs.length > 1"
                                    type="button"
                                    class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                                    @click="removeTaxSlab(component, slab)"
                                >
                                    🗑
                                </button>
                            </div>

                            <div class="flex flex-wrap items-center gap-6">
                                <span
                                    class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                                    >Amount</span
                                >
                                <label
                                    class="flex items-center gap-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Start Range:
                                    <input
                                        v-model.number="slab.start_range"
                                        type="number"
                                        min="0"
                                        class="block w-28 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                                <label
                                    class="flex items-center gap-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    End Range:
                                    <input
                                        v-model.number="slab.end_range"
                                        type="number"
                                        min="0"
                                        :disabled="
                                            slabIndex ===
                                            component.slabs.length - 1
                                        "
                                        class="block w-28 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:bg-[#f5f5f4] disabled:text-[#a1a09a] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC] dark:disabled:bg-[#1D1D1B]"
                                    />
                                </label>
                            </div>

                            <div class="flex flex-wrap items-center gap-6">
                                <span
                                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                    >Tax value type</span
                                >
                                <label
                                    class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    <input
                                        v-model="slab.tax_value_type"
                                        type="radio"
                                        value="percentage"
                                        class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                                    />
                                    Percentage
                                </label>
                                <label
                                    class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    <input
                                        v-model="slab.tax_value_type"
                                        type="radio"
                                        value="fixed"
                                        class="h-4 w-4 text-[#f53003] focus:ring-[#f53003]"
                                    />
                                    Fixed
                                </label>
                                <label
                                    class="flex items-center gap-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Tax:
                                    <input
                                        v-model.number="slab.tax"
                                        type="number"
                                        step="0.001"
                                        min="0"
                                        class="block w-28 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                            </div>
                        </div>
                    </div>

                    <p
                        v-if="componentSlabGapError(component)"
                        class="text-sm font-medium text-[#f53003] dark:text-[#FF4433]"
                    >
                        {{ componentSlabGapError(component) }}
                    </p>
                </div>

                <p
                    v-if="!taxRuleForm.components.length"
                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    No tax components added yet.
                </p>
            </div>

            <p
                v-if="taxRuleFormSlabError"
                class="text-sm font-medium text-[#f53003] dark:text-[#FF4433]"
            >
                Fix the slab breaks above before saving.
            </p>

            <div
                class="flex items-center gap-2 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
            >
                <button
                    type="button"
                    :disabled="!!taxRuleFormSlabError"
                    class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:cursor-not-allowed disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    @click="saveTaxRule"
                >
                    Save
                </button>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="taxRuleFormOpen = false"
                >
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- General Tax Setting -->
    <div v-show="topTab === 'general-tax-setting'" class="flex flex-col gap-4">
        <h2
            class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            General Tax Setting
        </h2>
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            No general tax settings configured yet.
        </p>
    </div>

    <div
        v-if="
            !(topTab === 'tax-category' && taxCategoryModalOpen) &&
            !(topTab === 'tax-rule' && taxRuleFormOpen) &&
            !componentChoiceModalOpen
        "
        class="mt-6 flex items-center gap-3"
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
            :href="admin.taxSettings.index()"
            class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
        >
            Cancel
        </Link>
    </div>

    <Modal
        :show="componentChoiceModalOpen"
        title="Tax Component Choice"
        @close="closeComponentChoiceModal"
    >
        <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Please enter details here
        </p>
        <div class="flex flex-col gap-4">
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Name</label
                >
                <input
                    v-model="componentChoiceForm.name"
                    type="text"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <div>
                <label
                    class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Description</label
                >
                <textarea
                    v-model="componentChoiceForm.description"
                    rows="4"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    @click="saveComponentChoice"
                >
                    Save
                </button>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="closeComponentChoiceModal"
                >
                    Cancel
                </button>
            </div>
        </div>
    </Modal>
</template>
