<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\DomainVerificationFailure;
use App\Support\Webhooks\OutboundUrlGuard;

/**
 * TXT-record lookup for custom-domain verification (H22a / ADR-0012).
 *
 * An interface rather than a direct `dns_get_record()` call for one reason: DNS is not HTTP, so
 * `Http::fake()` cannot reach it, and there is no other way to test the verification sweep. This is the
 * same "third-party I/O behind a contract" shape the connector providers use.
 *
 * DELIBERATELY TXT-ONLY. A general `records(string $host, int $type)` would drag PHP's `dns_get_record`
 * result-array shape into the contract and make every fake carry format knowledge it has no business
 * having. One question, one method.
 *
 * ⚠️ NOT the same question {@see OutboundUrlGuard} asks, and deliberately not
 * unified with it. That class resolves A records to answer "is this address public" as an SSRF control on
 * a security boundary; this one asks "did the tenant publish our token". They share the word DNS and
 * nothing else, and folding them together would widen a security-critical interface for no benefit. See
 * ADR-0012 so the next author does not "unify" them.
 */
interface DnsTxtResolver
{
    /**
     * The TXT strings published at `$name`.
     *
     * THE NULL/EMPTY DISTINCTION IS LOAD-BEARING and is mirrored by
     * {@see DomainVerificationFailure::LookupFailed} versus {@see DomainVerificationFailure::NotFound}:
     *
     *   []    a definitive answer — the zone published nothing at that name. Evidence about the tenant.
     *   null  the lookup itself failed (SERVFAIL, timeout, a dead resolver). Evidence about US, not them.
     *
     * Collapsing the two would let a single upstream DNS outage expire every pending claim in one sweep.
     *
     * @return list<string>|null
     */
    public function txt(string $name): ?array;
}
