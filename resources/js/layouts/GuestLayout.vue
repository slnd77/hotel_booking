<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center justify-center bg-[#FDFDFC] px-6 py-12 dark:bg-[#0a0a0a]"
    >
        <Link
            href="/"
            class="font-display mb-8 flex flex-col items-center gap-3 text-2xl font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            <img
                src="/brand/hospitality-mark-128.webp"
                width="72"
                height="72"
                alt=""
                decoding="async"
                class="block h-[72px] w-[72px]"
            />
            Vasuttam PMS
        </Link>

        <div
            class="w-full max-w-sm rounded-lg bg-white p-8 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]"
        >
            <slot />
        </div>
    </div>
</template>
