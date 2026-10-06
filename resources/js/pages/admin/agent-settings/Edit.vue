<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AdminLayout });

type RateCode = {
    id: number;
    name: string;
    code: string | null;
    description: string | null;
    is_active: boolean;
    agencies_count: number;
};

const props = defineProps<{
    property: { id: number; name: string };
    rateCodes: RateCode[];
}>();

const editing = ref<RateCode | null>(null);
const formOpen = ref(false);
const confirmingDeleteId = ref<number | null>(null);

const form = useForm({
    name: '',
    code: '',
    description: '',
    is_active: true,
});

function openForm(rateCode: RateCode | null = null): void {
    editing.value = rateCode;
    form.defaults({
        name: rateCode?.name ?? '',
        code: rateCode?.code ?? '',
        description: rateCode?.description ?? '',
        is_active: rateCode?.is_active ?? true,
    });
    form.reset();
    form.clearErrors();
    formOpen.value = true;
}

function closeForm(): void {
    formOpen.value = false;
}

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => closeForm() };

    if (editing.value) {
        form.put(
            admin.agentSettings.rateCodes.update({
                property: props.property.id,
                rateCode: editing.value.id,
            }).url,
            options,
        );
    } else {
        form.post(
            admin.agentSettings.rateCodes.store(props.property.id).url,
            options,
        );
    }
}

function destroy(rateCode: RateCode): void {
    router.delete(
        admin.agentSettings.rateCodes.destroy({
            property: props.property.id,
            rateCode: rateCode.id,
        }).url,
        {
            preserveScroll: true,
            onFinish: () => (confirmingDeleteId.value = null),
        },
    );
}

const thClass =
    'px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]';
const tdClass = 'px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]';
const labelClass =
    'mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]';
const inputClass =
    'w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
</script>

<template>
    <Head :title="`Agent Settings: ${property.name}`" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Agent Settings
        </h1>
        <div class="flex items-center gap-3">
            <Link
                :href="
                    admin.databases.agents.index({
                        query: { property: property.id },
                    }).url
                "
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
            >
                Agents
            </Link>
            <button
                type="button"
                class="rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-medium text-white hover:bg-[#d92a02]"
                @click="openForm()"
            >
                Add Rate Code
            </button>
        </div>
    </div>

    <h2
        class="mb-3 text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
    >
        Agent Rate Codes
    </h2>

    <div
        class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <table
            class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
        >
            <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                <tr>
                    <th :class="thClass">Name</th>
                    <th :class="thClass">Code</th>
                    <th :class="thClass">Description</th>
                    <th :class="thClass">Agencies</th>
                    <th :class="thClass">Status</th>
                    <th :class="thClass"></th>
                </tr>
            </thead>
            <tbody
                class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
            >
                <tr v-for="rateCode in rateCodes" :key="rateCode.id">
                    <td :class="[tdClass, 'font-medium']">
                        {{ rateCode.name }}
                    </td>
                    <td :class="tdClass">{{ rateCode.code || '—' }}</td>
                    <td
                        :class="[tdClass, 'max-w-xs truncate']"
                        :title="rateCode.description ?? undefined"
                    >
                        {{ rateCode.description || '—' }}
                    </td>
                    <td :class="tdClass">{{ rateCode.agencies_count }}</td>
                    <td :class="tdClass">
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                rateCode.is_active
                                    ? 'bg-green-100 text-green-700'
                                    : 'bg-[#f5f5f4] text-[#706f6c]'
                            "
                        >
                            {{ rateCode.is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td :class="[tdClass, 'text-right whitespace-nowrap']">
                        <button
                            type="button"
                            class="mr-3 text-[#0284c7] hover:underline"
                            @click="openForm(rateCode)"
                        >
                            Edit
                        </button>
                        <template v-if="confirmingDeleteId === rateCode.id">
                            <button
                                type="button"
                                class="mr-2 font-medium text-red-600 hover:underline"
                                @click="destroy(rateCode)"
                            >
                                Confirm delete
                            </button>
                            <button
                                type="button"
                                class="text-[#706f6c] hover:underline"
                                @click="confirmingDeleteId = null"
                            >
                                Cancel
                            </button>
                        </template>
                        <button
                            v-else
                            type="button"
                            class="text-red-600 hover:underline"
                            @click="confirmingDeleteId = rateCode.id"
                        >
                            Delete
                        </button>
                    </td>
                </tr>
                <tr v-if="rateCodes.length === 0">
                    <td
                        colspan="6"
                        class="px-4 py-8 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        No rate codes yet. Add one to use it as an agency's
                        class.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div
        v-if="formOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4"
        @click.self="closeForm"
    >
        <form
            class="w-full max-w-lg rounded-lg border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
            @submit.prevent="submit"
        >
            <div
                class="flex items-center justify-between bg-[#1b1b18] px-6 py-3"
            >
                <h2 class="font-display text-base font-semibold text-white">
                    {{ editing ? 'Update Rate Code' : 'Add Rate Code' }}
                </h2>
                <button
                    type="button"
                    class="text-white/80 hover:text-white"
                    aria-label="Close"
                    @click="closeForm"
                >
                    ✕
                </button>
            </div>

            <div class="grid grid-cols-2 gap-4 px-6 py-5">
                <div>
                    <label for="rate_code_name" :class="labelClass"
                        >Name*</label
                    >
                    <input
                        id="rate_code_name"
                        v-model="form.name"
                        type="text"
                        placeholder="e.g. Agent1"
                        :class="inputClass"
                    />
                    <p
                        v-if="form.errors.name"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ form.errors.name }}
                    </p>
                </div>
                <div>
                    <label for="rate_code_code" :class="labelClass">Code</label>
                    <input
                        id="rate_code_code"
                        v-model="form.code"
                        type="text"
                        :class="inputClass"
                    />
                    <p
                        v-if="form.errors.code"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ form.errors.code }}
                    </p>
                </div>
                <div class="col-span-2">
                    <label for="rate_code_description" :class="labelClass"
                        >Description</label
                    >
                    <textarea
                        id="rate_code_description"
                        v-model="form.description"
                        rows="3"
                        :class="inputClass"
                    />
                    <p
                        v-if="form.errors.description"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ form.errors.description }}
                    </p>
                </div>
                <label
                    class="col-span-2 flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="h-4 w-4"
                    />
                    Active (can be picked for agencies)
                </label>
            </div>

            <div
                class="flex items-center gap-4 border-t border-[#e3e3e0] px-6 py-4 dark:border-[#3E3E3A]"
            >
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-semibold text-white hover:bg-[#d92a02] disabled:opacity-60"
                >
                    {{ form.processing ? 'Saving…' : 'Save' }}
                </button>
                <button
                    type="button"
                    class="text-sm font-medium text-[#0284c7] hover:underline"
                    @click="closeForm"
                >
                    Cancel
                </button>
            </div>
        </form>
    </div>
</template>
