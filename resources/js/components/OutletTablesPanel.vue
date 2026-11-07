<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import admin from '@/routes/admin';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

export type OutletTableRow = {
    id: number;
    name: string;
    seats: number;
    area: string | null;
    pos_x: number;
    pos_y: number;
    shape: 'square' | 'round' | 'rect';
    status: boolean;
    order_url: string;
};

const props = defineProps<{
    property: { id: number };
    outlet: { id: number; name: string; qr_ordering: boolean };
    tables: OutletTableRow[];
}>();

/** The floor plan's grid: tables snap to its cells. */
const COLUMNS = 12;
const ROWS = 8;

const scope = computed(() => ({
    property: props.property.id,
    outlet: props.outlet.id,
}));

// ── Table list ──
const modalOpen = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({
    name: '',
    seats: 4,
    area: '',
    shape: 'square' as OutletTableRow['shape'],
    status: true,
});

function openTable(table: OutletTableRow | null): void {
    editingId.value = table?.id ?? null;
    form.clearErrors();
    Object.assign(form, {
        name: table?.name ?? '',
        seats: table?.seats ?? 4,
        area: table?.area ?? '',
        shape: table?.shape ?? 'square',
        status: table?.status ?? true,
    });
    modalOpen.value = true;
}

function saveTable(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (modalOpen.value = false),
    };

    if (editingId.value === null) {
        form.post(admin.outlets.tables.store(scope.value).url, options);
    } else {
        form.put(
            admin.outlets.tables.update({
                ...scope.value,
                table: editingId.value,
            }).url,
            options,
        );
    }
}

const deleteTarget = ref<OutletTableRow | null>(null);

function deleteTable(): void {
    if (deleteTarget.value) {
        router.delete(
            admin.outlets.tables.destroy({
                ...scope.value,
                table: deleteTarget.value.id,
            }).url,
            {
                preserveScroll: true,
                onSuccess: () => (deleteTarget.value = null),
            },
        );
    }
}

function renewQr(table: OutletTableRow): void {
    router.post(
        admin.outlets.tables.renewQr({ ...scope.value, table: table.id }).url,
        {},
        { preserveScroll: true },
    );
}

function toggleOrdering(): void {
    router.put(
        admin.outlets.tables.settings(scope.value).url,
        { qr_ordering: !props.outlet.qr_ordering },
        { preserveScroll: true },
    );
}

// ── Floor plan: drag a table onto a cell, then save ──
const positions = ref<Record<number, { x: number; y: number }>>({});
const layoutDirty = ref(false);

watch(
    () => props.tables,
    (tables) => {
        positions.value = Object.fromEntries(
            tables.map((table) => [
                table.id,
                {
                    x: Math.min(table.pos_x, COLUMNS - 1),
                    y: Math.min(table.pos_y, ROWS - 1),
                },
            ]),
        );
        layoutDirty.value = false;
    },
    { immediate: true },
);

const dragging = ref<number | null>(null);

function tableAt(x: number, y: number): OutletTableRow | undefined {
    return props.tables.find(
        (table) =>
            positions.value[table.id]?.x === x &&
            positions.value[table.id]?.y === y,
    );
}

function drop(x: number, y: number): void {
    if (dragging.value === null) {
        return;
    }

    // A table already there swaps into the dragged table's old cell.
    const occupant = tableAt(x, y);
    const from = positions.value[dragging.value];

    if (occupant && occupant.id !== dragging.value) {
        positions.value[occupant.id] = { ...from };
    }

    positions.value[dragging.value] = { x, y };
    dragging.value = null;
    layoutDirty.value = true;
}

function saveLayout(): void {
    router.put(
        admin.outlets.tables.layout(scope.value).url,
        {
            tables: props.tables.map((table) => ({
                id: table.id,
                pos_x: positions.value[table.id].x,
                pos_y: positions.value[table.id].y,
            })),
        },
        { preserveScroll: true },
    );
}

function qrUrl(table: OutletTableRow): string {
    return admin.outlets.tables.qr({ ...scope.value, table: table.id }).url;
}

const shapeClass: Record<OutletTableRow['shape'], string> = {
    square: 'rounded-md',
    round: 'rounded-full',
    rect: 'rounded-md col-span-1',
};

const inputClass =
    'w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const primaryButton =
    'rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]';
const secondaryButton =
    'rounded-md border border-[#e3e3e0] px-4 py-2 text-sm dark:border-[#3E3E3A]';
</script>

<template>
    <div class="flex flex-col gap-6">
        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
        >
            <div>
                <p class="text-sm font-semibold dark:text-[#EDEDEC]">
                    Ordering from the table QR codes
                </p>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Guests scan their table's code, pick from the menu and send
                    the order. Each order waits on the POS until the staff
                    accept it onto the table's bill.
                </p>
            </div>
            <button
                type="button"
                :class="outlet.qr_ordering ? secondaryButton : primaryButton"
                data-test="toggle-qr-ordering"
                @click="toggleOrdering"
            >
                {{ outlet.qr_ordering ? 'Turn off' : 'Turn on' }}
            </button>
        </div>

        <section>
            <div class="mb-3 flex items-center justify-between">
                <h3
                    class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Tables
                </h3>
                <button
                    type="button"
                    :class="primaryButton"
                    data-test="add-table"
                    @click="openTable(null)"
                >
                    + Add table
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
                            <th class="px-4 py-3">Table</th>
                            <th class="px-4 py-3">Seats</th>
                            <th class="px-4 py-3">Area</th>
                            <th class="px-4 py-3">Active</th>
                            <th class="px-4 py-3">QR code</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!tables.length">
                            <td
                                colspan="6"
                                class="px-4 py-6 text-center text-[#706f6c]"
                            >
                                No tables yet.
                            </td>
                        </tr>
                        <tr
                            v-for="table in tables"
                            :key="table.id"
                            class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                        >
                            <td class="px-4 py-2.5 font-medium">
                                {{ table.name }}
                            </td>
                            <td class="px-4 py-2.5">{{ table.seats }}</td>
                            <td class="px-4 py-2.5">{{ table.area ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                {{ table.status ? 'Yes' : 'No' }}
                            </td>
                            <td class="px-4 py-2.5 text-xs">
                                <a
                                    :href="qrUrl(table)"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-[#f53003] hover:underline"
                                    >QR code</a
                                >
                                <a
                                    :href="table.order_url"
                                    target="_blank"
                                    rel="noopener"
                                    class="ml-3 hover:underline"
                                    >Open</a
                                >
                                <button
                                    type="button"
                                    class="ml-3 text-[#706f6c] hover:underline"
                                    @click="renewQr(table)"
                                >
                                    New code
                                </button>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <button
                                    type="button"
                                    :aria-label="`Edit ${table.name}`"
                                    @click="openTable(table)"
                                >
                                    ✎
                                </button>
                                <button
                                    type="button"
                                    class="ml-3 hover:text-red-600"
                                    :aria-label="`Delete ${table.name}`"
                                    @click="deleteTarget = table"
                                >
                                    🗑
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="tables.length">
            <div class="mb-3 flex items-center justify-between">
                <div>
                    <h3
                        class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Floor plan
                    </h3>
                    <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        Drag the tables to where they are in the room; the POS
                        shows this plan.
                    </p>
                </div>
                <button
                    type="button"
                    :class="primaryButton"
                    :disabled="!layoutDirty"
                    data-test="save-layout"
                    @click="saveLayout"
                >
                    Save floor plan
                </button>
            </div>
            <div
                class="grid gap-1 rounded-lg border border-[#e3e3e0] bg-[#fafaf9] p-2 dark:border-[#3E3E3A] dark:bg-[#161615]"
                :style="{
                    gridTemplateColumns: `repeat(${COLUMNS}, minmax(0, 1fr))`,
                }"
            >
                <template v-for="y in ROWS" :key="`row-${y}`">
                    <div
                        v-for="x in COLUMNS"
                        :key="`${x}-${y}`"
                        class="flex aspect-square items-center justify-center rounded border border-dashed border-[#e3e3e0] dark:border-[#3E3E3A]"
                        :data-test="`cell-${x - 1}-${y - 1}`"
                        @dragover.prevent
                        @drop.prevent="drop(x - 1, y - 1)"
                    >
                        <div
                            v-if="tableAt(x - 1, y - 1)"
                            draggable="true"
                            class="flex h-[85%] w-[85%] cursor-grab flex-col items-center justify-center border border-[#1b1b18] bg-white text-xs font-semibold select-none dark:border-[#EDEDEC] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            :class="[
                                shapeClass[tableAt(x - 1, y - 1)!.shape],
                                tableAt(x - 1, y - 1)!.status
                                    ? ''
                                    : 'opacity-40',
                            ]"
                            :data-test="`plan-table-${tableAt(x - 1, y - 1)!.id}`"
                            @dragstart="dragging = tableAt(x - 1, y - 1)!.id"
                        >
                            {{ tableAt(x - 1, y - 1)!.name }}
                            <span class="font-normal text-[#706f6c]">{{
                                tableAt(x - 1, y - 1)!.seats
                            }}</span>
                        </div>
                    </div>
                </template>
            </div>
        </section>

        <Modal
            :show="modalOpen"
            :title="editingId === null ? 'Add table' : 'Edit table'"
            @close="modalOpen = false"
        >
            <form class="flex flex-col gap-3" @submit.prevent="saveTable">
                <label class="flex flex-col gap-1 text-sm"
                    >Name<input
                        v-model="form.name"
                        type="text"
                        maxlength="20"
                        required
                        :class="inputClass"
                        data-test="table-name"
                    /><span
                        v-if="form.errors.name"
                        class="text-xs text-red-600"
                        >{{ form.errors.name }}</span
                    ></label
                >
                <label class="flex flex-col gap-1 text-sm"
                    >Seats<input
                        v-model.number="form.seats"
                        type="number"
                        min="1"
                        max="50"
                        required
                        :class="inputClass"
                /></label>
                <label class="flex flex-col gap-1 text-sm"
                    >Area (optional)<input
                        v-model="form.area"
                        type="text"
                        maxlength="60"
                        placeholder="e.g. Terrace"
                        :class="inputClass"
                /></label>
                <label class="flex flex-col gap-1 text-sm">
                    Shape
                    <select v-model="form.shape" :class="inputClass">
                        <option value="square">Square</option>
                        <option value="round">Round</option>
                        <option value="rect">Long</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 text-sm"
                    ><input
                        v-model="form.status"
                        type="checkbox"
                        class="h-4 w-4"
                    />
                    Active</label
                >
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        :class="secondaryButton"
                        @click="modalOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :class="primaryButton"
                        :disabled="form.processing"
                        data-test="save-table"
                    >
                        Save
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="deleteTarget !== null"
            :title="`Delete table ${deleteTarget?.name ?? ''}`"
            @close="deleteTarget = null"
        >
            <p class="mb-4 text-sm">
                Its QR code stops working. Bills already made for it are kept.
            </p>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :class="secondaryButton"
                    @click="deleteTarget = null"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white"
                    @click="deleteTable"
                >
                    Delete
                </button>
            </div>
        </Modal>
    </div>
</template>
