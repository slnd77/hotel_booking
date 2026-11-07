import { router } from '@inertiajs/vue3';
import { onMounted, watch } from 'vue';
import { useSelectedProperty } from './useSelectedProperty';

/**
 * An index page that only exists to open its property's page (Front Desk, Reports, a settings
 * tab, ...): goes there for the property picked in the header, and again when the pick changes.
 *
 * The visit starts once mounted, in the browser only: the SSR render has no router to navigate
 * with, so an immediate watch there would fail the render and lose SSR for the page.
 */
export function useRedirectToProperty(
    target: (property: { id: number }) => { url: string },
): void {
    const { selectedPropertyId } = useSelectedProperty();

    function open(value: number | ''): void {
        if (value !== '') {
            router.get(target({ id: value }).url, {}, { replace: true });
        }
    }

    onMounted(() => open(selectedPropertyId.value));
    watch(selectedPropertyId, open);
}
