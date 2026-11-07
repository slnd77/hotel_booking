<script setup lang="ts">
export type PermissionItem = { name: string; label: string };
export type PermissionGroup = { name: string; permissions: PermissionItem[] };
export type PermissionSection = { name: string; groups: PermissionGroup[] };

const props = defineProps<{
    catalogue: PermissionSection[];
    disabled?: boolean;
}>();

const permissions = defineModel<string[]>({ required: true });

function groupNames(group: PermissionGroup): string[] {
    return group.permissions.map((permission) => permission.name);
}

function isGroupChecked(group: PermissionGroup): boolean {
    return groupNames(group).every((name) => permissions.value.includes(name));
}

function isGroupPartial(group: PermissionGroup): boolean {
    const names = groupNames(group);
    const checked = names.filter((name) =>
        permissions.value.includes(name),
    ).length;

    return checked > 0 && checked < names.length;
}

function toggleGroup(group: PermissionGroup, checked: boolean): void {
    const names = groupNames(group);

    permissions.value = checked
        ? [...new Set([...permissions.value, ...names])]
        : permissions.value.filter((name) => !names.includes(name));
}

function toggle(name: string, checked: boolean): void {
    permissions.value = checked
        ? [...permissions.value, name]
        : permissions.value.filter((granted) => granted !== name);
}

defineExpose({
    setAll(checked: boolean): void {
        permissions.value = checked
            ? props.catalogue.flatMap((section) =>
                  section.groups.flatMap(groupNames),
              )
            : [];
    },
});
</script>

<template>
    <div>
        <section
            v-for="section in catalogue"
            :key="section.name"
            class="mb-6 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <h2
                class="border-b border-[#e3e3e0] bg-[#f5f5f4] px-4 py-2 font-semibold text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#1D1D1B] dark:text-[#EDEDEC]"
            >
                {{ section.name }}
            </h2>
            <div class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]">
                <fieldset
                    v-for="group in section.groups"
                    :key="group.name"
                    class="px-4 py-3"
                    :disabled="disabled"
                >
                    <legend class="sr-only">{{ group.name }}</legend>
                    <label
                        class="mb-2 flex items-center gap-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            type="checkbox"
                            :checked="isGroupChecked(group)"
                            :indeterminate="isGroupPartial(group)"
                            @change="
                                toggleGroup(
                                    group,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        {{ group.name }}
                    </label>
                    <div
                        class="grid gap-x-6 gap-y-1.5 pl-6 sm:grid-cols-2 xl:grid-cols-3"
                    >
                        <label
                            v-for="permission in group.permissions"
                            :key="permission.name"
                            class="flex items-start gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            <input
                                type="checkbox"
                                :checked="permissions.includes(permission.name)"
                                class="mt-0.5"
                                @change="
                                    toggle(
                                        permission.name,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                            {{ permission.label }}
                        </label>
                    </div>
                </fieldset>
            </div>
        </section>
    </div>
</template>
