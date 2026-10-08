<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Single-form OCR (M128 — docs/ocr-pipeline-design.md, PRD Feature #1)
|--------------------------------------------------------------------------
|
| A scanned or photographed copy of a form's printed blank (`docs/ocr-pipeline-design.md` §2.5) is read
| by a provider and matched back to the version it was printed from. This file holds the provider's
| credential and the numbers the reading path is built against. It follows `config/connectors.php`'s
| convention, a dedicated file rather than `config/services.php`, because the credential is PLATFORM-WIDE
| and never per-tenant (ADR-0009 §D9).
|
| >> THE PROVIDER IS GOOGLE CLOUD VISION UNTIL H1d DECIDES OTHERWISE. <<
|
| H1d, the bake-off that writes the reserved ADR-0010, chooses between Cloud Vision and Document AI on real
| samples. Until it does, the reading path is built against Cloud Vision's REST API with an API key, which
| is what the platform holds. ⚠️ A key whose Google Cloud project has no billing account attached is refused
| `403 BILLING_DISABLED` on every call, even inside the free tier — measured on 2026-10-03.
|
| >> THE THRESHOLDS ARE THE DESIGN'S DEFAULTS, NOT A CALIBRATION. <<
|
| 90 and 70 are `docs/ocr-pipeline-design.md` §3's "tunable-later default". H1d calibrates them on the
| samples, which is why they are configuration rather than constants.
|
*/

return [

    'provider' => 'google_vision',

    'google_vision' => [
        'key' => env('OCR_GOOGLE_VISION_KEY'),
        'endpoint' => 'https://vision.googleapis.com/v1',

        /*
        | Seconds. One request reads one page (or one PDF of at most `upload.max_pages` pages), and
        | the reading job reads ONE such request per run, so a run stays far under the queue's 120-second
        | `retry_after` on its own — Windows PHP has no `pcntl`, so `--timeout` cannot stop a hung job there.
        */
        'connect_timeout' => 5,
        'timeout' => 25,

        /*
        | Sent as `imageContext.languageHints` with every page (M149). `en-t-i0-handwrit` is Google's hint for
        | handwriting. On the bake-off's thirty phone photos it took fields needing correction from 57.2% to
        | 51.1%, silent errors from 1 to 0, and Cyrillic letters in Tagalog answers from 4 to 0, while every
        | printed label was still found. An empty list sends no hint.
        */
        'language_hints' => ['en-t-i0-handwrit'],
    ],

    /*
    | Runs of the reading job that may end without progress before the scan is marked failed: a provider
    | answer worth retrying (rate-limited, unavailable, a timeout) or a page still waiting for its virus
    | scan. Each such run releases the job with a growing delay, so five spans roughly half an hour.
    */
    'max_attempts' => 5,

    /*
    | Per-field confidence tiers on a 0–100 scale (`docs/ocr-pipeline-design.md` §3). At or above `auto` a
    | value is filled and not flagged; at or above `review` it is filled and flagged; below `review` it is
    | left blank and marked for manual entry, because a wrong value a reviewer clicks past is worse than an
    | obviously empty one.
    */
    'confidence' => [
        'auto' => 90,
        'review' => 70,
    ],

    /*
    | What one scan may be. The type allowlist is checked against the CONTENT-SNIFFED type, never the
    | client's header. A page is sent to the provider base64-encoded inside JSON, whose request cap is
    | 10 MB, so one file stays under 7,000,000 bytes. A whole scan stays under 25,000,000 bytes because the
    | testing server's `post_max_size` is 30M (`docs/deployment-infrastructure.md` §8, step 1). A PDF is read
    | inline, which the provider allows for at most five pages.
    */
    'upload' => [
        'accepted_types' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        'max_bytes_per_file' => 7_000_000,
        'max_bytes_per_scan' => 25_000_000,
        'max_pages' => 5,
    ],

];
