<!DOCTYPE html>
{{-- Meridian public form-runtime shell (Increment F6b). A STANDALONE Vue 3 SPA mount point — NOT Inertia:
     guests have no session/subdomain/CSRF channel (architecture §8). The minted share token (+ form id/
     title/slug/locale) is embedded in the mount node's dataset; main.ts reads it once and the SPA drives
     the host-agnostic /api/v1/public schema + submit endpoints same-origin (the token carries the tenant).
     Guests have no user_ui_preferences, so the theme is the SYSTEM default — no data-theme-mode/data-accent
     is emitted and the design-system tokens resolve light/dark via prefers-color-scheme. --}}
<html lang="{{ str_replace('_', '-', $locale ?? app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $form['title'] }} · {{ config('app.name', 'Meridian') }}</title>
    {{-- Increment G8a — installable PWA. Per-form manifest (id/start_url/scope = this form's /f/{slug}), a
         theme colour, and home-screen icons; the service worker itself is registered from main.ts. --}}
    <meta name="theme-color" content="#1B5E5E">
    <link rel="manifest" href="/f/{{ $slug }}/manifest.webmanifest">
    <link rel="icon" type="image/png" href="/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon-180.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $form['title'] }}">
    @vite(['resources/public-runtime/main.ts'])
</head>
<body>
    <div id="app"
        data-share-token="{{ $shareToken }}"
        data-expires-at="{{ $expiresAt }}"
        data-form-id="{{ $form['id'] }}"
        data-form-title="{{ $form['title'] }}"
        data-form-slug="{{ $slug }}"
        data-default-locale="{{ $locale }}"
    ></div>
</body>
</html>
