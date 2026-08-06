<!DOCTYPE html>
{{-- The 503 maintenance page (Increment I5, PRD Feature #10). ONE view for both halves of the feature —
     a tenant that has paused its public forms, and the platform in a maintenance window — because they
     say the same thing to the same audience and two templates would eventually say it differently.

     ── NO JAVASCRIPT, AND @vite(['resources/css/app.css']) RATHER THAN AN ENTRY OF ITS OWN ──────────────
     There is nothing to mount: no Inertia root, no SPA, no interaction. The CSS entry is a real one
     (vite.config.ts's input list), so this page is styled from the same tokens as everything else and
     Standing Rule 2's "one shared design system, no exceptions" holds without a new build target.

     Because there is no JS, there is no theme-mode attribute either — light/dark resolves from
     prefers-color-scheme through the design system's own tokens, exactly as the guest runtime shell does
     for a respondent who has no preferences to read.

     ── $brand IS OPTIONAL AND THE PARTIAL OWNS THE GUARD ───────────────────────────────────────────────
     A TENANT maintenance page is branded (it replaces a form the respondent expected to see branded);
     the PLATFORM one is not (there is no tenant to brand it with). The @include is unconditional and
     partials/brand-ramp handles the null — see that file's "callers own WHETHER, this file owns HOW".
     It must come AFTER @vite: the light block is :root, the same specificity as the base tokens it beats,
     so source order decides. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name', 'Meridian') }} — temporarily unavailable</title>
    @vite(['resources/css/app.css'])
    @include('partials.brand-ramp', ['tokens' => $brand ?? null])
    <style>
        .maintenance {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            min-height: 100dvh;
            padding: var(--mds-space-6);
            background-color: var(--mds-color-bg-canvas);
            font-family: var(--mds-font-family-body);
        }

        .maintenance__panel {
            max-width: 34rem;
            padding: var(--mds-space-8) var(--mds-space-6);
            border: 1px solid var(--mds-color-border-default);
            border-radius: var(--mds-radius-lg);
            background-color: var(--mds-color-bg-surface);
            text-align: center;
        }

        .maintenance__icon {
            width: 40px;
            height: 40px;
            margin-bottom: var(--mds-space-4);
            color: var(--mds-color-status-warning-fg);
        }

        .maintenance__title {
            margin: 0 0 var(--mds-space-3);
            font-family: var(--mds-font-family-display);
            font-size: var(--mds-type-heading-2-font-size);
            line-height: var(--mds-type-heading-2-line-height);
            font-weight: var(--mds-type-heading-2-font-weight);
            color: var(--mds-color-text-heading);
        }

        .maintenance__message {
            margin: 0;
            font-size: var(--mds-type-body-md-font-size);
            line-height: var(--mds-type-body-md-line-height);
            color: var(--mds-color-text-body);
        }
    </style>
</head>
<body>
    <main class="maintenance">
        <div class="maintenance__panel">
            <svg class="maintenance__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                <path
                    d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
            <h1 class="maintenance__title">Temporarily unavailable</h1>
            {{-- The message is tenant- or operator-authored free text. `{{ }}` escapes it; it is never
                 rendered as markup, and the request that stored it caps it at 500 characters. --}}
            <p class="maintenance__message">{{ $message }}</p>
        </div>
    </main>
</body>
</html>
