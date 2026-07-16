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
    ) {}
}
