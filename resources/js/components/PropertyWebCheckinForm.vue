<script setup lang="ts">
import admin from '@/routes/admin';
import { useForm } from '@inertiajs/vue3';

export type WebCheckinSettings = {
    enabled: boolean;
    opens_days_before: number;
    require_id_document: boolean;
    require_signature: boolean;
    require_address: boolean;
    ask_arrival_time: boolean;
    include_in_confirmation_email: boolean;
    instructions: string;
    terms: string;
};

const props = defineProps<{
    propertyId: number;
    settings: WebCheckinSettings;
}>();

const form = useForm({ ...props.settings });

function save(): void {
    form.put(admin.properties.webCheckin.update(props.propertyId).url, {
        preserveScroll: true,
    });
}

type ToggleKey =
    | 'require_id_document'
    | 'require_signature'
    | 'require_address'
    | 'ask_arrival_time'
    | 'include_in_confirmation_email';

const toggles: { key: ToggleKey; label: string; help: string }[] = [
    {
        key: 'require_id_document',
        label: 'Ask for an ID document',
        help: 'The guest picks the ID type, enters its number and uploads a photo or PDF.',
    },
    {
        key: 'require_signature',
        label: 'Ask for a signature',
        help: 'The guest signs on screen; it prints on the registration card.',
    },
    { key: 'require_address', label: 'Ask for the home address', help: '' },
    { key: 'ask_arrival_time', label: 'Ask for the arrival time', help: '' },
    {
        key: 'include_in_confirmation_email',
        label: 'Put the link in the booking confirmation e-mail',
        help: 'The front desk can also send it from the booking.',
    },
];

const inputClass =
    'w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
</script>

<template>
    <form
        class="flex max-w-2xl flex-col gap-5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
        @submit.prevent="save"
    >
        <div>
            <h2 class="text-base font-semibold">Web check-in</h2>
            <p class="text-[#706f6c] dark:text-[#A1A09A]">
                Guests fill in their details, upload their ID and sign before
                they arrive, from a link sent with their booking.
            </p>
        </div>

        <label class="flex items-center gap-2 font-medium">
            <input v-model="form.enabled" type="checkbox" class="h-4 w-4" />
            Let guests check in online
        </label>

        <fieldset
            :disabled="!form.enabled"
            class="flex flex-col gap-4 disabled:opacity-60"
        >
            <label class="flex flex-col gap-1">
                The link opens this many days before arrival
                <input
                    v-model.number="form.opens_days_before"
                    type="number"
                    min="0"
                    max="60"
                    class="w-32"
                    :class="inputClass"
                />
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >It closes on the arrival date.</span
                >
                <span
                    v-if="form.errors.opens_days_before"
                    class="text-xs text-red-600"
                    >{{ form.errors.opens_days_before }}</span
                >
            </label>

            <div class="flex flex-col gap-2">
                <label
                    v-for="toggle in toggles"
                    :key="toggle.key"
                    class="flex items-start gap-2"
                >
                    <input
                        v-model="form[toggle.key]"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4"
                    />
                    <span>
                        {{ toggle.label }}
                        <span
                            v-if="toggle.help"
                            class="block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >{{ toggle.help }}</span
                        >
                    </span>
                </label>
            </div>

            <label class="flex flex-col gap-1">
                Instructions shown on the form
                <textarea
                    v-model="form.instructions"
                    rows="3"
                    maxlength="2000"
                    :class="inputClass"
                    placeholder="e.g. Check-in from 2 pm. Bring the ID you upload."
                ></textarea>
            </label>
            <label class="flex flex-col gap-1">
                Terms the guest must accept (leave empty for none)
                <textarea
                    v-model="form.terms"
                    rows="4"
                    maxlength="5000"
                    :class="inputClass"
                ></textarea>
            </label>
        </fieldset>

        <div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-md bg-[#1b1b18] px-4 py-2 font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
            >
                Save
            </button>
        </div>
    </form>
</template>
