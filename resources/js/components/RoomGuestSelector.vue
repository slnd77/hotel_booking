<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

export type RoomRequirement = {
    room_category_id: number | null;
    adults: number;
    children: number;
    infants: number;
};

const props = withDefaults(
    defineProps<{
        modelValue: RoomRequirement[];
        roomCategoryOptions?: { id: number; name: string }[];
    }>(),
    {
        roomCategoryOptions: () => [],
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: RoomRequirement[]];
    apply: [rooms: RoomRequirement[]];
}>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);

const draftRooms = ref<RoomRequirement[]>(
    props.modelValue.map((r) => ({ ...r })),
);

watch(
    () => props.modelValue,
    (newVal) => {
        draftRooms.value = newVal.map((r) => ({ ...r }));
    },
    { deep: true },
);

function emptyRoom(): RoomRequirement {
    return { room_category_id: null, adults: 1, children: 0, infants: 0 };
}

function addRoom(): void {
    draftRooms.value.push(emptyRoom());
}

function removeRoom(index: number): void {
    if (draftRooms.value.length <= 1) {
        return;
    }

    draftRooms.value = draftRooms.value.filter((_, i) => i !== index);
}

function updateRoom(index: number, patch: Partial<RoomRequirement>): void {
    draftRooms.value = draftRooms.value.map((room, i) =>
        i === index ? { ...room, ...patch } : room,
    );
}

function step(
    index: number,
    key: 'adults' | 'children' | 'infants',
    delta: number,
): void {
    const room = draftRooms.value[index];
    const min = key === 'adults' ? 1 : 0;
    const next = Math.max(min, Math.min(10, room[key] + delta));

    updateRoom(index, { [key]: next });
}

const totalAdults = computed(() =>
    props.modelValue.reduce((sum, room) => sum + room.adults, 0),
);
const totalChildren = computed(() =>
    props.modelValue.reduce((sum, room) => sum + room.children, 0),
);
const totalInfants = computed(() =>
    props.modelValue.reduce((sum, room) => sum + room.infants, 0),
);

const summary = computed(
    () =>
        `${props.modelValue.length} Room${props.modelValue.length === 1 ? '' : 's'}, ${totalAdults.value} Adult${totalAdults.value === 1 ? '' : 's'}, ${totalChildren.value} Children, ${totalInfants.value} Infant${totalInfants.value === 1 ? '' : 's'}`,
);

function onClickOutside(event: MouseEvent): void {
    if (!open.value) {
        return;
    }

    if (
        event.composedPath &&
        root.value &&
        event.composedPath().includes(root.value)
    ) {
        return;
    }

    const target = event.target as Node | null;
    if (
        root.value &&
        target &&
        target.isConnected &&
        !root.value.contains(target)
    ) {
        open.value = false;
        draftRooms.value = props.modelValue.map((r) => ({ ...r }));
    }
}

function toggleOpen(): void {
    if (open.value) {
        draftRooms.value = props.modelValue.map((r) => ({ ...r }));
        open.value = false;
    } else {
        draftRooms.value = props.modelValue.map((r) => ({ ...r }));
        open.value = true;
    }
}

function onApply(): void {
    open.value = false;
    const appliedRooms = draftRooms.value.map((r) => ({ ...r }));
    emit('update:modelValue', appliedRooms);
    emit('apply', appliedRooms);
}

onMounted(() => document.addEventListener('click', onClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
    <div ref="root" class="relative flex flex-col gap-1 text-sm text-[#706f6c]">
        Room/Guest
        <button
            type="button"
            class="flex w-64 items-center justify-between gap-2 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-left text-sm text-[#1b1b18]"
            @click="toggleOpen"
        >
            <span class="truncate">{{ summary }}</span>
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 shrink-0 text-[#706f6c]"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M19 9l-7 7-7-7"
                />
            </svg>
        </button>

        <div
            v-if="open"
            class="absolute top-full right-0 z-50 mt-2 w-[min(90vw,420px)] rounded-md border border-[#e3e3e0] bg-white p-4 shadow-lg"
            @click.stop
        >
            <div class="flex max-h-[60vh] flex-col gap-4 overflow-y-auto">
                <div
                    v-for="(room, index) in draftRooms"
                    :key="index"
                    class="rounded-md border border-[#e3e3e0] p-3"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-sm font-semibold text-[#1b1b18]">
                            Room {{ index + 1 }}
                        </p>
                        <button
                            v-if="draftRooms.length > 1"
                            type="button"
                            class="text-xs font-medium text-[#f53003] hover:underline"
                            @click.stop="removeRoom(index)"
                        >
                            Remove
                        </button>
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <div
                            v-for="key in [
                                'adults',
                                'children',
                                'infants',
                            ] as const"
                            :key="key"
                            class="flex flex-col items-center gap-1"
                        >
                            <span class="text-xs text-[#706f6c] capitalize">{{
                                key
                            }}</span>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="flex h-6 w-6 items-center justify-center rounded-md border border-[#e3e3e0] text-[#1b1b18] hover:bg-[#f5f5f4]"
                                    @click="step(index, key, -1)"
                                >
                                    −
                                </button>
                                <span
                                    class="w-4 text-center text-sm text-[#1b1b18]"
                                    >{{ room[key] }}</span
                                >
                                <button
                                    type="button"
                                    class="flex h-6 w-6 items-center justify-center rounded-md border border-[#e3e3e0] text-[#1b1b18] hover:bg-[#f5f5f4]"
                                    @click="step(index, key, 1)"
                                >
                                    +
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button
                type="button"
                class="mt-3 text-sm font-medium text-[#f53003] hover:underline"
                @click="addRoom"
            >
                + Add Room
            </button>

            <button
                type="button"
                class="mt-4 w-full rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-semibold text-white hover:bg-black"
                @click="onApply"
            >
                Apply
            </button>
        </div>
    </div>
</template>
