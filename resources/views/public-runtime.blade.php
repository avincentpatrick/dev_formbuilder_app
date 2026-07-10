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
