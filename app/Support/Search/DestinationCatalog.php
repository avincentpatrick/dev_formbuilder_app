<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Support\Authorization\ShellAbilities;

/**
 * The navigable destinations global search can offer — "settings" in PRD §3.7's list (Increment J1c).
 *
 * ⚠️ THIS IS A STATIC CATALOG, NOT A QUERY OVER THE `settings` TABLE, AND THE DIFFERENCE IS THE POINT.
 * What a user means by searching "webhooks" or "dark mode" is *take me to that page*, not *show me the
 * stored value*. Searching stored settings values would also be a disclosure surface with no policy behind
 * it — `settings` holds platform and tenant configuration, some of it operational. So the rows below are
 * authored, and the only dynamic part is which of them this user may reach.
 *
 * ⚠️ THE GATES COME FROM {@see ShellAbilities}, THE SAME MAP THE SIDEBAR READS. Re-deriving them here is
 * how the sidebar and the palette start disagreeing about what a Viewer can reach — within about two
 * increments, on this codebase's own track record. `ShellAbilityParityTest` holds the two ends together.
 *
 * ⚠️ FEATURE GATES ARE THE NAV'S, NOT THE ROUTE'S, AND FOR ONE ROW THEY DELIBERATELY DIFFER. `/domains`
 * carries no `feature:` middleware on its read path (ADR-0012 §D9 — a tenant downgraded off Business keeps
 * a live hostname and must be able to take it down), but `nav-model.ts` still hides the nav item. This
 * catalog follows the NAV, so a downgraded tenant stops seeing "Domains" in search too; `/settings` still
 * links the page once the tenant holds a domain, so the path is not lost.
 */
final readonly class DestinationCatalog
{
    /**
     * `keywords` are extra search terms beyond the label — the words a user actually types when they do not
     * know the product's noun ("2fa" for Security, "dark mode" for Appearance, "people" for Members). They
     * are matched with the same `ILIKE` semantics as everything else, so multi-word queries narrow.
     *
     * @var list<array{key: string, label: string, url: string, keywords: string, ability: ?string, feature: ?string}>
     */
    private const array ROWS = [
        ['key' => 'forms', 'label' => 'Forms', 'url' => '/forms', 'keywords' => 'build questionnaire survey templates', 'ability' => 'manageForms', 'feature' => null],
        ['key' => 'submissions', 'label' => 'Submissions', 'url' => '/submissions', 'keywords' => 'inbox responses replies answers review', 'ability' => 'viewSubmissions', 'feature' => null],
        ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => '/dashboard', 'keywords' => 'home overview summary', 'ability' => null, 'feature' => null],
        ['key' => 'analytics', 'label' => 'Analytics', 'url' => '/analytics', 'keywords' => 'reports charts trends insights', 'ability' => 'viewAnalytics', 'feature' => 'advanced_analytics'],
        ['key' => 'members', 'label' => 'Members', 'url' => '/members', 'keywords' => 'people team invite roles permissions staff', 'ability' => 'manageMembers', 'feature' => null],
        ['key' => 'scopes', 'label' => 'Scopes', 'url' => '/scopes', 'keywords' => 'hierarchy regions offices areas branches', 'ability' => 'manageScopes', 'feature' => null],
        ['key' => 'audit', 'label' => 'Audit log', 'url' => '/audit-log', 'keywords' => 'history ledger compliance who changed', 'ability' => 'viewAuditLog', 'feature' => null],
        ['key' => 'feedback', 'label' => 'Feedback', 'url' => '/feedback', 'keywords' => 'bug report suggestion', 'ability' => 'viewFeedback', 'feature' => null],
        ['key' => 'webhooks', 'label' => 'Webhooks', 'url' => '/webhooks', 'keywords' => 'callbacks http endpoints deliveries zapier', 'ability' => 'manageWebhooks', 'feature' => 'webhooks'],
        ['key' => 'integrations', 'label' => 'Integrations', 'url' => '/integrations', 'keywords' => 'connectors sheets airtable slack google', 'ability' => 'manageIntegrations', 'feature' => 'native_connectors'],
        ['key' => 'domains', 'label' => 'Domains', 'url' => '/domains', 'keywords' => 'dns hostname cname custom url', 'ability' => 'manageDomains', 'feature' => 'custom_domain'],
        ['key' => 'settings', 'label' => 'Settings', 'url' => '/settings', 'keywords' => 'preferences workspace configuration options', 'ability' => null, 'feature' => null],
        ['key' => 'notifications', 'label' => 'Notifications', 'url' => '/notifications', 'keywords' => 'alerts bell activity', 'ability' => null, 'feature' => null],
    ];

    public function __construct(private EntitlementService $entitlements) {}

    /**
     * Every destination this user may actually reach.
     *
     * A row the user cannot reach is ABSENT, never greyed out or rendered as a locked upsell — the same
     * disclosure rule the arms follow, and ADR-0011 §D9's posture for plan-gated destinations (hidden, not
     * locked, because Business is held from sale and an upgrade CTA would point at a plan nobody can buy).
     *
     * @return list<array{key: string, label: string, url: string, keywords: string}>
     */
    public function visibleTo(User $user): array
    {
        $abilities = ShellAbilities::for($user);

        $rows = array_filter(
            self::ROWS,
            fn (array $row): bool => ($row['ability'] === null || ($abilities[$row['ability']] ?? false))
                && ($row['feature'] === null || $this->entitlements->feature($row['feature']))
        );

        return array_values(array_map(
            static fn (array $row): array => [
                'key' => $row['key'],
                'label' => $row['label'],
                'url' => $row['url'],
                'keywords' => $row['keywords'],
            ],
            $rows
        ));
    }
}
