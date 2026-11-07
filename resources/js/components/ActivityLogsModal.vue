<script setup lang="ts">
export type ActivityLogRow = {
    id: number;
    action: string;
    user: string | null;
    date: string | null;
};

const props = defineProps<{
    show: boolean;
    title: string;
    logs: ActivityLogRow[];
    timezone?: string | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

function formatLogDate(date: string | null): string {
    return date
        ? new Date(date).toLocaleString('en-GB', {
              timeZone: props.timezone ?? 'UTC',
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '--';
}
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        @click.self="emit('close')"
    >
        <div
            class="flex max-h-[80vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-xl dark:bg-[#161615]"
        >
            <div
                class="flex items-center justify-between border-b border-[#e3e3e0] px-4 py-3 dark:border-[#3E3E3A]"
            >
                <h3 class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">
                    {{ title }}
                </h3>
                <button
                    type="button"
                    class="text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                    @click="emit('close')"
                >
                    ✕
                </button>
            </div>
            <div class="overflow-y-auto">
                <table
                    class="min-w-full divide-y divide-[#e3e3e0] text-sm dark:divide-[#3E3E3A]"
                >
                    <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                        <tr>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Date
                            </th>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                User
                            </th>
                            <th
                                class="px-4 py-2 text-left text-xs font-medium text-[#706f6c] uppercase dark:text-[#A1A09A]"
                            >
                                Activity
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
                    >
                        <tr v-for="log in logs" :key="log.id">
                            <td
                                class="px-4 py-2 whitespace-nowrap text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ formatLogDate(log.date) }}
                            </td>
                            <td
                                class="px-4 py-2 text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ log.user ?? '--' }}
                            </td>
                            <td
                                class="px-4 py-2 text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ log.action }}
                            </td>
                        </tr>
                        <tr v-if="!logs.length">
                            <td
                                colspan="3"
                                class="px-4 py-3 text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                No activity yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
