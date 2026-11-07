import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

type PageModule = { default: DefineComponent };

// The app's pages, and those of installed packages, named "package::page".
const pages = import.meta.glob<PageModule>('./pages/**/*.vue');
const packagePages = import.meta.glob<PageModule>(
    '../../packages/vasuttam/*/resources/js/pages/**/*.vue',
);

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const [packageName, page] = name.includes('::')
            ? name.split('::')
            : [null, name];
        const load = packageName
            ? packagePages[
                  `../../packages/vasuttam/${packageName}/resources/js/pages/${page}.vue`
              ]
            : pages[`./pages/${name}.vue`];

        if (!load) {
            throw new Error(`Page not found: ${name}`);
        }

        return (await load()).default;
    },
    progress: {
        color: '#4B5563',
    },
});
