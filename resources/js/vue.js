import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
     
import './bootstrap';

const appName = window.document.getElementsByTagName('title')[0]?.innerText || 'Laravel';

function resolvePage(name) {
    const [page, module] = name.split('::');

    const pagePath = module
        ? `../../Modules/${module}/Resources/assets/js/Pages/${page}.vue`
        : `./Pages/${page}.vue`;

    const pages = module
        ? import.meta.glob('../../Modules/**/Resources/assets/js/Pages/**/*.vue')
        : import.meta.glob('./Pages/**/*.vue');

    if (!pages[pagePath]) {
        const errorMessage = `Page not found: ${pagePath}`;
        console.log(errorMessage);
        throw new Error(errorMessage);
    }

    return typeof pages[pagePath] === 'function' ? pages[pagePath]() : pages[pagePath];
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: async (name) => await resolvePage(name),
        //.then((module) => module.default),
    setup({el, App, props, plugin}) {
        return createApp({ render: () => h(App, props) })
        .use(plugin)
        .mount(el)
    },
});