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
