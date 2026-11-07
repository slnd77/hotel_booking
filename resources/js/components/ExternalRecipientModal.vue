<script setup lang="ts">
import PhoneCountryCodeSelect, {
    type PhoneCountryOption,
} from '@/components/PhoneCountryCodeSelect.vue';
import { onBeforeUnmount, onMounted, reactive, watch } from 'vue';

export type ExternalRecipient = {
    name: string;
    phone_country_code: string;
    phone: string;
    email: string;
};

const props = defineProps<{
    show: boolean;
    countries: PhoneCountryOption[];
    defaultPhoneCountryCode: string;
    recipient?: ExternalRecipient | null;
}>();

const emit = defineEmits<{
    close: [];
    save: [recipient: ExternalRecipient];
}>();

function emptyRecipient(): ExternalRecipient {
    return {
        name: '',
        phone_country_code: props.defaultPhoneCountryCode,
        phone: '',
        email: '',
    };
}

const form = reactive<ExternalRecipient>(emptyRecipient());

watch(
    () => props.show,
    (show) => {
        if (!show) {
            return;
        }

        Object.assign(
            form,
            props.recipient ? { ...props.recipient } : emptyRecipient(),
        );
    },
    { immediate: true },
);

function save(): void {
    if (!form.name.trim()) {
        return;
    }

    emit('save', { ...form });
}

function close(): void {
    emit('close');
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.show) {
        close();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4"
        @click.self="close"
    >
        <div
            class="w-full max-w-lg overflow-hidden rounded-lg border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <div class="bg-[#1b1b18] px-6 py-3">
                <h2 class="font-display text-base font-semibold text-white">
                    Add/Update External Recipients
                </h2>
            </div>

            <div class="grid grid-cols-1 gap-4 px-6 py-5 sm:grid-cols-2">
                <label
                    class="col-span-full flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Name*
                    <input
                        v-model="form.name"
                        type="text"
                        required
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>

                <label
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Phone
                    <div class="flex gap-2">
                        <PhoneCountryCodeSelect
                            v-model="form.phone_country_code"
                            :countries="countries"
                        />
                        <input
                            v-model="form.phone"
                            type="text"
                            class="block flex-1 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                </label>

                <label
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    E-mail
                    <input
                        v-model="form.email"
                        type="email"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>
            </div>

            <div class="flex items-center gap-4 px-6 pb-5">
                <button
                    type="button"
                    :disabled="!form.name.trim()"
                    class="rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-semibold text-white hover:bg-[#d92a02] disabled:opacity-50"
                    @click="save"
                >
                    SAVE
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
