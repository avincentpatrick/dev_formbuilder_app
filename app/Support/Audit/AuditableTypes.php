<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Support\Str;

/**
 * The `audits.auditable_type` alias catalog (audit-compliance-logging-spec §1) and its human labels —
 * the read-side counterpart to {@see AuditRedactor}, which keys its redaction maps off the same strings.
 * A pure, stateless lookup: no database, no framework state, exhaustively unit-testable.
 *
 * ── Why a FIXED catalog and not `SELECT DISTINCT auditable_type` (I2) ──────────────────────────────
 * Three reasons, in order of force:
 *
 *  1. A `distinct` makes a brand-new tenant's filter dropdown EMPTY, and makes the filter's contents a
 *     function of history — it silently gains options over time with no code change to review. Spec §1's
 *     table is normative and closed; this catalog mirrors the DOCUMENT, not the data.
 *  2. A label map has to exist regardless — `resource_grants` must render as "Access grant", not as
 *     `resource_grants`. A `distinct` supplies no labels, so the map exists either way, at which point
 *     the map IS the catalog and the query is redundant.
 *  3. Cost. `audits_tenant_auditable_idx` is `(tenant_id, auditable_type, auditable_id)` and Postgres has
 *     no loose index scan, so `SELECT DISTINCT auditable_type` is a full scan of the tenant's slice of an
 *     unbounded, never-pruned table — on every page render.
 *
 * {@see self::label()} therefore fails OPEN and {@see self::options()} fails closed, which is the correct
 * direction for a compliance log: an increment that starts writing a new alias gets a visible (if
 * un-prettified) row immediately, and only the *dropdown* lags until someone registers it here.
 */
final class AuditableTypes
{
    /**
     * Spec §1 alias ⇒ human label. Keep in sync with that table; it is the normative list.
     *
     * @var array<string, string>
     */
    private const array LABELS = [
        'form' => 'Form',
        'form_version' => 'Form version',
        'submission' => 'Submission',
        'settings' => 'Settings',
        'domain' => 'Custom domain',
        'audit_log' => 'Audit log',
        'webhook_endpoint' => 'Webhook endpoint',
        'connection' => 'Integration',
        'connection_subscription' => 'Integration rule',
        'sso_connection' => 'Single sign-on',
        'subscription' => 'Subscription',
        'tenant_users' => 'Membership',
        'resource_grants' => 'Access grant',
        'users' => 'User',
        'tenant' => 'Organization',
        'feedback_reports' => 'Feedback report',
    ];

    /**
     * Never null: an unregistered alias renders un-prettified rather than disappearing from the ledger.
     * A row whose type reads `scope_node` is worse than one that reads "Scope node" and far better than
     * one that reads nothing at all.
     */
    public static function label(string $alias): string
    {
        return self::LABELS[$alias] ?? Str::of($alias)->replace('_', ' ')->ucfirst()->toString();
    }

    /**
     * The filter dropdown catalog, alphabetical by label.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $labels = self::LABELS;
        asort($labels);

        $options = [];

        foreach ($labels as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
