import admin from '@/routes/admin';
import type { Option } from '@/types';
import { ref } from 'vue';

export function useLocationCascade() {
    const states = ref<Option[]>([]);
    const cities = ref<Option[]>([]);

    async function loadStates(
        countryId: number | string | null | undefined,
    ): Promise<void> {
        states.value = [];
        cities.value = [];

        if (!countryId) {
            return;
        }

        const response = await fetch(
            admin.locations.states.url({ query: { country_id: countryId } }),
        );
        states.value = await response.json();
    }

    async function loadCities(
        stateId: number | string | null | undefined,
    ): Promise<void> {
        cities.value = [];

        if (!stateId) {
            return;
        }

        const response = await fetch(
            admin.locations.cities.url({ query: { state_id: stateId } }),
        );
        cities.value = await response.json();
    }

    return { states, cities, loadStates, loadCities };
}
