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
