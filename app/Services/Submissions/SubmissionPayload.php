<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\SubmissionSource;
use App\Models\FormVersion;

/**
 * The one normalised input every ingest channel hands the {@see SubmissionPipeline}
 * (technical-architecture.md §4.1). A channel adapter (manual encode in F4b; guest link in F5; OCR /
 * offline-sync / API import later) builds this and calls the pipeline — the adapter itself never
 * validates. Manual encoding populates `version` + `answers` + `source` + `respondentUserId` and leaves
 * the guest/offline provenance fields null; those are wired by the later channels.
 *
 * `version` is the already-resolved published version (the caller resolves `current_published_version_id`);
 * the pipeline re-asserts it is published (Stage 2) defensively.
 */
final readonly class SubmissionPayload
{
    /**
     * @param  array<string, mixed>  $answers  raw answers as the channel received them: field key => value, plus
     *                                         (Increment G1) repeatable-section key => list<field key => value>
     *                                         for repeat-group instances
     */
    public function __construct(
        public FormVersion $version,
        public array $answers,
        public SubmissionSource $source,
        public ?string $respondentUserId = null,
        public ?string $clientSubmissionUuid = null,
        public ?string $locale = null,
        public ?string $guestToken = null,
        public ?string $guestIp = null,
        public ?string $guestUserAgent = null,
        public ?string $guestContactEmail = null,
        // Increment G8b — device provenance for the offline-sync + guest channels (data-dictionary §7).
        public ?string $deviceId = null,
        public ?string $appVersion = null,
        // Increment H10 — draft-only: the resume cursor (the SPA's current step key, null for single-page /
        // non-draft channels) and the tenant-configured expiry window in days (null ⇒ the 30-day default).
        // Both are consumed only by the draft substrate; the finalize pipeline ignores them.
        public ?string $draftCurrentStep = null,
        public ?int $ttlDays = null,
        // ── Increment P3a — the DRAFT channel's lost-update token (offline-first-sync-design.md §8) ────────
        // `baseContentChecksum` is the `answers_content_checksum` the SAVING DEVICE last saw for this draft —
        // from the resume response or its own previous save — i.e. the state its edit is based on. The guard
        // fires only when the stored checksum has moved since, which means another device wrote in between.
        //
        // ⚠️ THE FLAG IS SEPARATE FROM THE VALUE, and that is deliberate rather than defensive: a legacy
        // draft's stored checksum is legitimately NULL (the column is nullable, added in
        // 2026_07_16_000001), so `$baseContentChecksum !== null` cannot mean "the client made a claim".
        // Only a caller that actually has a page/session behind it sets `checkBaseline`. This mirrors
        // {@see SubmissionAnswerEditService::edit()}, whose docblock records the same reasoning for the same
        // column on the sibling channel.
        public bool $checkBaseline = false,
        public ?string $baseContentChecksum = null,
    ) {}
}
