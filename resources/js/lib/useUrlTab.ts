import { usePage } from '@inertiajs/vue3';
import { ref, watch, type Ref } from 'vue';

export function useUrlTab<T extends string>(
    tabs: readonly T[],
    fallback: T,
    paramName = 'tab',
): Ref<T> {
    // The URL Inertia holds for the page, not window.location: it is known while rendering on
    // the server too, so SSR and the hydrating browser open the same tab.
    const page = usePage();

    function initialTab(): T {
        const tab = new URLSearchParams(page.url.split('?')[1] ?? '').get(
            paramName,
        );

        return (tabs as readonly string[]).includes(tab ?? '')
            ? (tab as T)
            : fallback;
    }

    const activeTab = ref(initialTab()) as Ref<T>;

    watch(activeTab, (tab) => {
        const url = new URL(window.location.href);
        url.searchParams.set(paramName, tab);
        window.history.replaceState(window.history.state, '', url);
    });

    return activeTab;
}
