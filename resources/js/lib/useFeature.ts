import { usePage } from '@inertiajs/vue3';

/**
 * Whether a feature is on. Installed packages may turn features off (shared "features" prop);
 * always true when none does or the item needs no feature.
 */
export function useFeature() {
    const page = usePage();

    return (feature?: string | null): boolean =>
        !feature ||
        !page.props.features ||
        page.props.features[feature] !== false;
}
