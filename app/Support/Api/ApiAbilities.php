<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * The Sanctum "API key" ability catalog (api-specification.md §2.6) and its mapping onto the RBAC
 * permission catalog (multi-tenancy-rbac-design.md §5 / {@see RolePermissionSeeder}).
 *
 * Token abilities are a stable, integration-facing vocabulary; each maps to the RBAC permission(s) that
 * entitle a user to hold it. A minted key is intersected against the issuer's own permissions
 * ({@see self::intersect()}), so a key can never be broader than its issuer — and per-request the
 * `ability:` middleware plus the FormPolicy `can:` gate still both run on top.
 *
 * Only `read:forms` / `write:forms` are wired to routes by the Increment-E scaffold; the remaining
 * abilities are defined so this stays the single source of truth as Phase-1 resources land.
 */
final class ApiAbilities
{
    public const READ_FORMS = 'read:forms';

    public const WRITE_FORMS = 'write:forms';

    public const READ_SUBMISSIONS = 'read:submissions';

    public const WRITE_SUBMISSIONS = 'write:submissions';

    public const REVIEW_SUBMISSIONS = 'review:submissions';

    public const EXPORT_SUBMISSIONS = 'export:submissions';

    public const MANAGE_WEBHOOKS = 'manage:webhooks';

    public const MANAGE_SETTINGS = 'manage:settings';

    public const READ_AUDIT_LOG = 'read:audit_log';

    /**
     * Authoring the tenant's scoping hierarchy AND granting access on it (Increment G10b).
     *
     * G10a deliberately left this decision open, because `scopes.manage` was added as a NEW permission
     * rather than folded into `tenant.settings.manage` specifically so already-minted `manage:settings`
     * tokens would not retroactively gain authority over authorization structure. A new ability keeps that
     * property: no existing token carries it, since none was minted with it.
     *
     * One ability covering both `scopes.manage` and `forms.collaborators.manage`, not two. Note the map's
     * semantics — holding ANY one of the listed permissions grants the ability — so on its own this would
     * let a `scopes.manage`-only principal mint a token that reaches the grant routes. What makes that
     * safe is that the ability is a token SCOPE, never the authorization: every route below also carries
     * its own `can:` policy gate, which re-checks the acting user's real permissions. A principal without
     * `forms.collaborators.manage` is refused there regardless of what their token says.
     */
    public const MANAGE_SCOPES = 'manage:scopes';

    /**
     * Managing the tenant's native-connector OAuth grants and their delivery rules (H15a / ADR-0009).
     *
     * A NEW ability mapped to a NEW permission, for the same reason `manage:scopes` is: `manage:webhooks`
     * tokens have already been minted, and folding connections into `webhooks.manage` would retroactively
     * grant every one of them authority over credentials that let the platform act inside the tenant's Slack
     * workspace — an authority no issuer of those tokens agreed to. A new ability cannot be held retroactively,
     * because no token was ever minted with it.
     */
    public const MANAGE_INTEGRATIONS = 'manage:integrations';

    /**
     * Reading cross-form analytics — aggregates, saved report views and the analytics export (H24a /
     * ADR-0011 §D9).
     *
     * A NEW ability, never a widening of `read:submissions` or `export:submissions`, for the third time and
     * the same reason: folding analytics into an existing ability would retroactively grant every
     * already-minted token the power to read tenant-wide aggregates, which no issuer of those tokens agreed
     * to. A new ability cannot be held retroactively, because no token was ever minted with it.
     *
     * It differs from `manage:scopes` and `manage:integrations` in one respect worth recording: it needs
     * **no new RBAC permission**. `dashboard.org.view` and `dashboard.form.view` are already seeded and
     * already in the role matrix, and the org-wide-versus-own-forms split they encode IS the visibility
     * split an analytics read needs — so this maps onto existing permissions instead of coining a thirtieth.
     *
     * One consequence of the any-of semantics, stated rather than discovered: a **Viewer** holds
     * `dashboard.org.view`, so a Viewer can mint an analytics token and read org-wide numbers. That makes
     * this the broadest-issuable read ability in the catalog. Intended — a Viewer already sees every
     * submission in the inbox — but it is the reason the analytics routes ALSO carry a `can:` policy gate
     * and the `feature:advanced_analytics` plan gate.
     */
    public const READ_ANALYTICS = 'read:analytics';

    /**
     * Managing the tenant's custom domains — claim, verify, release (H22a / ADR-0012).
     *
     * A NEW ability, never a widening of `manage:settings`, for the fourth time and the sharpest instance
     * of the same reason: an already-minted `manage:settings` token would otherwise retroactively gain the
     * power to point the hostname a tenant's respondents visit at somewhere else. No issuer of those
     * tokens agreed to that, and a new ability cannot be held retroactively because no token was ever
     * minted with it.
     *
     * Like `read:analytics`, it needs NO new RBAC permission. `tenant.settings.manage` is already seeded,
     * already in the role matrix, and its audience (Owner + Admin) is exactly the audience that should be
     * able to move a tenant's domain — so this maps onto it rather than coining a thirtieth permission and
     * touching RolePermissionSeeder, the 5xN role matrix and multi-tenancy-rbac-design.md §5 for nothing.
     *
     * ACTIVATION IS DELIBERATELY NOT REACHABLE THROUGH ANY ABILITY. Putting a verified domain into service
     * is `php artisan domains:activate`, run by whoever installed the TLS certificate — see ADR-0012.
     */
    public const MANAGE_DOMAINS = 'manage:domains';

    /**
     * ability => the RBAC permissions that entitle a user to hold it (holding ANY one grants the ability).
     * `read:forms` mirrors FormPolicy::viewAny exactly, so a token's ability and the route policy agree.
     *
     * @var array<string, list<string>>
     */
    private const ABILITY_TO_PERMISSION = [
        self::READ_FORMS => ['forms.create', 'forms.edit.any', 'forms.edit.own'],
        self::WRITE_FORMS => ['forms.create', 'forms.edit.any', 'forms.edit.own', 'forms.publish.any', 'forms.publish.own'],
        self::READ_SUBMISSIONS => ['submissions.view'],
        self::WRITE_SUBMISSIONS => ['submissions.create'],
        self::REVIEW_SUBMISSIONS => ['submissions.review.any', 'submissions.review.own'],
        self::EXPORT_SUBMISSIONS => ['submissions.export'],
        self::MANAGE_WEBHOOKS => ['webhooks.manage'],
        self::MANAGE_SETTINGS => ['tenant.settings.manage'],
        self::READ_AUDIT_LOG => ['audit_log.view'],
        self::MANAGE_SCOPES => ['scopes.manage', 'forms.collaborators.manage'],
        self::MANAGE_INTEGRATIONS => ['integrations.manage'],
        self::READ_ANALYTICS => ['dashboard.org.view', 'dashboard.form.view'],
        self::MANAGE_DOMAINS => ['tenant.settings.manage'],
    ];

    /**
     * Every ability the catalog defines (for validating a mint request's requested set).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::ABILITY_TO_PERMISSION);
    }

    /**
     * The subset of $requested abilities the user is actually entitled to under their current-tenant RBAC,
     * so a token can never exceed its issuer (api-spec §2.6). Unknown abilities are dropped.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    public static function intersect(User $user, array $requested): array
    {
        return array_values(array_filter(
            array_unique($requested),
            static fn (string $ability): bool => isset(self::ABILITY_TO_PERMISSION[$ability])
                && self::userHolds($user, self::ABILITY_TO_PERMISSION[$ability]),
        ));
    }

    /**
     * @param  list<string>  $permissions
     */
    private static function userHolds(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            // $user->can() (Spatie Gate::before) returns false for an unheld OR unseeded permission —
            // never throws — matching the FormPolicy convention.
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
