<script setup lang="ts">
import { computed, reactive, watch } from 'vue';

export type TableColumn = {
    key: string;
    label: string;
};

const props = defineProps<{
    show: boolean;
    columns: TableColumn[];
    modelValue: Record<string, boolean>;
    title?: string;
}>();

const emit = defineEmits<{
    close: [];
    save: [visibility: Record<string, boolean>];
}>();

const visibility = reactive<Record<string, boolean>>({});

watch(
    () => props.show,
    (show) => {
        if (!show) {
            return;
        }

        props.columns.forEach((column) => {
            visibility[column.key] = props.modelValue[column.key] ?? true;
        });
    },
    { immediate: true },
);

const allSelected = computed({
    get: () => props.columns.every((column) => visibility[column.key]),
    set: (value: boolean) => {
        props.columns.forEach((column) => {
            visibility[column.key] = value;
        });
    },
});

function save(): void {
    emit('save', { ...visibility });
}

function close(): void {
    emit('close');
}
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4"
        @click.self="close"
    >
        <div
            class="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <div
                class="flex items-center justify-between bg-[#1b1b18] px-6 py-3"
            >
                <h2 class="font-display text-base font-semibold text-white">
                    {{ title ?? 'Set your default list view' }}
                </h2>
                <button
                    type="button"
                    class="text-white/80 hover:text-white"
                    @click="close"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </div>

            <div class="overflow-y-auto px-6 py-5">
                <label
                    class="mb-3 flex items-center gap-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="allSelected"
                        type="checkbox"
                        class="rounded border-[#e3e3e0] dark:border-[#3E3E3A]"
                    />
                    Select All / Deselect All
                </label>

                <div class="grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-2">
                    <label
                        v-for="column in columns"
                        :key="column.key"
                        class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="visibility[column.key]"
                            type="checkbox"
                            class="rounded border-[#e3e3e0] dark:border-[#3E3E3A]"
                        />
                        {{ column.label }}
                    </label>
                </div>
            </div>

            <div
                class="flex items-center gap-4 border-t border-[#e3e3e0] px-6 py-4 dark:border-[#3E3E3A]"
            >
                <button
                    type="button"
                    class="rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-semibold text-white hover:bg-[#d92a02]"
                    @click="save"
                >
                    Save
                </button>
                <button
                    type="button"
                    class="text-sm font-medium text-[#1b1b18] hover:underline dark:text-[#EDEDEC]"
                    @click="close"
                >
                    Cancel
                </button>
            </div>
        </div>
    </div>
</template>
