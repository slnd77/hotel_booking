<script setup lang="ts">
import admin from '@/routes/admin';
import { useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    show: boolean;
    propertyId?: number;
}>();

const emit = defineEmits<{
    close: [];
    uploaded: [];
}>();

const form = useForm<{ file: File | null }>({ file: null });
const fileInput = ref<HTMLInputElement | null>(null);

watch(
    () => props.show,
    (show) => {
        if (!show) {
            return;
        }

        form.reset();
        form.clearErrors();

        if (fileInput.value) {
            fileInput.value.value = '';
        }
    },
);

function onFileChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    form.file = target.files?.[0] ?? null;
}

function upload(): void {
    if (!form.file || !props.propertyId) {
        return;
    }

    form.post(
        admin.databases.guests.upload({ query: { property: props.propertyId } })
            .url,
        {
            forceFormData: true,
            onSuccess: () => emit('uploaded'),
        },
    );
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
                    Upload Guests
                </h2>
            </div>

            <div class="flex flex-col gap-4 px-6 py-5">
                <a
                    :href="admin.databases.guests.uploadTemplate().url"
                    class="text-sm font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                >
                    Download upload template
                </a>

                <label
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    CSV file*
                    <input
                        ref="fileInput"
                        type="file"
                        accept=".csv,text/csv"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] file:mr-3 file:rounded file:border-0 file:bg-[#f5f5f4] file:px-2 file:py-1 focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        @change="onFileChange"
                    />
                    <span
                        v-if="form.errors.file"
                        class="text-sm text-red-600"
                        >{{ form.errors.file }}</span
                    >
                </label>
            </div>

            <div class="flex items-center gap-4 px-6 pb-5">
                <button
                    type="button"
                    :disabled="!form.file || form.processing"
                    class="rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-semibold text-white hover:bg-[#d92a02] disabled:opacity-50"
                    @click="upload"
                >
                    {{ form.processing ? 'Uploading…' : 'Upload' }}
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
