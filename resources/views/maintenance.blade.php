{{-- The 503 maintenance page (Increment I5, PRD Feature #10). ONE view for both halves of the feature —
     a tenant that has paused its public forms, and the platform in a maintenance window — because they
     say the same thing to the same audience and two templates would eventually say it differently. Since
     M96 its document lives in partials/maintenance-page, which the deploy window's page shares.

     ── NO JAVASCRIPT, AND @vite(['resources/css/app.css']) RATHER THAN AN ENTRY OF ITS OWN ──────────────
     There is nothing to mount: no Inertia root, no SPA, no interaction. The CSS entry is a real one
     (vite.config.ts's input list), so this page is styled from the same tokens as everything else and
     Standing Rule 2's "one shared design system, no exceptions" holds without a new build target. The
     call below is exactly what the @vite directive compiles to, so withoutVite() still silences it.

     Because there is no JS, there is no theme-mode attribute either — light/dark resolves from
     prefers-color-scheme through the design system's own tokens, exactly as the guest runtime shell does
     for a respondent who has no preferences to read.

     $brand is optional; the partial passes it on to partials/brand-ramp, which owns the null guard. --}}
@include('partials.maintenance-page', [
    'styles' => app(\Illuminate\Foundation\Vite::class)(['resources/css/app.css']),
    'brand' => $brand ?? null,
    'message' => $message,
])
