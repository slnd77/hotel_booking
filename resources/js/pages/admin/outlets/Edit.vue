<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineOptions({ layout: AdminLayout });

type Outlet = {
    id: number;
    name: string;
    type: string;
    printer_setting: string;
    printer_setting_label: string;
    printer_host: string | null;
    printer_port: number | null;
    cash_drawer: boolean;
    menu_url: string;
    kot_printer_setting: string;
    kot_printer_host: string | null;
    kot_printer_port: number | null;
    display_order: number;
    status: boolean;
};

const props = defineProps<{
    property: { id: number; name: string };
    outlets: Outlet[];
    types: string[];
    printerSettings: { value: string; label: string }[];
    activityLogs: { action: string; user: string | null; at: string | null }[];
}>();

const propertyTime = usePropertyTime();
const activityLogsOpen = ref(false);
const modalOpen = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({
    name: '',
    type: 'Others',
    printer_setting: 'a4',
    printer_host: '',
    printer_port: null as number | null,
    cash_drawer: false,
    kot_printer_setting: 'thermal_80',
    kot_printer_host: '',
    kot_printer_port: null as number | null,
    display_order: null as number | null,
    status: true,
});

function openAdd(): void {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(outlet: Outlet): void {
    editingId.value = outlet.id;
    form.clearErrors();
    Object.assign(form, {
        name: outlet.name,
        type: outlet.type,
        printer_setting: outlet.printer_setting,
        printer_host: outlet.printer_host ?? '',
        printer_port: outlet.printer_port,
        cash_drawer: outlet.cash_drawer,
        kot_printer_setting: outlet.kot_printer_setting,
        kot_printer_host: outlet.kot_printer_host ?? '',
        kot_printer_port: outlet.kot_printer_port,
        display_order: outlet.display_order,
        status: outlet.status,
    });
    modalOpen.value = true;
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (modalOpen.value = false),
    };

    if (editingId.value === null) {
        form.post(admin.outlets.store(props.property.id).url, options);
    } else {
        form.put(
            admin.outlets.update({
                property: props.property.id,
                outlet: editingId.value,
            }).url,
            options,
        );
    }
}

/** The Status tick saves straight away. */
function toggleStatus(outlet: Outlet): void {
    router.put(
        admin.outlets.update({ property: props.property.id, outlet: outlet.id })
            .url,
        { ...outlet, status: !outlet.status },
        { preserveScroll: true },
    );
}

function destroy(outlet: Outlet): void {
    if (
        !confirm(
            `Delete outlet ${outlet.name} with its categories, items and price plans?`,
        )
    ) {
        return;
    }

    router.delete(
        admin.outlets.destroy({
            property: props.property.id,
            outlet: outlet.id,
        }).url,
        { preserveScroll: true },
    );
}

// Menu QR code: one for the outlet, or one per table.
const qrOutlet = ref<Outlet | null>(null);
const qrTable = ref('');

const qrImageUrl = computed(() =>
    qrOutlet.value
        ? admin.outlets.qr(
              { property: props.property.id, outlet: qrOutlet.value.id },
              {
                  // The token's tail busts the cached image once the QR code is renewed.
                  query: {
                      v: qrOutlet.value.menu_url.slice(-8),
                      ...(qrTable.value.trim()
                          ? { table: qrTable.value.trim() }
                          : {}),
                  },
              },
          ).url
        : '',
);
const qrMenuUrl = computed(() =>
    qrOutlet.value
        ? qrOutlet.value.menu_url +
          (qrTable.value.trim()
              ? `?table=${encodeURIComponent(qrTable.value.trim())}`
              : '')
        : '',
);

function openQr(outlet: Outlet): void {
    qrTable.value = '';
    qrOutlet.value = outlet;
}

function renewQr(): void {
    if (
        !qrOutlet.value ||
        !confirm(
            `Make a new menu QR code for ${qrOutlet.value.name}? QR codes printed before stop working.`,
        )
    ) {
        return;
    }

    const outletId = qrOutlet.value.id;
    router.post(
        admin.outlets.renewQr({ property: props.property.id, outlet: outletId })
            .url,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                (qrOutlet.value =
                    props.outlets.find((outlet) => outlet.id === outletId) ??
                    null),
        },
    );
}

const inputClass =
    'w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
</script>

<template>
    <Head :title="`Outlets: ${property.name}`" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Outlets
        </h1>
        <div class="flex items-center gap-3">
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="activityLogsOpen = !activityLogsOpen"
            >
                Activity Logs
            </button>
            <button
                type="button"
                class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                @click="openAdd"
            >
                + Add
            </button>
        </div>
    </div>

    <div
        v-if="activityLogsOpen"
        class="mb-6 flex flex-col gap-2 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
    >
        <h3
            class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
        >
            Activity Logs
        </h3>
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
        class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <table class="w-full text-left text-sm">
            <thead
                class="bg-[#FDFDFC] text-xs tracking-wide text-[#706f6c] uppercase dark:bg-[#161615] dark:text-[#A1A09A]"
            >
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Printer Setting</th>
                    <th class="px-4 py-3">Display Order</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Menu QR</th>
                    <th class="px-4 py-3">Edit</th>
                    <th class="px-4 py-3">Delete</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="outlets.length === 0">
                    <td
                        colspan="8"
                        class="px-4 py-6 text-center text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        No outlets yet. Add one to set up its items and price
                        plans.
                    </td>
                </tr>
                <tr
                    v-for="outlet in outlets"
                    :key="outlet.id"
                    class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                >
                    <td class="px-4 py-2.5">
                        <Link
                            :href="
                                admin.outlets.show({
                                    property: property.id,
                                    outlet: outlet.id,
                                }).url
                            "
                            class="font-medium text-[#f53003] hover:underline dark:text-[#FF4433]"
                            >{{ outlet.name }}</Link
                        >
                    </td>
                    <td class="px-4 py-2.5">{{ outlet.type }}</td>
                    <td class="px-4 py-2.5">
                        {{ outlet.printer_setting_label }}
                    </td>
                    <td class="px-4 py-2.5">{{ outlet.display_order }}</td>
                    <td class="px-4 py-2.5">
                        <input
                            type="checkbox"
                            class="h-4 w-4"
                            :checked="outlet.status"
                            :aria-label="`${outlet.name} active`"
                            @change="toggleStatus(outlet)"
                        />
                    </td>
                    <td class="px-4 py-2.5">
                        <button
                            type="button"
                            class="text-xs font-medium text-[#f53003] hover:underline dark:text-[#FF4433]"
                            @click="openQr(outlet)"
                        >
                            QR code
                        </button>
                    </td>
                    <td class="px-4 py-2.5">
                        <button
                            type="button"
                            class="text-[#706f6c] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                            :aria-label="`Edit ${outlet.name}`"
                            @click="openEdit(outlet)"
                        >
                            ✎
                        </button>
                    </td>
                    <td class="px-4 py-2.5">
                        <button
                            type="button"
                            class="text-[#706f6c] hover:text-red-600"
                            :aria-label="`Delete ${outlet.name}`"
                            @click="destroy(outlet)"
                        >
                            🗑
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <Modal :show="modalOpen" title="Outlet" @close="modalOpen = false">
        <form class="flex flex-col gap-3" @submit.prevent="save">
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Please enter details here
            </p>
            <label class="flex flex-col gap-1 text-sm">
                Name
                <input
                    v-model="form.name"
                    type="text"
                    :class="inputClass"
                    required
                />
                <span v-if="form.errors.name" class="text-xs text-red-600">{{
                    form.errors.name
                }}</span>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                Type
                <select v-model="form.type" :class="inputClass">
                    <option v-for="type in types" :key="type" :value="type">
                        {{ type }}
                    </option>
                </select>
                <span v-if="form.errors.type" class="text-xs text-red-600">{{
                    form.errors.type
                }}</span>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                Printer Setting
                <select v-model="form.printer_setting" :class="inputClass">
                    <option
                        v-for="option in printerSettings"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <fieldset
                class="flex flex-col gap-2 rounded-md border border-[#e3e3e0] p-3 dark:border-[#3E3E3A]"
            >
                <legend class="px-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Network printers (ESC/POS, optional)
                </legend>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Without one, bills and kitchen tickets print from the
                    browser. The server must be able to reach the printer.
                </p>
                <div class="grid grid-cols-3 gap-2">
                    <label class="col-span-2 flex flex-col gap-1 text-sm">
                        Bill printer IP / host
                        <input
                            v-model="form.printer_host"
                            type="text"
                            :class="inputClass"
                            placeholder="192.168.1.50"
                        />
                        <span
                            v-if="form.errors.printer_host"
                            class="text-xs text-red-600"
                            >{{ form.errors.printer_host }}</span
                        >
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        Port
                        <input
                            v-model="form.printer_port"
                            type="number"
                            min="9100"
                            max="9109"
                            :class="inputClass"
                            placeholder="9100"
                        />
                        <span
                            v-if="form.errors.printer_port"
                            class="text-xs text-red-600"
                            >{{ form.errors.printer_port }}</span
                        >
                    </label>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.cash_drawer"
                        type="checkbox"
                        class="h-4 w-4"
                    />
                    Cash drawer on the bill printer (opens for cash payments)
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    Kitchen ticket (KOT) paper
                    <select
                        v-model="form.kot_printer_setting"
                        :class="inputClass"
                    >
                        <option
                            v-for="option in printerSettings"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <div class="grid grid-cols-3 gap-2">
                    <label class="col-span-2 flex flex-col gap-1 text-sm">
                        Kitchen printer IP / host
                        <input
                            v-model="form.kot_printer_host"
                            type="text"
                            :class="inputClass"
                            placeholder="192.168.1.51"
                        />
                        <span
                            v-if="form.errors.kot_printer_host"
                            class="text-xs text-red-600"
                            >{{ form.errors.kot_printer_host }}</span
                        >
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        Port
                        <input
                            v-model="form.kot_printer_port"
                            type="number"
                            min="9100"
                            max="9109"
                            :class="inputClass"
                            placeholder="9100"
                        />
                        <span
                            v-if="form.errors.kot_printer_port"
                            class="text-xs text-red-600"
                            >{{ form.errors.kot_printer_port }}</span
                        >
                    </label>
                </div>
            </fieldset>
            <label class="flex flex-col gap-1 text-sm">
                Display Order
                <input
                    v-model="form.display_order"
                    type="number"
                    min="0"
                    :class="inputClass"
                    placeholder="Last"
                />
            </label>
            <label class="flex items-center gap-2 text-sm"
                ><input v-model="form.status" type="checkbox" class="h-4 w-4" />
                Active</label
            >
            <div class="mt-2 flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm dark:border-[#3E3E3A]"
                    @click="modalOpen = false"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                >
                    Save
                </button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="qrOutlet !== null"
        :title="`${qrOutlet?.name ?? ''} menu QR code`"
        @close="qrOutlet = null"
    >
        <div v-if="qrOutlet" class="flex flex-col items-center gap-3 text-sm">
            <p class="text-center text-[#706f6c] dark:text-[#A1A09A]">
                Guests scan it to see the menu with today's prices. Add a table
                to print one QR code per table.
            </p>
            <label class="flex w-full flex-col gap-1">
                Table (optional)
                <input
                    v-model="qrTable"
                    type="text"
                    maxlength="20"
                    :class="inputClass"
                    placeholder="e.g. T4"
                />
            </label>
            <img
                :src="qrImageUrl"
                :alt="`QR code of ${qrOutlet.name}'s menu`"
                class="h-64 w-64 rounded-md bg-white p-2"
            />
            <a
                :href="qrMenuUrl"
                target="_blank"
                rel="noopener"
                class="text-xs break-all text-[#f53003] hover:underline dark:text-[#FF4433]"
                >{{ qrMenuUrl }}</a
            >
            <div class="flex w-full justify-between gap-2">
                <button
                    type="button"
                    class="text-xs text-red-700 hover:underline dark:text-red-400"
                    @click="renewQr"
                >
                    New QR code
                </button>
                <a
                    :href="qrImageUrl"
                    :download="`${qrOutlet.name} menu${qrTable ? ` table ${qrTable}` : ''}.svg`"
                    class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                    >Download SVG</a
                >
            </div>
        </div>
    </Modal>
</template>
