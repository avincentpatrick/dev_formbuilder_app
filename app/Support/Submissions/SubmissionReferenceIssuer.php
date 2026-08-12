<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Forms\FormSlug;
use App\Support\Tenancy\DnsTxtResolver;

/**
 * Issues the code that goes into `submissions.reference` (Increment J2e).
 *
 * ⚠️ AN INTERFACE FOR ONE REASON: A COLLISION IS OTHERWISE UNTESTABLE. At 32^8 ≈ 1.1e12 codes, forcing two
 * `mint()` calls to agree by chance is not something a test can do — so the retry path that recovers from a
 * collision would ship unexercised, which for a path that only ever runs in the rare case is the same as
 * shipping it broken. Binding a scripted issuer makes the collision deterministic. This is the
 * {@see DnsTxtResolver} seam applied to randomness instead of to DNS.
 *
 * ⚠️ IT DOES NOT PROBE FOR UNIQUENESS, AND THAT IS THE DESIGN — the obvious "mint, SELECT, re-mint if taken"
 * loop (the {@see FormSlug::suggest()} shape) is WRONG here for a reason `FormSlug` never
 * faces. Both production writers create their row INSIDE an open transaction
 * ({@see SubmissionPipeline::persist()} and
 * {@see SubmissionDraftService::createDraft()}), so:
 *
 *   - the probe would be a TOCTOU read that proves nothing about a concurrent inserter, and
 *   - by the time the unique index raises 23505 the surrounding PostgreSQL transaction is in ERROR state, so
 *     re-minting and re-inserting inside it is impossible — every later statement fails 25P02.
 *
 * A probe's attempts are therefore probes, not inserts: it lowers the odds and cannot recover. The recovery
 * lives at the TRANSACTION boundary in both writers, where the whole closure is re-run and a fresh model
 * instance calls this again. Keeping the probe out of here is what makes that the only mechanism.
 */
interface SubmissionReferenceIssuer
{
    /** A code for a submission about to be inserted. Uniqueness is the database's job, not this method's. */
    public function issue(): string;
}
