import { ref, watch, type Ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import type { ThemeMode } from '@/types/inertia';

/**
 * Apply a theme mode to the document root optimistically. SPA (Inertia) visits swap <body>, not
 * <html>, so after choosing a theme the client must set/remove data-theme-mode itself for an instant
 * effect — the server (app.blade.php) re-emits it on the next full load. "system" = absence of the
 * attribute (prefers-color-scheme decides).
 */
export function applyThemeMode(mode: ThemeMode): void {
    const html = document.documentElement;
    if (mode === 'light' || mode === 'dark') {
        html.setAttribute('data-theme-mode', mode);
    } else {
        html.removeAttribute('data-theme-mode');
    }
}

/**
 * Reactive theme preference bound to the shared ui.theme prop. `setMode` flips the theme instantly
 * (client-side) and persists it to user_ui_preferences. Shared by the Settings panel and the top-nav
 * quick toggle so both stay in sync and behave identically.
 */
export function useThemePreference(): { mode: Ref<ThemeMode>; setMode: (mode: ThemeMode) => void } {
    const page = usePage();
    const mode = ref<ThemeMode>(page.props.ui.theme.mode);

    watch(
        () => page.props.ui.theme.mode,
        (m) => {
            mode.value = m;
        },
    );

    function setMode(next: ThemeMode): void {
        mode.value = next;
        applyThemeMode(next);
        router.patch(
            '/settings/appearance',
            { theme_mode: next },
            { preserveScroll: true, preserveState: true },
        );
    }

    return { mode, setMode };
}
