<!DOCTYPE html>
{{-- Meridian app root template. The four personalization axes (design-system-reference.md §2.9) are
     emitted server-side, ONCE, from the shared Inertia prop (HandleInertiaRequests →
     user_ui_preferences) — never recomputed per component. In every axis the product default is the
     ABSENCE of the attribute: "system" mode resolves via prefers-color-scheme, "blueprint" accent and
     "standard" text size are the base token values, and dyslexia-off leaves the body face alone. That
     convention is why an unauthenticated visitor and a user who never opened Settings render byte
     identically, and why the opt-in web font is never fetched for them.

     Each value is matched against a whitelist rather than interpolated blind, so a corrupted or
     hand-edited row can never inject an arbitrary attribute value into the document. --}}
@php
    $theme = $page['props']['ui']['theme'] ?? [];
    $themeMode = $theme['mode'] ?? null;
    $themeAccent = $theme['accent'] ?? null;
    $themeFontSize = $theme['fontSize'] ?? null;
    $themeDyslexiaFont = (bool) ($theme['dyslexiaFont'] ?? false);
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    @if (in_array($themeMode, ['light', 'dark'], true)) data-theme-mode="{{ $themeMode }}" @endif
    @if ($themeAccent === 'teal') data-accent="teal" @endif
    @if (in_array($themeFontSize, ['large', 'extra_large'], true)) data-font-size="{{ $themeFontSize }}" @endif
    @if ($themeDyslexiaFont) data-dyslexia-font="true" @endif
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'Meridian') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
