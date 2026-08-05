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
    // NULL means "no opinion" since H23a3 — NOT Blueprint. It is what makes a tenant brand apply, and it
    // is why this is read raw rather than through AccentToken::fromColumn(), which collapses the two.
    $themeAccent = $theme['accent'] ?? null;
    $themeFontSize = $theme['fontSize'] ?? null;
    $themeDyslexiaFont = (bool) ($theme['dyslexiaFont'] ?? false);

    // ── TENANT BRAND RAMP (H23a3, ADR-0014) ──────────────────────────────────────────────────────────
    // PRECEDENCE IS RESOLVED HERE, ON THE SERVER, AND DELIBERATELY NOT IN CSS.
    //
    // The CSS-specificity route was designed first and rejected on inspection: the tenant ramp has to
    // re-point the same six custom properties in BOTH themes, so it needs a `:root` block, a
    // `[data-theme-mode='dark']` block and a prefers-color-scheme twin — and those land at (0,1,0),
    // (0,2,0) and (0,3,0). The user's own `:root[data-accent='teal']` rule is (0,2,0), so it would TIE
    // with the tenant's dark block and the winner would fall to source order. That is exactly the bug
    // G11 already shipped once (see theme-overrides.css's note on why the teal dark payload exists), and
    // "personalization wins over tenant branding" is too load-bearing to rest on a tie-break.
    //
    // So the server decides instead, and the rule is trivially checkable: EMIT THE TENANT RAMP ONLY WHEN
    // THE USER HAS EXPRESSED NO ACCENT OPINION. A user who picked teal gets teal's rules over the base
    // tokens, exactly as before this increment. A user who picked blueprint gets the base tokens — which
    // ARE blueprint — so no restoring rule has to exist. Personalization cannot lose, because when it is
    // present the thing it would have to beat was never emitted.
    $brandTokens = $themeAccent === null ? ($page['props']['ui']['brand'] ?? null) : null;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    @if (in_array($themeMode, ['light', 'dark'], true)) data-theme-mode="{{ $themeMode }}" @endif
    {{-- A whitelist rather than the single `=== 'teal'` literal this used to carry: the comment above
         already promised one, and 'blueprint' is now a storable value that must not reach the attribute
         (it is the ABSENCE of data-accent, per §2.9's absence-as-default convention). --}}
    @if (in_array($themeAccent, ['teal'], true)) data-accent="{{ $themeAccent }}" @endif
    @if (in_array($themeFontSize, ['large', 'extra_large'], true)) data-font-size="{{ $themeFontSize }}" @endif
    @if ($themeDyslexiaFont) data-dyslexia-font="true" @endif
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'Meridian') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    {{-- The tenant brand ramp, AFTER @vite deliberately — see the partial for why order and selector
         specificity are load-bearing. Shared verbatim with the guest shell since H23b: this template
         decides WHETHER (the precedence rule above), the partial decides HOW. --}}
    @include('partials.brand-ramp', ['tokens' => $brandTokens])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
