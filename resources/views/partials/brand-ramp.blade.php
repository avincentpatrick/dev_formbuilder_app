{{-- The tenant brand ramp's CSS emission (ADR-0014 §D7) — shared by the Inertia admin root
     (`app.blade.php`, H23a3) and the public guest shell (`public-runtime.blade.php`, H23b).

     ── WHY THIS IS A PARTIAL AND NOT TWO BLOCKS ──────────────────────────────────────────────────
     The guest shell can never carry `data-theme-mode` (guests have no `user_ui_preferences`), so a
     guest-specific variant naming only `:root` looked obviously right and is WRONG: inside the media
     query a bare `:root` is (0,1,0) and LOSES to theme-overrides.css's own system-dark block at
     `:root:not([data-theme-mode='light']):not([data-theme-mode='dark'])` — (0,3,0). The third block
     below has to match that selector exactly to tie it and win on source order. One copy of that
     reasoning, in one file, is the whole point; two copies is the drift ADR-0014's Consequences
     section is a cautionary tale about. The second block is simply inert on the guest shell.

     ── CALLERS OWN *WHETHER*, THIS FILE OWNS *HOW* ───────────────────────────────────────────────
     `$tokens` is null when nothing should render branded, and the two callers reach that null by
     different routes: the admin root additionally withholds the ramp when the member has expressed an
     accent opinion (server-side precedence — see app.blade.php), while the guest shell has no
     personalization layer to lose to and emits whenever the tenant's brand is active. The `is_array`
     guard lives HERE so neither caller can forget it.

     ── ORDER AND ESCAPING ────────────────────────────────────────────────────────────────────────
     Must be included AFTER @vite: the light block is `:root`, the same (0,1,0) as the base token
     declarations it must beat, so source order is what decides. Every value is a stored,
     server-derived hex (never tenant text), so there is nothing to escape beyond what {{ }} does.

     Only these SIX properties, never a neutral, semantic or chart token — ADR-0014 §D7, and §D11's
     rule that a data series must look the same to two colleagues reading one screenshot.

     ── A FORM'S PRESET THEME ADDS ITS DOCUMENTED LINES, AND NOTHING ELSE (M131, `D65` = A) ─────────────
     On a form with a preset the guest shell passes `styleId` `form-theme` and the preset's `lines`: its font
     pair and radius scale, each one of `FormThemePreset::DOCUMENTED_PROPERTIES` — the set grows only by a
     property argued for and gated, never by "a documented superset". The admin root and the maintenance page
     pass neither, so their block is exactly the six, as before. ⚠️ The lines are emitted RAW (`{!! !!}`):
     inside `<style>` an HTML entity is not decoded, so an escaped quote would rename the font. They are
     server constants, never author text, and `FormThemePresetTest` holds every value to a character
     whitelist that cannot close a declaration, a rule or this element.

     @param  ?array<string, array<string, string>>  $tokens  theme => role => `#RRGGBB`
     @param  ?string  $styleId  the block's id: `tenant-brand` (default) or `form-theme`
     @param  ?array<string, string>  $lines  documented extra properties, property => value (presets only) --}}
@if (is_array($tokens))
    <style id="{{ $styleId ?? 'tenant-brand' }}">
        :root {
            --mds-color-action-primary-bg: {{ $tokens['light']['bg'] }};
            --mds-color-action-primary-bg-hover: {{ $tokens['light']['bg_hover'] }};
            --mds-color-action-primary-bg-active: {{ $tokens['light']['bg_active'] }};
            --mds-color-action-primary-fg: {{ $tokens['light']['fg'] }};
            --mds-color-action-primary-tint: {{ $tokens['light']['tint'] }};
            --mds-color-focus-ring: {{ $tokens['light']['ring'] }};
@foreach ($lines ?? [] as $property => $value)
            {!! $property !!}: {!! $value !!};
@endforeach
        }

        :root[data-theme-mode='dark'] {
            --mds-color-action-primary-bg: {{ $tokens['dark']['bg'] }};
            --mds-color-action-primary-bg-hover: {{ $tokens['dark']['bg_hover'] }};
            --mds-color-action-primary-bg-active: {{ $tokens['dark']['bg_active'] }};
            --mds-color-action-primary-fg: {{ $tokens['dark']['fg'] }};
            --mds-color-action-primary-tint: {{ $tokens['dark']['tint'] }};
            --mds-color-focus-ring: {{ $tokens['dark']['ring'] }};
        }

        @media (prefers-color-scheme: dark) {
            :root:not([data-theme-mode='light']):not([data-theme-mode='dark']) {
                --mds-color-action-primary-bg: {{ $tokens['dark']['bg'] }};
                --mds-color-action-primary-bg-hover: {{ $tokens['dark']['bg_hover'] }};
                --mds-color-action-primary-bg-active: {{ $tokens['dark']['bg_active'] }};
                --mds-color-action-primary-fg: {{ $tokens['dark']['fg'] }};
                --mds-color-action-primary-tint: {{ $tokens['dark']['tint'] }};
                --mds-color-focus-ring: {{ $tokens['dark']['ring'] }};
            }
        }
    </style>
@endif
