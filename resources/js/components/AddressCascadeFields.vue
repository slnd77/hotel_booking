<script setup lang="ts">
import { useLocationCascade } from '@/lib/useLocationCascade';
import { onMounted, watch } from 'vue';

export type AddressValue = {
    address: string;
    country_id: number | null;
    state_id: number | null;
    city_id: number | null;
    postal_code: string;
};

const props = defineProps<{
    modelValue: AddressValue;
    countries: { id: number; name: string }[];
    idPrefix: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: AddressValue): void;
}>();

const location = useLocationCascade();

function update<K extends keyof AddressValue>(
    key: K,
    value: AddressValue[K],
): void {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}

function onCountryChange(countryId: number | null): void {
    emit('update:modelValue', {
        ...props.modelValue,
        country_id: countryId,
        state_id: null,
        city_id: null,
    });
    location.loadStates(countryId);
}

function onStateChange(stateId: number | null): void {
    emit('update:modelValue', {
        ...props.modelValue,
        state_id: stateId,
        city_id: null,
    });
    location.loadCities(stateId);
}

onMounted(() => {
    if (props.modelValue.country_id) {
        location.loadStates(props.modelValue.country_id).then(() => {
            if (props.modelValue.state_id) {
                location.loadCities(props.modelValue.state_id);
            }
        });
    }
});

watch(
    () => props.modelValue.country_id,
    (countryId, previous) => {
        if (
            countryId &&
            countryId !== previous &&
            location.states.value.length === 0
        ) {
            location.loadStates(countryId);
        }
    },
);
</script>

<template>
    <div class="flex flex-col gap-4">
        <label
            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            Address
            <input
                :value="modelValue.address"
                type="text"
                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                @input="
                    update('address', ($event.target as HTMLInputElement).value)
                "
            />
        </label>

        <label
            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            Country*
            <select
                :id="`${idPrefix}-country`"
                :value="modelValue.country_id ?? ''"
                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                @change="
                    onCountryChange(
                        ($event.target as HTMLSelectElement).value
                            ? Number(($event.target as HTMLSelectElement).value)
                            : null,
                    )
                "
            >
                <option value="">--Select Country--</option>
                <option
                    v-for="country in countries"
                    :key="country.id"
                    :value="country.id"
                >
                    {{ country.name }}
                </option>
            </select>
        </label>

        <label
            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            State*
            <select
                :value="modelValue.state_id ?? ''"
                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                @change="
                    onStateChange(
                        ($event.target as HTMLSelectElement).value
                            ? Number(($event.target as HTMLSelectElement).value)
                            : null,
                    )
                "
            >
                <option value="">--Select State--</option>
                <option
                    v-for="state in location.states.value"
                    :key="state.id"
                    :value="state.id"
                >
                    {{ state.name }}
                </option>
            </select>
        </label>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label
                class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
            >
                City*
                <select
                    :value="modelValue.city_id ?? ''"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    @change="
                        update(
                            'city_id',
                            ($event.target as HTMLSelectElement).value
                                ? Number(
                                      ($event.target as HTMLSelectElement)
                                          .value,
                                  )
                                : null,
                        )
                    "
                >
                    <option value="">--Select City--</option>
                    <option
                        v-for="city in location.cities.value"
                        :key="city.id"
                        :value="city.id"
                    >
                        {{ city.name }}
                    </option>
                </select>
            </label>
            <label
                class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
            >
                Postal Code*
                <input
                    :value="modelValue.postal_code"
                    type="text"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    @input="
                        update(
                            'postal_code',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </label>
        </div>
    </div>
</template>
