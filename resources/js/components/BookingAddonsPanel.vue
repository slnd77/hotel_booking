<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import { useCan } from '@/lib/useCan';
import { useCurrency } from '@/lib/useCurrency';
import admin from '@/routes/admin';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

export type BookingAddonLine = {
    id: number;
    addon_id: number | null;
    name: string;
    pricing_label: string;
    unit_price: number;
    quantity: number;
    multiplier: number;
    amount: number;
    tax_amount: number;
    total: number;
    added_from: string;
};

export type AddonOption = {
    id: number;
    name: string;
    description: string | null;
    pricing: string;
    pricing_label: string;
    price: number;
    tax_rate: number;
};

const props = defineProps<{
    propertyId: number;
    bookingId: number;
    lines: BookingAddonLine[];
    catalogue: AddonOption[];
    /** False for cancelled, no-show and checked-out bookings. */
    editable: boolean;
}>();

const can = useCan();
const { money } = useCurrency();
const removing = ref<BookingAddonLine | null>(null);
const form = useForm({ addon_id: null as number | null, quantity: 1 });

function add(): void {
    form.post(
        admin.bookings.addons.store({
            property: props.propertyId,
            booking: props.bookingId,
        }).url,
        {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        },
    );
}

function remove(): void {
    if (!removing.value) {
        return;
    }

    router.delete(
        admin.bookings.addons.destroy({
            property: props.propertyId,
            booking: props.bookingId,
            bookingAddon: removing.value.id,
        }).url,
        {
            preserveScroll: true,
            onFinish: () => (removing.value = null),
        },
    );
}

const sources: Record<string, string> = {
    booking_engine: 'Booking engine',
    api: 'API',
    front_desk: 'Front desk',
    early_check_in: 'Early check-in',
    late_check_out: 'Late check-out',
};
</script>

<template>
    <div class="p-4">
        <h2 class="mb-2 text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]">
            Addons
        </h2>

        <p v-if="!lines.length" class="text-xs text-gray-500 italic">
            No add-ons on this booking.
        </p>
        <table
            v-else
            class="mb-3 w-full text-xs text-gray-800 dark:text-gray-200"
        >
            <thead class="text-gray-500">
                <tr>
                    <th class="py-1 text-left font-medium">Service</th>
                    <th class="py-1 text-right font-medium">Qty</th>
                    <th class="py-1 text-right font-medium">Amount</th>
                    <th class="py-1 text-right font-medium">Tax</th>
                    <th class="py-1 text-right font-medium">Total</th>
                    <th></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-[#20201e]">
                <tr v-for="line in lines" :key="line.id">
                    <td class="py-1.5">
                        {{ line.name }}
                        <span class="block text-[11px] text-gray-500"
                            >{{ money(line.unit_price) }}
                            {{ line.pricing_label.toLowerCase()
                            }}<template v-if="line.multiplier > 1">
                                × {{ line.multiplier }}</template
                            >
                            ·
                            {{
                                sources[line.added_from] ?? line.added_from
                            }}</span
                        >
                    </td>
                    <td class="py-1.5 text-right">{{ line.quantity }}</td>
                    <td class="py-1.5 text-right">{{ money(line.amount) }}</td>
                    <td class="py-1.5 text-right">
                        {{ money(line.tax_amount) }}
                    </td>
                    <td class="py-1.5 text-right font-medium">
                        {{ money(line.total) }}
                    </td>
                    <td class="py-1.5 pl-2 text-right">
                        <button
                            v-if="editable && can('reservation.delete_addon')"
                            type="button"
                            class="text-gray-400 hover:text-red-600"
                            :aria-label="`Remove ${line.name}`"
                            @click="removing = line"
                        >
                            ✕
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <form
            v-if="editable && catalogue.length"
            class="flex flex-wrap items-end gap-2"
            @submit.prevent="add"
        >
            <label
                class="flex min-w-0 flex-1 flex-col gap-1 text-xs text-gray-500"
            >
                Add a service
                <select
                    v-model="form.addon_id"
                    required
                    class="rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-800 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
                >
                    <option :value="null" disabled>Choose an add-on</option>
                    <option
                        v-for="addon in catalogue"
                        :key="addon.id"
                        :value="addon.id"
                    >
                        {{ addon.name }} · {{ money(addon.price) }}
                        {{ addon.pricing_label.toLowerCase() }}
                    </option>
                </select>
            </label>
            <label class="flex w-16 flex-col gap-1 text-xs text-gray-500">
                Qty
                <input
                    v-model.number="form.quantity"
                    type="number"
                    min="1"
                    max="999"
                    required
                    class="rounded border border-gray-300 bg-white px-2 py-1 text-xs dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
                />
            </label>
            <button
                type="submit"
                :disabled="form.processing || form.addon_id === null"
                class="rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-bold text-gray-800 disabled:opacity-40 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
            >
                ADD
            </button>
            <span
                v-if="form.errors.addon_id || form.errors.quantity"
                class="w-full text-xs text-red-600"
                >{{ form.errors.addon_id ?? form.errors.quantity }}</span
            >
        </form>
        <p v-else-if="editable" class="text-xs text-gray-500">
            Set up add-on services under Property → Add-ons.
        </p>

        <Modal
            :show="removing !== null"
            title="Remove add-on?"
            @close="removing = null"
        >
            <p class="text-sm dark:text-[#EDEDEC]">
                {{ removing?.name }} comes off the booking, and its charge is
                reversed on the folio.
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="removing = null"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white"
                    @click="remove"
                >
                    Remove
                </button>
            </div>
        </Modal>
    </div>
</template>
