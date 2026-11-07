<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';

const props = defineProps<{
    show: boolean;
    title?: string;
    /** Tailwind max-width class for the panel. */
    maxWidth?: string;
}>();

const emit = defineEmits<{
    close: [];
}>();

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.show) {
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4"
        @click.self="emit('close')"
    >
        <div
            class="max-h-full w-full overflow-y-auto rounded-lg border border-[#e3e3e0] bg-white p-6 dark:border-[#3E3E3A] dark:bg-[#161615]"
            :class="maxWidth ?? 'max-w-md'"
        >
            <div v-if="title" class="mb-4 flex items-center justify-between">
                <h2
                    class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ title }}
                </h2>
                <button
                    type="button"
                    class="text-sm text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                    @click="emit('close')"
                >
                    ✕
                </button>
            </div>
            <slot />
        </div>
    </div>
</template>
