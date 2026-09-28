<script setup lang="ts">
import { useCan } from '@/lib/useCan';
import profile from '@/routes/profile';
import { Link } from '@inertiajs/vue3';

defineProps<{
    active: 'profile' | 'two-factor' | 'developer';
}>();

const can = useCan();
</script>

<template>
    <div
        class="mb-6 flex flex-wrap gap-1 border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <Link
            v-for="tab in [
                { key: 'profile', label: 'Profile', href: profile.edit().url },
                {
                    key: 'two-factor',
                    label: 'Two-Factor Authentication',
                    href: profile.twoFactor().url,
                },
                ...(can('manage_account.api_keys')
                    ? [
                          {
                              key: 'developer',
                              label: 'Developer',
                              href: profile.developer.index().url,
                          },
                      ]
                    : []),
            ]"
            :key="tab.key"
            :href="tab.href"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                active === tab.key
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]'
            "
        >
            {{ tab.label }}
        </Link>
    </div>
</template>
