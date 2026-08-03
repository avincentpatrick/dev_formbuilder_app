<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Authorization\ResourceGrantResolver;

/**
 * How an analytics query bounds itself to a set of forms (ADR-0011 §D7(b), Increment H24a).
 *
 * D7 requires every analytics query to be form-bounded. {@see self::All} is spelled as an EXPLICIT mode
 * rather than an omitted parameter so that tenant-wide selection is a stated choice a reader can see in the
 * saved view's stored definition — `docs/PRD.md:197`'s org-wide criteria (total submissions this period vs
 * prior, the volume trend, top forms) require it, and an absent parameter meaning "everything" is the shape
 * that makes an unbounded query look like an oversight rather than a decision.
 *
 * Boundedness for `All` comes from the other two rules, which apply to every mode: the mandatory date range
 * with its maximum span, and the fixed-cardinality result.
 */
enum AnalyticsFormSelection: string
{
    /** Every form the requesting user may see. Bounded by the date range and the result cardinality. */
    case All = 'all';

    /**
     * An explicit list, capped at `AnalyticsQuery::MAX_FORM_IDS`. The cap is not decorative:
     * `ResourceGrantResolver`'s docblock records that PostgreSQL caps bind parameters at 65535, which is why
     * that class returns subqueries rather than materialized id arrays.
     */
    case Forms = 'forms';

    /**
     * A `scope_nodes` subtree (§D6's grouping axis). Resolved through
     * {@see ResourceGrantResolver::subtreeFormIdsQuery()}, so a deactivated node
     * and its whole branch are excluded — the same semantics `nodeReach()` reports with, chosen because an
     * analytics total that counted branches the authorization layer treats as gone would disagree with every
     * other number on the screen.
     */
    case ScopeNode = 'scope_node';
}
