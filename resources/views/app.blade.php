<!DOCTYPE html>
{{-- Meridian app root template. Theme is emitted server-side from the shared Inertia prop
     (HandleInertiaRequests → user_ui_preferences). Absence of data-theme-mode = "system"
     (resolves via prefers-color-scheme); default "blueprint" accent emits no data-accent. --}}
@php
    $theme = $page['props']['ui']['theme'] ?? [];
    $themeMode = $theme['mode'] ?? null;
    $themeAccent = $theme['accent'] ?? null;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    @if (in_array($themeMode, ['light', 'dark'], true)) data-theme-mode="{{ $themeMode }}" @endif
    @if ($themeAccent === 'teal') data-accent="teal" @endif
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
