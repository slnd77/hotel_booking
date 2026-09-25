<script setup lang="ts">
import AuthenticatedSessionController from '@/actions/Laravel/Fortify/Http/Controllers/AuthenticatedSessionController';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
</script>

<template>
    <div class="min-h-screen bg-[#FDFDFC] dark:bg-[#0a0a0a]">
        <header
            class="flex h-16 items-center justify-between border-b border-[#e3e3e0] px-4 sm:px-6 dark:border-[#3E3E3A]"
        >
            <span
                class="font-display flex items-center gap-2.5 text-lg font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                <img
                    src="/brand/hospitality-mark-64.webp"
                    srcset="
                        /brand/hospitality-mark-64.webp  1x,
                        /brand/hospitality-mark-128.webp 2x
                    "
                    width="32"
                    height="32"
                    alt=""
                    decoding="async"
                    class="block h-8 w-8"
                />
                Vasuttam PMS
            </span>

            <div class="flex items-center gap-4">
                <span class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    {{ page.props.auth.user?.name }}
                </span>
                <Link
                    :href="AuthenticatedSessionController.destroy()"
                    method="post"
                    as="button"
                    class="text-sm font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                >
                    Log out
                </Link>
            </div>
        </header>

        <main class="p-4 sm:p-6">
            <div
                v-if="page.props.flash.success"
                class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm font-medium text-green-700 dark:bg-green-950 dark:text-green-400"
            >
                {{ page.props.flash.success }}
            </div>
            <div
                v-if="page.props.flash.error"
                class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:bg-red-950 dark:text-red-400"
            >
                {{ page.props.flash.error }}
            </div>

            <slot />
        </main>
    </div>
</template>
