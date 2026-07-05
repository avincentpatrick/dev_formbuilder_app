import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import AppLayout from '@/Layouts/AppLayout.vue';

const appName = import.meta.env.VITE_APP_NAME ?? 'Meridian';

// Guest/central surfaces manage their own chrome and must NOT get the tenant app shell:
// the auth + invitation pages wrap themselves in AuthLayout in-template; the admin console and the
// marketing Welcome are central-domain pages. Every other (tenant) page gets the persistent AppLayout.
const isSelfLaidOut = (name: string): boolean =>
    /^(auth|invitations|admin)\//.test(name) || name === 'Welcome';

type PageModule = { default: DefineComponent & { layout?: unknown } };

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent<PageModule>(
            `./Pages/${name}.vue`,
            import.meta.glob<PageModule>('./Pages/**/*.vue'),
        ).then((page) => {
            // AppLayout is a module-level import (stable identity) so Inertia keeps ONE persistent
            // instance across visits — the shell (nav/sidebar) doesn't remount or flash. Return the
            // component (with .layout attached) rather than the module so the resolve type matches.
            page.default.layout ??= isSelfLaidOut(name) ? undefined : AppLayout;
            return page.default;
        }),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
