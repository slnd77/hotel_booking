<script setup lang="ts">
import type { GuestRecord } from '@/components/GuestFormModal.vue';
import admin from '@/routes/admin';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    property: { id: number; name: string };
    excludeIds?: number[];
    placeholder?: string;
}>();

const emit = defineEmits<{
    select: [guest: GuestRecord];
    addNew: [];
}>();

const query = ref('');
const open = ref(false);
const loading = ref(false);
const results = ref<GuestRecord[]>([]);
const root = ref<HTMLElement | null>(null);
let requestId = 0;
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

function guestLabel(guest: GuestRecord): string {
    const name = [guest.title, guest.first_name, guest.last_name]
        .filter(Boolean)
        .join(' ');
    const phone = [guest.phone_country_code, guest.phone]
        .filter(Boolean)
        .join(' ');

    return [name, phone].filter(Boolean).join(' — ');
}

async function search(term: string): Promise<void> {
    const currentRequestId = ++requestId;
    loading.value = true;

    try {
        const response = await fetch(
            admin.guests.search(props.property, { query: { q: term } }).url,
            {
                headers: { Accept: 'application/json' },
            },
        );
        const data = (await response.json()) as { guests: GuestRecord[] };

        if (currentRequestId === requestId) {
            results.value = data.guests;
        }
    } finally {
        if (currentRequestId === requestId) {
            loading.value = false;
        }
    }
}

watch(query, (value) => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    const term = value.trim();

    if (term.length === 0) {
        results.value = [];
        loading.value = false;

        return;
    }

    debounceTimer = setTimeout(() => search(term), 300);
});

function visibleResults(): GuestRecord[] {
    const excluded = new Set(props.excludeIds ?? []);

    return results.value.filter((guest) => !excluded.has(guest.id));
}

function selectGuest(guest: GuestRecord): void {
    emit('select', guest);
    query.value = '';
    results.value = [];
    open.value = false;
}

function addNewGuest(): void {
    emit('addNew');
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
        <div class="relative">
            <input
                v-model="query"
                type="text"
                :placeholder="
                    placeholder ??
                    'Search by name, Contact No., Email, Guest ID'
                "
                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 pr-8 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                @focus="open = true"
            />
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="pointer-events-none absolute top-1/2 right-2.5 h-4 w-4 -translate-y-1/2 text-[#706f6c] dark:text-[#A1A09A]"
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
        </div>

        <div
            v-if="open"
            class="absolute left-0 z-50 mt-1 w-full min-w-72 rounded-md border border-[#e3e3e0] bg-white p-2 shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <ul class="max-h-64 overflow-y-auto">
                <li
                    v-if="loading"
                    class="px-2 py-1.5 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Searching…
                </li>
                <template v-else>
                    <li v-for="guest in visibleResults()" :key="guest.id">
                        <button
                            type="button"
                            class="w-full rounded-md px-2 py-1.5 text-left text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                            @click="selectGuest(guest)"
                        >
                            {{ guestLabel(guest) }}
                        </button>
                    </li>
                    <li
                        v-if="query.trim() && !visibleResults().length"
                        class="px-2 py-1.5 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        No matches found.
                    </li>
                </template>
                <li
                    class="mt-1 border-t border-[#e3e3e0] pt-1 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="w-full rounded-md px-2 py-1.5 text-left text-sm font-medium text-[#f53003] hover:bg-[#f53003]/10 dark:text-[#FF4433]"
                        @click="addNewGuest"
                    >
                        + Add New Guest
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
