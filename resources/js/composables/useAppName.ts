import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

/**
 * The product's name as a person reads it — M252 (`D236`): "iConnect Collection", and "iConnect" where only a few letters
 * fit (the phone-size top bar, a badge). ONE SOURCE: `config('app.name')` / `config('app.short_name')`, shared on every
 * page by `HandleInertiaRequests` (`app.name`, `app.short_name`), so the box's `APP_NAME` renames every surface at
 * once without a rebuild. The two constants below are only what a page reads before the props exist (a unit test, or
 * a module evaluated ahead of the first page) — they are not a second place to change the name.
 *
 * ⚠️ The name is DISPLAY ONLY. Every identifier that still says `meridian` — the `meridian-session` cookie, IndexedDB
 * `meridian-offline`, the `meridian:*` storage keys, `X-Meridian-Challenge` — stays, because something already
 * deployed or already on a tester's device reads it back by that name.
 */
export const APP_NAME_FALLBACK = 'iConnect Collection';
export const APP_SHORT_NAME_FALLBACK = 'iConnect';

/** The full and the short product name, reactive to the page props. */
export function useAppName(): { name: ComputedRef<string>; shortName: ComputedRef<string> } {
    const page = usePage();

    return {
        name: computed(() => page.props?.app?.name || APP_NAME_FALLBACK),
        shortName: computed(() => page.props?.app?.short_name || APP_SHORT_NAME_FALLBACK),
    };
}

/**
 * The full product name, read once — for a plain module (a client's error text, a default title) that builds a string
 * outside any component. `usePage()` is safe to call there: before the first page it answers with no props, and the
 * fallback is used.
 */
export function currentAppName(): string {
    return usePage().props?.app?.name || APP_NAME_FALLBACK;
}
