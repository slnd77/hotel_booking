<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import { useCurrency } from '@/lib/useCurrency';
import admin from '@/routes/admin';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

export type PropertyAddon = {
    id: number;
    name: string;
    description: string | null;
    pricing: string;
    pricing_label: string;
    price: number;
    tax_rate: number;
    sac_hsn_code: string | null;
    show_on_booking_engine: boolean;
    status: boolean;
};

const props = defineProps<{
    propertyId: number;
    addons: PropertyAddon[];
    pricing: { value: string; label: string }[];
}>();

const { money } = useCurrency();
const modalOpen = ref(false);
const editingId = ref<number | null>(null);
const confirmDelete = ref<PropertyAddon | null>(null);

const form = useForm({
    name: '',
    description: '',
    pricing: 'per_booking',
    price: 0,
    tax_rate: 0,
    sac_hsn_code: '',
    show_on_booking_engine: true,
    status: true,
});

function openAdd(): void {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(addon: PropertyAddon): void {
    editingId.value = addon.id;
    form.clearErrors();
    Object.assign(form, {
        name: addon.name,
        description: addon.description ?? '',
        pricing: addon.pricing,
        price: addon.price,
        tax_rate: addon.tax_rate,
        sac_hsn_code: addon.sac_hsn_code ?? '',
        show_on_booking_engine: addon.show_on_booking_engine,
        status: addon.status,
    });
    modalOpen.value = true;
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (modalOpen.value = false),
    };

    if (editingId.value === null) {
        form.post(admin.properties.addons.store(props.propertyId).url, options);
    } else {
        form.put(
            admin.properties.addons.update({
                property: props.propertyId,
                addon: editingId.value,
            }).url,
            options,
        );
    }
}

/** The ticks in the table save straight away. */
function toggle(
    addon: PropertyAddon,
    field: 'status' | 'show_on_booking_engine',
): void {
    router.put(
        admin.properties.addons.update({
            property: props.propertyId,
            addon: addon.id,
        }).url,
        {
            ...addon,
            description: addon.description ?? '',
            sac_hsn_code: addon.sac_hsn_code ?? '',
            [field]: !addon[field],
        },
        { preserveScroll: true },
    );
}

function destroy(): void {
    if (!confirmDelete.value) {
        return;
    }

    router.delete(
        admin.properties.addons.destroy({
            property: props.propertyId,
            addon: confirmDelete.value.id,
        }).url,
        {
            preserveScroll: true,
            onFinish: () => (confirmDelete.value = null),
        },
    );
}

const inputClass =
    'w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
</script>

<template>
    <section class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2
                    class="text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Add-on services
                </h2>
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    Extras sold with a booking: on the booking form, the
                    booking's page and, when ticked, the booking engine. They're
                    charged to the booking's folio.
                </p>
            </div>
            <button
                type="button"
                class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                @click="openAdd"
            >
                + Add add-on
            </button>
        </div>

        <div
            class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <table class="w-full text-left text-sm dark:text-[#EDEDEC]">
                <thead
                    class="bg-[#FDFDFC] text-xs tracking-wide text-[#706f6c] uppercase dark:bg-[#161615] dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Pricing</th>
                        <th class="px-4 py-3 text-right">Price</th>
                        <th class="px-4 py-3 text-right">Tax %</th>
                        <th class="px-4 py-3">Booking engine</th>
                        <th class="px-4 py-3">Active</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!addons.length">
                        <td
                            colspan="7"
                            class="px-4 py-6 text-center text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            No add-ons yet. Add services like airport pickup,
                            breakfast or a spa session.
                        </td>
                    </tr>
                    <tr
                        v-for="addon in addons"
                        :key="addon.id"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <td class="px-4 py-2.5">
                            <div class="font-medium">{{ addon.name }}</div>
                            <div
                                v-if="addon.description"
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ addon.description }}
                            </div>
                        </td>
                        <td class="px-4 py-2.5">{{ addon.pricing_label }}</td>
                        <td class="px-4 py-2.5 text-right">
                            {{ money(addon.price) }}
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            {{ addon.tax_rate }}
                        </td>
                        <td class="px-4 py-2.5">
                            <input
                                type="checkbox"
                                class="h-4 w-4"
                                :checked="addon.show_on_booking_engine"
                                :aria-label="`Show ${addon.name} on the booking engine`"
                                @change="
                                    toggle(addon, 'show_on_booking_engine')
                                "
                            />
                        </td>
                        <td class="px-4 py-2.5">
                            <input
                                type="checkbox"
                                class="h-4 w-4"
                                :checked="addon.status"
                                :aria-label="`${addon.name} active`"
                                @change="toggle(addon, 'status')"
                            />
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <button
                                type="button"
                                class="mr-3 text-[#706f6c] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                                :aria-label="`Edit ${addon.name}`"
                                @click="openEdit(addon)"
                            >
                                ✎
                            </button>
                            <button
                                type="button"
                                class="text-[#706f6c] hover:text-red-600"
                                :aria-label="`Delete ${addon.name}`"
                                @click="confirmDelete = addon"
                            >
                                🗑
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal
            :show="modalOpen"
            :title="editingId === null ? 'Add add-on' : 'Edit add-on'"
            @close="modalOpen = false"
        >
            <form
                class="flex flex-col gap-3 text-sm dark:text-[#EDEDEC]"
                @submit.prevent="save"
            >
                <label class="flex flex-col gap-1">
                    Name
                    <input
                        v-model="form.name"
                        type="text"
                        maxlength="120"
                        required
                        :class="inputClass"
                    />
                    <span
                        v-if="form.errors.name"
                        class="text-xs text-red-600"
                        >{{ form.errors.name }}</span
                    >
                </label>
                <label class="flex flex-col gap-1">
                    Description (shown to guests)
                    <textarea
                        v-model="form.description"
                        rows="2"
                        maxlength="1000"
                        :class="inputClass"
                    ></textarea>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1">
                        Pricing
                        <select v-model="form.pricing" :class="inputClass">
                            <option
                                v-for="option in pricing"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1">
                        Price
                        <input
                            v-model.number="form.price"
                            type="number"
                            min="0"
                            step="0.01"
                            required
                            :class="inputClass"
                        />
                        <span
                            v-if="form.errors.price"
                            class="text-xs text-red-600"
                            >{{ form.errors.price }}</span
                        >
                    </label>
                    <label class="flex flex-col gap-1">
                        Tax rate %
                        <input
                            v-model.number="form.tax_rate"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            required
                            :class="inputClass"
                        />
                        <span
                            v-if="form.errors.tax_rate"
                            class="text-xs text-red-600"
                            >{{ form.errors.tax_rate }}</span
                        >
                    </label>
                    <label class="flex flex-col gap-1">
                        SAC / HSN code
                        <input
                            v-model="form.sac_hsn_code"
                            type="text"
                            maxlength="8"
                            :class="inputClass"
                        />
                        <span
                            v-if="form.errors.sac_hsn_code"
                            class="text-xs text-red-600"
                            >{{ form.errors.sac_hsn_code }}</span
                        >
                    </label>
                </div>
                <label class="flex items-center gap-2"
                    ><input
                        v-model="form.show_on_booking_engine"
                        type="checkbox"
                        class="h-4 w-4"
                    />
                    Offer on the booking engine</label
                >
                <label class="flex items-center gap-2"
                    ><input
                        v-model="form.status"
                        type="checkbox"
                        class="h-4 w-4"
                    />
                    Active</label
                >
                <div class="mt-2 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 dark:border-[#3E3E3A]"
                        @click="modalOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-md bg-[#1b1b18] px-4 py-2 font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                    >
                        Save
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="confirmDelete !== null"
            title="Delete add-on?"
            @close="confirmDelete = null"
        >
            <p class="text-sm dark:text-[#EDEDEC]">
                {{ confirmDelete?.name }} won't be offered any more. Bookings
                that already have it keep it.
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="confirmDelete = null"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white"
                    @click="destroy"
                >
                    Delete
                </button>
            </div>
        </Modal>
    </section>
</template>
