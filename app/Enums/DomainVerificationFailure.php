<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Tenancy\DnsTxtResolver;

/**
 * Why the last DNS verification attempt for a custom domain did not succeed (H22a / ADR-0012).
 *
 * Recorded on `domains.verification_failure_reason` so the H22b admin surface can tell a tenant what to fix
 * without re-running a lookup, and so `LookupFailed` stays distinguishable from a real negative.
 *
 * THE `LookupFailed` / `NotFound` SPLIT IS LOAD-BEARING and mirrors the null-versus-empty-array contract on
 * {@see DnsTxtResolver::txt()}. `NotFound` is a definitive "the zone published nothing
 * at that name"; `LookupFailed` is "we could not ask" (SERVFAIL, timeout, a dead resolver). Only the former
 * is evidence about the tenant. Collapsing them would let one upstream DNS outage expire every pending claim
 * in a single sweep.
 */
enum DomainVerificationFailure: string
{
    /** The name resolved, but no TXT record was published there. The usual "not propagated yet" state. */
    case NotFound = 'not_found';

    /** TXT records exist at the challenge name, but none of them carries this row's token. */
    case Mismatch = 'mismatch';

    /** The lookup itself failed. Says nothing about the tenant, and never counts against the claim. */
    case LookupFailed = 'lookup_failed';
}
