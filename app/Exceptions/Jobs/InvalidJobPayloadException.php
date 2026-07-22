<?php

declare(strict_types=1);

namespace App\Exceptions\Jobs;

use App\Jobs\TenantAwareJob;
use App\Models\Concerns\BelongsToTenant;
use RuntimeException;

/**
 * A queued job reached the worker with a payload that cannot be trusted to scope itself to a tenant
 * (ADR-0007 §D2). This is the "fails fast (explicit exception)" that ADR-0002 §D3 (`:125`) promised
 * and §D6 (`:151`) required, and which — until H2 — did not exist: a context-less job silently
 * inherited the previous job's process-lifetime static and then failed obscurely against RLS.
 *
 * Thrown by {@see TenantAwareJob::handle()} BEFORE any tenant-scoped query runs, so a malformed
 * payload can never reach the database with an ambiguous context.
 *
 * Registered with `$exceptions->dontRetry()` in bootstrap/app.php: a payload is immutable once
 * enqueued, so retrying a malformed one three times only multiplies the noise. It goes straight to
 * `failed_jobs` on the first attempt.
 *
 * The offending value is truncated in the message. `failed_jobs` is an RLS-FREE table (ADR-0007 §D12),
 * so anything written here is readable outside any tenant boundary — keep it short and non-sensitive.
 */
final class InvalidJobPayloadException extends RuntimeException
{
    private const int MAX_ECHOED_LENGTH = 64;

    public static function missingTenantId(string $job): self
    {
        return new self("{$job} reached the worker with an empty tenantId; every TenantAwareJob payload must carry one (ADR-0007 §D2).");
    }

    public static function malformedTenantId(string $job, string $value): self
    {
        return new self("{$job} carries a tenantId that is not a uuid: '".self::echoable($value)."' (ADR-0007 §D2).");
    }

    /**
     * The all-zeroes uuid is {@see BelongsToTenant}'s NO_TENANT_SENTINEL — the
     * value the global scope substitutes when there is no context. A job carrying it is a job that
     * serialized an absent tenant, which is precisely the bug this class exists to surface.
     */
    public static function sentinelTenantId(string $job): self
    {
        return new self("{$job} carries the no-tenant sentinel uuid as its tenantId, meaning it was dispatched with no tenant context (ADR-0007 §D2).");
    }

    private static function echoable(string $value): string
    {
        return mb_strlen($value) > self::MAX_ECHOED_LENGTH
            ? mb_substr($value, 0, self::MAX_ECHOED_LENGTH).'…'
            : $value;
    }
}
