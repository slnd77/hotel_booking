<script setup lang="ts">
import { holidayType } from '@/lib/holidays';
import { computed } from 'vue';

const props = defineProps<{ type: string }>();

const option = computed(() => holidayType(props.type));
</script>

<template>
    <svg
        class="shrink-0"
        viewBox="0 0 24 24"
        fill="currentColor"
        :style="{ color: option.color }"
        role="img"
        :aria-label="option.label"
    >
        <path :d="option.icon" fill-rule="evenodd" />
    </svg>
</template>
