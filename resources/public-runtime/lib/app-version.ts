/**
 * The build's app version (Increment G8b) sent as `app_version` on every submission, so a record can be traced
 * to the client build that produced it (data-dictionary §7). Injected at build time via a `vite.config.ts`
 * `define` (a short git sha / package version, kept ≤20 chars for the column). Falls back to `'dev'` when the
 * define is absent (e.g. under Vitest, which does not apply the app build's define).
 */

declare const __APP_VERSION__: string;

export const APP_VERSION: string = typeof __APP_VERSION__ === 'string' ? __APP_VERSION__ : 'dev';
