<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue';

export type ViewableDocument = {
    title: string;
    subtitle?: string;
    /** A server URL or a `blob:` object URL for a file that hasn't been uploaded yet. */
    url: string;
    mimeType: string | null;
    fileName: string;
    downloadUrl?: string;
};

const props = defineProps<{
    document: ViewableDocument | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const kind = computed<'image' | 'pdf' | 'other'>(() => {
    const mimeType = props.document?.mimeType ?? '';
    const extension =
        props.document?.fileName.split('.').pop()?.toLowerCase() ?? '';

    if (mimeType.startsWith('image/') && mimeType !== 'image/heic') {
        return 'image';
    }

    if (mimeType === 'application/pdf' || extension === 'pdf') {
        return 'pdf';
    }

    return 'other';
});

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.document) {
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div
        v-if="document"
        class="fixed inset-0 z-[130] flex items-center justify-center bg-black/50 p-4"
        @mousedown.self="emit('close')"
    >
        <div
            class="flex h-[85vh] w-full max-w-4xl flex-col overflow-hidden rounded-md bg-white shadow-2xl dark:bg-[#161615]"
        >
            <div
                class="flex items-center justify-between gap-3 bg-black px-5 py-3 text-white"
            >
                <div class="min-w-0">
                    <h2 class="truncate text-lg font-semibold">
                        {{ document.title }}
                    </h2>
                    <p
                        v-if="document.subtitle"
                        class="truncate text-xs text-white/70"
                    >
                        {{ document.subtitle }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-4">
                    <a
                        :href="document.downloadUrl ?? document.url"
                        :download="document.fileName"
                        class="text-sm underline underline-offset-4 hover:text-white/80"
                        >Download</a
                    >
                    <button
                        type="button"
                        title="Close"
                        class="text-xl leading-none text-white/80 hover:text-white"
                        @click="emit('close')"
                    >
                        ✕
                    </button>
                </div>
            </div>

            <div
                class="flex min-h-0 flex-1 items-center justify-center bg-[#f5f5f4] dark:bg-[#0a0a0a]"
            >
                <img
                    v-if="kind === 'image'"
                    :src="document.url"
                    :alt="document.title"
                    class="max-h-full max-w-full object-contain"
                />
                <iframe
                    v-else-if="kind === 'pdf'"
                    :src="document.url"
                    :title="document.title"
                    class="h-full w-full border-0"
                ></iframe>
                <div
                    v-else
                    class="px-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    <p class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ document.fileName }}
                    </p>
                    <p class="mt-1">
                        This file type can't be previewed in the browser.
                    </p>
                    <a
                        :href="document.downloadUrl ?? document.url"
                        :download="document.fileName"
                        class="mt-4 inline-block rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:bg-[#eeeeec] dark:text-[#1C1C1A]"
                    >
                        Download to view
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>
