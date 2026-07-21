import { computed, ref, watch, type ComputedRef } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import type { AccentToken, FontSizeScale, ThemeMode, UiTheme } from '@/types/inertia';

/**
 * Apply the §2.9 personalization attributes to <html>.
 *
 * SPA (Inertia) visits swap <body>, not <html>, so after choosing a preference the client must set or
 * remove the attribute itself for an instant effect — the server (app.blade.php) re-emits the durable
 * copy on the next full load. In EVERY axis the default is the ABSENCE of the attribute ("system"
 * mode, "blueprint" accent, "standard" size, dyslexia off), matching the server emission convention
 * exactly, so the two can never disagree about what "default" looks like.
 */
function setRootAttribute(name: string, value: string | null): void {
    const html = document.documentElement;

    if (value === null) {
        html.removeAttribute(name);
    } else {
        html.setAttribute(name, value);
    }
}

export function applyThemeMode(mode: ThemeMode): void {
    setRootAttribute('data-theme-mode', mode === 'light' || mode === 'dark' ? mode : null);
}

export function applyAccent(accent: AccentToken): void {
    setRootAttribute('data-accent', accent === 'teal' ? 'teal' : null);
}

export function applyFontSize(scale: FontSizeScale): void {
    setRootAttribute('data-font-size', scale === 'standard' ? null : scale);
}

export function applyDyslexiaFont(enabled: boolean): void {
    setRootAttribute('data-dyslexia-font', enabled ? 'true' : null);
}

interface AppearancePreference {
    mode: ComputedRef<ThemeMode>;
    accent: ComputedRef<AccentToken>;
    fontSize: ComputedRef<FontSizeScale>;
    dyslexiaFont: ComputedRef<boolean>;
    setMode: (next: ThemeMode) => void;
    setAccent: (next: AccentToken) => void;
    setFontSize: (next: FontSizeScale) => void;
    setDyslexiaFont: (next: boolean) => void;
}

/**
 * Reactive appearance preferences bound to the shared `ui.theme` prop.
 *
 * Each setter applies its own axis optimistically and then PATCHes ONLY that field. The anti-clobber
 * guarantee is server-side, not here: every rule on UpdateAppearanceRequest is `sometimes`, so an
 * omitted axis is never written. That is what lets the top-nav quick toggle (theme_mode only) coexist
 * safely with this panel (all four) — and it holds even for a stale client.
 *
 * Note the pre-existing (C2) behaviour that the Settings panel and the top-nav toggle hold independent
 * local state until the redirect refreshes props; they converge on the `watch` below. G11 does not
 * change that.
 */
export function useAppearancePreference(): AppearancePreference {
    const page = usePage();
    const local = ref<UiTheme>({ ...page.props.ui.theme });

    // The PATCH redirects back(), so props refresh and every mounted consumer re-syncs.
    watch(
        () => page.props.ui.theme,
        (theme) => {
            local.value = { ...theme };
        },
        { deep: true },
    );

    function persist(field: string, value: string | boolean): void {
        router.patch(
            '/settings/appearance',
            { [field]: value },
            { preserveScroll: true, preserveState: true },
        );
    }

    return {
        mode: computed(() => local.value.mode),
        accent: computed(() => local.value.accent),
        fontSize: computed(() => local.value.fontSize),
        dyslexiaFont: computed(() => local.value.dyslexiaFont),

        setMode(next: ThemeMode): void {
            local.value.mode = next;
            applyThemeMode(next);
            persist('theme_mode', next);
        },
        setAccent(next: AccentToken): void {
            local.value.accent = next;
            applyAccent(next);
            persist('accent_token', next);
        },
        setFontSize(next: FontSizeScale): void {
            local.value.fontSize = next;
            applyFontSize(next);
            persist('font_size_scale', next);
        },
        setDyslexiaFont(next: boolean): void {
            local.value.dyslexiaFont = next;
            applyDyslexiaFont(next);
            persist('use_dyslexia_friendly_font', next);
        },
    };
}

/**
 * Mode-only facade, kept at its C2 signature so the top-nav quick toggle (exceptions-log #3) is
 * untouched by G11. One implementation, two entry points — the toggle physically cannot reach the
 * other three axes.
 */
export function useThemePreference(): {
    mode: ComputedRef<ThemeMode>;
    setMode: (mode: ThemeMode) => void;
} {
    const { mode, setMode } = useAppearancePreference();

    return { mode, setMode };
}
