<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import admin from '@/routes/admin';
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

type Candidate = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    bookings: number;
};

const props = defineProps<{
    /** The guest that is kept; the picked duplicate merges into it. */
    guest: {
        id: number;
        first_name: string;
        last_name: string | null;
        mobile: string;
        email: string | null;
    } | null;
}>();

const emit = defineEmits<{ close: [] }>();

const candidates = ref<Candidate[]>([]);
const term = ref('');
const loading = ref(false);
const duplicate = ref<Candidate | null>(null);
const error = ref('');
const processing = ref(false);

async function load(): Promise<void> {
    if (!props.guest) {
        return;
    }

    loading.value = true;

    try {
        const response = await fetch(
            admin.databases.guests.merge.candidates(
                { guest: props.guest.id },
                { query: { q: term.value || undefined } },
            ).url,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );
        candidates.value = response.ok
            ? ((await response.json()).guests as Candidate[])
            : [];
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.guest?.id,
    (id) => {
        term.value = '';
        duplicate.value = null;
        error.value = '';
        candidates.value = [];

        if (id) {
            void load();
        }
    },
    { immediate: true },
);

function merge(): void {
    if (!props.guest || !duplicate.value) {
        return;
    }

    processing.value = true;
    router.post(
        admin.databases.guests.merge.store({ guest: props.guest.id }).url,
        { duplicate_id: duplicate.value.id },
        {
            preserveScroll: true,
            onSuccess: () => emit('close'),
            onError: (errors) =>
                (error.value = Object.values(errors).join(' ')),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Modal :show="guest !== null" title="Merge guests" @close="emit('close')">
        <div
            class="flex flex-col gap-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            data-test="guest-merge"
        >
            <p>
                Keep
                <strong>{{ guest?.first_name }} {{ guest?.last_name }}</strong>
                (#{{ guest?.id }}) and merge a duplicate into it. The
                duplicate's bookings, consents and history move over, its
                details fill the blanks, and the duplicate is deleted.
            </p>

            <form class="flex gap-2" @submit.prevent="load">
                <input
                    v-model="term"
                    type="text"
                    placeholder="Search by name, mobile, e-mail or Guest ID"
                    data-test="guest-merge-search"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#0a0a0a]"
                />
                <button
                    type="submit"
                    class="rounded-md border border-[#e3e3e0] px-3 py-1.5 dark:border-[#3E3E3A]"
                >
                    Search
                </button>
            </form>

            <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                {{
                    term
                        ? 'Guests matching your search'
                        : 'Possible duplicates: same mobile, e-mail or name'
                }}
            </p>
            <ul
                class="max-h-64 divide-y divide-[#e3e3e0] overflow-y-auto rounded-md border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
            >
                <li v-if="loading" class="px-3 py-3 text-xs text-[#706f6c]">
                    Searching…
                </li>
                <li
                    v-else-if="!candidates.length"
                    class="px-3 py-3 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    No guest found.
                </li>
                <li v-for="candidate in candidates" v-else :key="candidate.id">
                    <label
                        class="flex cursor-pointer items-start gap-2 px-3 py-2 hover:bg-[#f5f5f4] dark:hover:bg-[#1D1D1B]"
                    >
                        <input
                            v-model="duplicate"
                            type="radio"
                            name="duplicate"
                            :value="candidate"
                            class="mt-1"
                            :data-test="`guest-merge-${candidate.id}`"
                        />
                        <span>
                            <span class="font-medium">{{
                                candidate.name
                            }}</span>
                            <span class="text-xs text-[#706f6c]"
                                >#{{ candidate.id }}</span
                            >
                            <span
                                class="block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{
                                    [candidate.phone, candidate.email]
                                        .filter(Boolean)
                                        .join(' · ') || 'No contact details'
                                }}
                                · {{ candidate.bookings }} booking{{
                                    candidate.bookings === 1 ? '' : 's'
                                }}
                            </span>
                        </span>
                    </label>
                </li>
            </ul>

            <p
                v-if="duplicate"
                class="rounded bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200"
            >
                {{ duplicate.name }} (#{{ duplicate.id }}) will be deleted after
                its {{ duplicate.bookings }} booking{{
                    duplicate.bookings === 1 ? '' : 's'
                }}
                move to #{{ guest?.id }}. This can't be undone.
            </p>
            <p
                v-if="error"
                class="rounded bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300"
                role="alert"
            >
                {{ error }}
            </p>

            <div
                class="flex justify-end gap-2 border-t border-[#e3e3e0] pt-3 dark:border-[#3E3E3A]"
            >
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-3 py-1.5 dark:border-[#3E3E3A]"
                    @click="emit('close')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    :disabled="!duplicate || processing"
                    data-test="guest-merge-confirm"
                    class="rounded-md bg-[#1b1b18] px-3 py-1.5 font-semibold text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                    @click="merge"
                >
                    Merge
                </button>
            </div>
        </div>
    </Modal>
</template>
