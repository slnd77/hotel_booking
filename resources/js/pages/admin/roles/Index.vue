<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import PermissionMatrix from '@/components/PermissionMatrix.vue';
import type { PermissionSection } from '@/components/PermissionMatrix.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

defineOptions({ layout: AdminLayout });

type RoleRow = {
    id: number;
    name: string;
    users_count: number;
    permissions: string[];
};

const props = defineProps<{
    systemRoles: RoleRow[];
    customRoles: RoleRow[];
    selectedRoleId: number | null;
    catalogue: PermissionSection[];
}>();

const allRoles = computed(() => [...props.systemRoles, ...props.customRoles]);
const selectedRole = computed(
    () =>
        allRoles.value.find((role) => role.id === props.selectedRoleId) ?? null,
);
const isSelectedCustom = computed(() =>
    props.customRoles.some((role) => role.id === props.selectedRoleId),
);
const totalPermissions = computed(() =>
    props.catalogue.reduce(
        (sum, section) =>
            sum +
            section.groups.reduce(
                (count, group) => count + group.permissions.length,
                0,
            ),
        0,
    ),
);
const selectedPermissions = computed({
    get: () => selectedRole.value?.permissions ?? [],
    set: () => {},
});

/** The ⋮ menu that is open, by role id. */
const menuRoleId = ref<number | null>(null);

function closeMenu(): void {
    menuRoleId.value = null;
}

onMounted(() => window.addEventListener('click', closeMenu));
onBeforeUnmount(() => window.removeEventListener('click', closeMenu));

const modalOpen = ref(false);
const editingRole = ref<RoleRow | null>(null);
const startFrom = ref<'copy' | 'custom'>('copy');
const copyFromRoleId = ref<number | ''>('');
const matrix = ref<InstanceType<typeof PermissionMatrix> | null>(null);

const form = useForm<{ name: string; permissions: string[] }>({
    name: '',
    permissions: [],
});

function openCreate(copyFrom: RoleRow | null = null): void {
    closeMenu();
    editingRole.value = null;
    startFrom.value = 'copy';
    copyFromRoleId.value = copyFrom?.id ?? '';
    form.defaults({
        name: '',
        permissions: [...(copyFrom?.permissions ?? [])],
    });
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(role: RoleRow): void {
    closeMenu();
    editingRole.value = role;
    form.defaults({ name: role.name, permissions: [...role.permissions] });
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function copyPermissions(): void {
    const source = allRoles.value.find(
        (role) => role.id === copyFromRoleId.value,
    );
    form.permissions = [...(source?.permissions ?? [])];
}

function chooseStart(choice: 'copy' | 'custom'): void {
    startFrom.value = choice;

    if (choice === 'custom') {
        copyFromRoleId.value = '';
        form.permissions = [];
    }
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (modalOpen.value = false),
    };

    if (editingRole.value) {
        form.put(admin.roles.update(editingRole.value.id).url, options);
    } else {
        form.post(admin.roles.store().url, options);
    }
}

function destroy(role: RoleRow): void {
    closeMenu();

    if (confirm(`Delete role ${role.name}?`)) {
        router.delete(admin.roles.destroy(role.id).url, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Roles and Permissions" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Roles and Permissions
        </h1>
    </div>

    <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
        <aside class="flex w-full shrink-0 flex-col gap-1 lg:w-72">
            <h2
                class="px-3 pb-1 text-xs font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
            >
                System Defined Roles
            </h2>
            <div
                v-for="role in systemRoles"
                :key="role.id"
                class="relative flex items-center"
            >
                <Link
                    :href="admin.roles.index({ query: { role: role.id } }).url"
                    preserve-scroll
                    class="flex flex-1 items-center justify-between rounded-md px-3 py-2 text-sm"
                    :class="
                        role.id === selectedRoleId
                            ? 'bg-[#1b1b18] font-medium text-white dark:bg-[#eeeeec] dark:text-[#1C1C1A]'
                            : 'text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]'
                    "
                >
                    <span>{{ role.name }}</span>
                    <span class="text-xs opacity-70"
                        >{{ role.permissions.length }} ·
                        {{ role.users_count }} user{{
                            role.users_count === 1 ? '' : 's'
                        }}</span
                    >
                </Link>
                <button
                    type="button"
                    class="ml-1 rounded-md px-2 py-1 text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]"
                    :aria-label="`${role.name} actions`"
                    @click.stop="
                        menuRoleId = menuRoleId === role.id ? null : role.id
                    "
                >
                    ⋮
                </button>
                <div
                    v-if="menuRoleId === role.id"
                    class="absolute top-full right-0 z-20 mt-1 w-48 rounded-md border border-[#e3e3e0] bg-white py-1 text-sm shadow-md dark:border-[#3E3E3A] dark:bg-[#161615]"
                    @click.stop
                >
                    <button
                        type="button"
                        class="block w-full px-3 py-1.5 text-left hover:bg-[#f5f5f4] dark:hover:bg-[#1D1D1B]"
                        @click="openCreate(role)"
                    >
                        Copy to a new role
                    </button>
                </div>
            </div>

            <h2
                class="mt-4 px-3 pb-1 text-xs font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
            >
                User Defined Roles
            </h2>
            <div
                v-for="role in customRoles"
                :key="role.id"
                class="relative flex items-center"
            >
                <Link
                    :href="admin.roles.index({ query: { role: role.id } }).url"
                    preserve-scroll
                    class="flex flex-1 items-center justify-between rounded-md px-3 py-2 text-sm"
                    :class="
                        role.id === selectedRoleId
                            ? 'bg-[#1b1b18] font-medium text-white dark:bg-[#eeeeec] dark:text-[#1C1C1A]'
                            : 'text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]'
                    "
                >
                    <span>{{ role.name }}</span>
                    <span class="text-xs opacity-70"
                        >{{ role.permissions.length }} ·
                        {{ role.users_count }} user{{
                            role.users_count === 1 ? '' : 's'
                        }}</span
                    >
                </Link>
                <button
                    type="button"
                    class="ml-1 rounded-md px-2 py-1 text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]"
                    :aria-label="`${role.name} actions`"
                    @click.stop="
                        menuRoleId = menuRoleId === role.id ? null : role.id
                    "
                >
                    ⋮
                </button>
                <div
                    v-if="menuRoleId === role.id"
                    class="absolute top-full right-0 z-20 mt-1 w-48 rounded-md border border-[#e3e3e0] bg-white py-1 text-sm shadow-md dark:border-[#3E3E3A] dark:bg-[#161615]"
                    @click.stop
                >
                    <button
                        type="button"
                        class="block w-full px-3 py-1.5 text-left hover:bg-[#f5f5f4] dark:hover:bg-[#1D1D1B]"
                        @click="openEdit(role)"
                    >
                        Edit
                    </button>
                    <button
                        type="button"
                        class="block w-full px-3 py-1.5 text-left hover:bg-[#f5f5f4] dark:hover:bg-[#1D1D1B]"
                        @click="openCreate(role)"
                    >
                        Copy to a new role
                    </button>
                    <button
                        type="button"
                        class="block w-full px-3 py-1.5 text-left text-red-600 hover:bg-[#f5f5f4] dark:hover:bg-[#1D1D1B]"
                        @click="destroy(role)"
                    >
                        Delete
                    </button>
                </div>
            </div>
            <p
                v-if="customRoles.length === 0"
                class="px-3 py-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
            >
                No user defined roles yet.
            </p>

            <button
                type="button"
                class="mt-3 self-center rounded-full bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700"
                @click.stop="openCreate()"
            >
                + Add role
            </button>
        </aside>

        <div v-if="selectedRole" class="flex-1">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    <span
                        class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >{{ selectedRole.name }}</span
                    >
                    · {{ selectedRole.permissions.length }} of
                    {{ totalPermissions }} permissions
                    <span v-if="!isSelectedCustom">
                        · System defined, can't be edited</span
                    >
                </p>
                <button
                    v-if="isSelectedCustom"
                    type="button"
                    class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    @click.stop="openEdit(selectedRole)"
                >
                    Edit
                </button>
            </div>

            <PermissionMatrix
                v-model="selectedPermissions"
                :catalogue="catalogue"
                disabled
            />
        </div>
    </div>

    <Modal
        :show="modalOpen"
        :title="editingRole ? `Edit role — ${editingRole.name}` : 'Add role'"
        max-width="max-w-6xl"
        @close="modalOpen = false"
    >
        <form novalidate @submit.prevent="submit">
            <section
                class="mb-6 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
            >
                <h3
                    class="mb-3 font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Basic Details
                </h3>
                <div
                    v-if="!editingRole"
                    class="mb-4 flex flex-wrap gap-x-10 gap-y-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <label class="flex items-center gap-2">
                        <input
                            type="radio"
                            :checked="startFrom === 'copy'"
                            @change="chooseStart('copy')"
                        />
                        Select a role to copy permissions from
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            type="radio"
                            :checked="startFrom === 'custom'"
                            @change="chooseStart('custom')"
                        />
                        Start with no permissions
                    </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label
                        v-if="!editingRole && startFrom === 'copy'"
                        class="block text-sm"
                    >
                        <span
                            class="mb-1 block text-[#706f6c] dark:text-[#A1A09A]"
                            >Role</span
                        >
                        <select
                            v-model="copyFromRoleId"
                            class="w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                            @change="copyPermissions"
                        >
                            <option value="">Select a role</option>
                            <optgroup label="System Defined Roles">
                                <option
                                    v-for="role in systemRoles"
                                    :key="role.id"
                                    :value="role.id"
                                >
                                    {{ role.name }}
                                </option>
                            </optgroup>
                            <optgroup
                                v-if="customRoles.length"
                                label="User Defined Roles"
                            >
                                <option
                                    v-for="role in customRoles"
                                    :key="role.id"
                                    :value="role.id"
                                >
                                    {{ role.name }}
                                </option>
                            </optgroup>
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span
                            class="mb-1 block text-[#706f6c] dark:text-[#A1A09A]"
                            >Name</span
                        >
                        <input
                            v-model="form.name"
                            type="text"
                            maxlength="100"
                            required
                            class="w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                        />
                        <span
                            v-if="form.errors.name"
                            class="mt-1 block text-red-600"
                            >{{ form.errors.name }}</span
                        >
                    </label>
                </div>
            </section>

            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">
                    Permissions
                    <span
                        class="text-sm font-normal text-[#706f6c] dark:text-[#A1A09A]"
                        >· {{ form.permissions.length }} of
                        {{ totalPermissions }}</span
                    >
                </h3>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]"
                        @click="matrix?.setAll(true)"
                    >
                        Select all
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]"
                        @click="matrix?.setAll(false)"
                    >
                        Clear all
                    </button>
                </div>
            </div>
            <p v-if="form.errors.permissions" class="mb-3 text-sm text-red-600">
                {{ form.errors.permissions }}
            </p>

            <PermissionMatrix
                ref="matrix"
                v-model="form.permissions"
                :catalogue="catalogue"
            />

            <div
                class="sticky bottom-0 flex justify-end gap-2 border-t border-[#e3e3e0] bg-white pt-4 dark:border-[#3E3E3A] dark:bg-[#161615]"
            >
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]"
                    @click="modalOpen = false"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                >
                    {{ form.processing ? 'Saving...' : 'Save' }}
                </button>
            </div>
        </form>
    </Modal>
</template>
