<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Models\Submission;
use App\Services\Validation\SemanticResult;

/**
 * The outcome of {@see SubmissionPipeline::submit()}. `created` distinguishes a fresh persist (HTTP
 * `201`) from an idempotent no-op replay of an already-seen `client_submission_uuid` (HTTP `200`,
 * technical-architecture.md §4.1 — "a duplicate replay is a successful no-op, not an error"). `semantic`
 * is the Stage-3 result for a fresh submission (null on the idempotent no-op path, which short-circuits
 * before Stage 3). Immutable.
 */
final readonly class SubmissionResult
{
    public function __construct(
        public Submission $submission,
        public bool $created,
        public ?SemanticResult $semantic = null,
        // Increment P3a — the `answers_content_checksum` this call actually wrote, handed straight back so a
        // draft channel can return the saving device's NEXT lost-update baseline without re-reading the row
        // it just wrote (draft autosave fires repeatedly while someone types, so the spared query is the
        // point). Null on every path that does not write an answer document, and on the pipeline's
        // idempotent no-op replay.
        public ?string $contentChecksum = null,
    ) {}
}
