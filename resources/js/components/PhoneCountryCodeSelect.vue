<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

export type PhoneCountryOption = {
    id: number;
    name: string;
    iso2: string;
    phonecode: string;
};

const props = defineProps<{
    modelValue: string;
    countries: PhoneCountryOption[];
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const open = ref(false);
const query = ref('');
const root = ref<HTMLElement | null>(null);

function flagClass(iso2: string): string {
    return iso2 ? `fi fi-${iso2.toLowerCase()}` : '';
}

function dialCode(country: PhoneCountryOption): string {
    return `+${country.phonecode}`;
}

const selectedCountry = computed(
    () =>
        props.countries.find(
            (country) => dialCode(country) === props.modelValue,
        ) ?? null,
);

const filteredCountries = computed(() => {
    const search = query.value.trim().toLowerCase();

    if (!search) {
        return props.countries;
    }

    return props.countries.filter(
        (country) =>
            country.name.toLowerCase().includes(search) ||
            country.phonecode.includes(search),
    );
});

watch(open, (value) => {
    if (value) {
        query.value = '';
    }
});

function select(country: PhoneCountryOption): void {
    emit('update:modelValue', dialCode(country));
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
            class="flex w-24 items-center gap-1.5 rounded-md border border-[#e3e3e0] bg-white px-2 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            @click="open = !open"
        >
            <span
                v-if="selectedCountry"
                :class="flagClass(selectedCountry.iso2)"
                class="shrink-0 rounded-[2px]"
            ></span>
            <span class="truncate">{{ modelValue || '+--' }}</span>
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="ml-auto h-3.5 w-3.5 shrink-0"
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
            class="absolute left-0 z-50 mt-1 w-64 rounded-md border border-[#e3e3e0] bg-white p-2 shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <input
                v-model="query"
                type="text"
                autofocus
                placeholder="Search country or code…"
                class="mb-2 w-full rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            />

            <ul class="max-h-64 overflow-y-auto">
                <li v-for="country in filteredCountries" :key="country.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        :class="{
                            'bg-[#f5f5f4] font-semibold dark:bg-[#1D1D1B]':
                                dialCode(country) === modelValue,
                        }"
                        @click="select(country)"
                    >
                        <span
                            :class="flagClass(country.iso2)"
                            class="shrink-0 rounded-[2px]"
                        ></span>
                        <span class="flex-1 truncate">{{ country.name }}</span>
                        <span class="text-[#706f6c] dark:text-[#A1A09A]">{{
                            dialCode(country)
                        }}</span>
                    </button>
                </li>
                <li
                    v-if="!filteredCountries.length"
                    class="px-2 py-1.5 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    No matches found.
                </li>
            </ul>
        </div>
    </div>
</template>
