<!DOCTYPE html>
{{-- Meridian authenticated app root template. Theme: absence of data-theme-mode = "system"
     (resolves via prefers-color-scheme); Increment C emits data-theme-mode/data-accent from user_ui_preferences. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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
