<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import type { User } from '@/types/auth';
import { Head, Link, router } from '@inertiajs/vue3';
import { usePropertyTime } from '@/lib/usePropertyTime';

/** Times arrive in UTC and are shown in the property's timezone. */
const propertyTime = usePropertyTime();

defineOptions({ layout: AdminLayout });

defineProps<{
    users: {
        data: User[];
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();

function destroyUser(user: User): void {
    if (!confirm(`Delete ${user.name}? This cannot be undone.`)) {
        return;
    }

    router.delete(admin.users.destroy(user).url);
}
</script>

<template>
    <Head title="Users" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Users
        </h1>
        <Link
            :href="admin.users.create()"
            class="inline-flex items-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
        >
            Create user
        </Link>
    </div>

    <div
        class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <table
            class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
        >
            <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                <tr>
                    <th
                        class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Name
                    </th>
                    <th
                        class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Email
                    </th>
                    <th
                        class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Mobile
                    </th>
                    <th
                        class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Role
                    </th>
                    <th
                        class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Status
                    </th>
                    <th
                        class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Joined
                    </th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody
                class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
            >
                <tr v-for="user in users.data" :key="user.id">
                    <td
                        class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        {{ user.name }}
                    </td>
                    <td
                        class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{ user.email }}
                    </td>
                    <td
                        class="px-4 py-3 text-sm whitespace-nowrap text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{
                            user.mobile
                                ? `${user.mobile_country_code ?? ''} ${user.mobile}`.trim()
                                : '—'
                        }}
                    </td>
                    <td
                        class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{ user.roles?.[0]?.name ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                user.status === 'inactive'
                                    ? 'bg-[#f5f5f4] text-[#706f6c] dark:bg-[#2a2a28] dark:text-[#A1A09A]'
                                    : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                            "
                        >
                            {{
                                user.status === 'inactive'
                                    ? 'Inactive'
                                    : 'Active'
                            }}
                        </span>
                    </td>
                    <td
                        class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{ propertyTime.date(user.created_at) }}
                    </td>
                    <td class="px-4 py-3 text-right text-sm">
                        <template v-if="user.can_manage">
                            <Link
                                :href="admin.users.edit(user)"
                                class="mr-3 font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                            >
                                Edit
                            </Link>
                            <button
                                type="button"
                                class="font-medium text-red-600 underline underline-offset-4"
                                @click="destroyUser(user)"
                            >
                                Delete
                            </button>
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-if="users.links.length > 3" class="mt-4 flex flex-wrap gap-1">
        <template v-for="(link, index) in users.links" :key="index">
            <Link
                v-if="link.url"
                :href="link.url"
                class="rounded-md px-3 py-1.5 text-sm"
                :class="
                    link.active
                        ? 'bg-[#1b1b18] text-white dark:bg-[#eeeeec] dark:text-[#1C1C1A]'
                        : 'text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]'
                "
                ><span v-html="link.label"
            /></Link>
            <span
                v-else
                class="rounded-md px-3 py-1.5 text-sm text-[#A1A09A]"
                v-html="link.label"
            />
        </template>
    </div>
</template>
