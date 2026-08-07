<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Upload size ceiling
    |--------------------------------------------------------------------------
    |
    | The hard maximum for any single uploaded file, in kilobytes — enforced as
    | the `max:` rule on StoreAttachmentRequest before any bytes are stored. A
    | media field's own `max_file_size_bytes` config may lower this per field,
    | but never raise it. (A per-tenant/plan storage quota is a later concern —
    | threat-model §6; there is no `usage_counters` table yet.)
    |
    */

    'max_upload_kb' => (int) env('ATTACHMENT_MAX_UPLOAD_KB', 25 * 1024), // 25 MB

    /*
    |--------------------------------------------------------------------------
    | Default accepted MIME types per media field type
    |--------------------------------------------------------------------------
    |
    | Applied when a field sets no `accepted_types` of its own. Keyed by the
    | FieldType backing value. A `*` glob suffix (e.g. `image/*`) matches by
    | prefix. An empty list means "any type" (subject only to the size ceiling)
    | — the deliberate default for a generic `file_upload`.
    |
    */

    'default_accepted_types' => [
        'image_capture' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'audio_capture' => ['audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav'],
        'video_capture' => ['video/webm', 'video/mp4', 'video/ogg'],
        'signature' => ['image/png', 'image/jpeg'],
        'file_upload' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant brand logo (H23a2 / ADR-0014)
    |--------------------------------------------------------------------------
    |
    | Its own allowlist rather than a reuse of `default_accepted_types`, because
    | a brand logo answers to a different threat model than a respondent's file:
    | it is served to EVERY respondent of EVERY branded form, same-origin.
    |
    | SVG IS DELIBERATELY EXCLUDED. An SVG is an XML document that can carry
    | <script> and event handlers, so a same-origin SVG logo is stored XSS
    | (threat-model §6) — the one upload on this surface that an attacker with
    | Owner/Admin access could turn against their own respondents. Raster only.
    | GIF is excluded too, for a duller reason: an animated logo on every form
    | header is a WCAG 2.2.2 problem nobody would think to check.
    |
    | 1 MB is generous for a logo (a 512px PNG is typically under 50 KB) and is
    | two orders of magnitude below the 25 MB global ceiling, which exists for
    | respondent video.
    |
    */

    'branding_logo' => [
        'accepted_types' => ['image/png', 'image/jpeg', 'image/webp'],
        'max_bytes' => 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Feedback screenshot (I7a / PRD Feature #11)
    |--------------------------------------------------------------------------
    |
    | Its own allowlist for the same reason the brand logo has one: a feedback
    | screenshot answers to a different threat model than a respondent's file.
    |
    | SVG IS DELIBERATELY EXCLUDED, for the branding argument exactly — an SVG
    | is an XML document that can carry <script>, and this image is rendered
    | back into the PLATFORM OPERATOR's own console, same-origin, on the
    | central host. That makes it the highest-value stored-XSS target in the
    | product: the one viewer who is a super-admin on every tenant. GIF is
    | excluded too (an animated screenshot is noise, and WCAG 2.2.2).
    |
    | 4 MB against the logo's 1 MB: this is a full-viewport raster, not a mark.
    | A 1600px-wide PNG of a dense screen lands around 1–2 MB, so 4 MB is
    | headroom rather than a target — and still six times under the 25 MB
    | global ceiling, which exists for respondent video. The client downscales
    | to 1600px before encoding; this cap is the server's independent check,
    | since the client's is advice and not enforcement.
    |
    */

    'feedback_screenshot' => [
        'accepted_types' => ['image/png', 'image/jpeg', 'image/webp'],
        'max_bytes' => 4 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Virus scanning
    |--------------------------------------------------------------------------
    |
    | Phase-1 ships with no scanner wired. ScanAttachmentJob transitions a
    | freshly-uploaded `pending` row to `skipped` (servable, but explicitly
    | "not scanned") when scanning is disabled; a real ClamAV integration later
    | flips this to true and transitions `pending → clean|infected`.
    |
    */

    'scanner_enabled' => (bool) env('ATTACHMENT_SCANNER_ENABLED', false),

];
