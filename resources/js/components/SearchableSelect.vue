<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    modelValue: number | '';
    options: { id: number; label: string }[];
    placeholder?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: number | ''): void;
}>();

const open = ref(false);
const query = ref('');
const root = ref<HTMLElement | null>(null);

const selectedOption = computed(
    () =>
        props.options.find((option) => option.id === props.modelValue) ?? null,
);

const filteredOptions = computed(() => {
    const search = query.value.trim().toLowerCase();

    if (!search) {
        return props.options;
    }

    return props.options.filter((option) =>
        option.label.toLowerCase().includes(search),
    );
});

watch(open, (value) => {
    if (value) {
        query.value = '';
    }
});

function select(id: number): void {
    emit('update:modelValue', id);
    open.value = false;
}

function clear(): void {
    emit('update:modelValue', '');
    open.value = false;
}

function onClickOutside(event: MouseEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', onClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="flex w-full items-center justify-between gap-2 rounded-md bg-transparent py-1 text-left text-sm font-semibold text-[#1b1b18] focus:ring-2 focus:ring-[#f53003] focus:outline-none dark:text-[#EDEDEC]"
            @click="open = !open"
        >
            <span
                :class="{
                    'text-[#706f6c] dark:text-[#A1A09A]': !selectedOption,
                }"
                class="truncate"
            >
                {{
                    selectedOption
                        ? selectedOption.label
                        : (placeholder ?? 'Select…')
                }}
            </span>
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 shrink-0"
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
            class="absolute left-0 z-50 mt-2 w-72 rounded-md border border-[#e3e3e0] bg-white p-2 shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <input
                v-model="query"
                type="text"
                autofocus
                placeholder="Search…"
                class="mb-2 w-full rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            />

            <ul class="max-h-64 overflow-y-auto">
                <li v-if="selectedOption">
                    <button
                        type="button"
                        class="w-full rounded-md px-2 py-1.5 text-left text-sm text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]"
                        @click="clear"
                    >
                        Clear selection
                    </button>
                </li>
                <li v-for="option in filteredOptions" :key="option.id">
                    <button
                        type="button"
                        class="w-full rounded-md px-2 py-1.5 text-left text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        :class="{
                            'bg-[#f5f5f4] font-semibold dark:bg-[#1D1D1B]':
                                option.id === modelValue,
                        }"
                        @click="select(option.id)"
                    >
                        {{ option.label }}
                    </button>
                </li>
                <li
                    v-if="!filteredOptions.length"
                    class="px-2 py-1.5 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    No matches found.
                </li>
            </ul>
        </div>
    </div>
</template>
