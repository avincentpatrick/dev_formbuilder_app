<!DOCTYPE html>
{{-- Meridian public form-runtime shell (Increment F6b). A STANDALONE Vue 3 SPA mount point — NOT Inertia:
     guests have no session/subdomain/CSRF channel (architecture §8). The minted share token (+ form id/
     title/slug/locale) is embedded in the mount node's dataset; main.ts reads it once and the SPA drives
     the host-agnostic /api/v1/public schema + submit endpoints same-origin (the token carries the tenant).

     Guests have no user_ui_preferences, so the theme is the SYSTEM default — no data-theme-mode/data-accent
     is emitted and the design-system tokens resolve light/dark via prefers-color-scheme. That is STILL true
     after H23b: tenant branding and user personalization are two layers serving two audiences (ADR-0014
     §D1), and only the tenant layer reaches a respondent. GuestRuntimeTest pins the absence. --}}
<html lang="{{ str_replace('_', '-', $locale ?? app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $form['title'] }} · {{ config('app.name', 'Meridian') }}</title>
    {{-- Increment G8a — installable PWA. Per-form manifest (id/start_url/scope = this form's /f/{slug}), a
         theme colour, and home-screen icons; the service worker itself is registered from main.ts.

         H23b — `theme-color` is the tenant's light primary fill, falling back to the product's own
         (`--mds-primary-600`). It was a hard-coded `--mds-accent-teal-600` until now, on a surface that has
         never emitted `data-accent` and therefore never rendered teal — see GuestBrandingPresenter.

         ONE tag, light arm only. A `media="(prefers-color-scheme: dark)"` twin is supportable in the
         browser but the manifest below has no per-scheme arm, so the installed app and the tab strip would
         then disagree in dark mode. One answer everywhere beats a better answer in one place.

         `?b=` is the manifest's CACHE-INVALIDATION lever: the SW never caches this URL (no route matches
         it) and the browser caches it aggressively, so a brand change has to move the URL to be seen. Safe
         for app identity — the manifest declares an explicit `id` (`/f/{slug}`), so the manifest URL is not
         what pins an installed app, and a changed query string cannot fork one into two. --}}
    <meta name="theme-color" content="{{ $brand['theme_color'] }}">
    <link rel="manifest" href="/f/{{ $slug }}/manifest.webmanifest?b={{ $brand['version'] }}">
    <link rel="icon" type="image/png" href="/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon-180.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $form['title'] }}">
    @vite(['resources/public-runtime/main.ts'])
    {{-- H23b — the tenant ramp, emitted unconditionally when the tenant's brand is ACTIVE. There is no
         precedence rule to apply here (the admin root has one): a respondent has no preferences for the
         tenant's brand to lose to. --}}
    @include('partials.brand-ramp', ['tokens' => $brand['tokens']])
</head>
<body>
    {{-- data-resume-token (Increment H9b) is present only when the shell was opened from a save-and-resume
         link; the SPA reads it to restore the saved draft answers (H10). Empty on a first-time fill.

         data-brand-version (H23b) is the fingerprint of the ramp THIS document was rendered with. The SPA
         compares it against the value it persisted in IndexedDB to notice that other, previously-cached
         guest shells on this device are still showing a superseded brand — see lib/brand-cache.ts. --}}
    <div id="app"
        data-share-token="{{ $shareToken }}"
        data-resume-token="{{ $resumeToken ?? '' }}"
        data-expires-at="{{ $expiresAt }}"
        data-form-id="{{ $form['id'] }}"
        data-form-title="{{ $form['title'] }}"
        data-form-slug="{{ $slug }}"
        data-default-locale="{{ $locale }}"
        data-brand-version="{{ $brand['version'] }}"
    ></div>
</body>
</html>
