<script setup lang="ts">
import TableColumnSettingsModal, {
    type TableColumn,
} from '@/components/TableColumnSettingsModal.vue';
import { ref } from 'vue';

/**
 * The gear button that opens "Set your default list view" for a table. `visibility` comes
 * from useColumnVisibility, which saves the choice.
 */
const props = defineProps<{
    columns: TableColumn[];
    visibility: Record<string, boolean>;
}>();

const open = ref(false);

function save(next: Record<string, boolean>): void {
    Object.assign(props.visibility, next);
    open.value = false;
}
</script>

<template>
    <span class="inline-flex">
        <button
            type="button"
            title="Column settings"
            class="rounded-md border border-[#e3e3e0] p-2 text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
            @click="open = true"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z"
                />
            </svg>
        </button>
        <TableColumnSettingsModal
            :show="open"
            :columns="columns"
            :model-value="visibility"
            @close="open = false"
            @save="save"
        />
    </span>
</template>
