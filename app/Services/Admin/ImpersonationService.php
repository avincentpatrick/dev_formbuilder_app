<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AuditEvent;
use App\Enums\NotificationType;
use App\Enums\TenantUserStatus;
use App\Models\ImpersonationToken;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\ImpersonationContext;
use App\Support\Audit\RedeemedImpersonation;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Impersonation, the operator's half — Increment I11b, `multi-tenancy-rbac-design.md` §9 resolved
 * decision 1. Mints the cross-host grant, records it in the affected tenant's own ledger, and tells that
 * tenant's Owner.
 *
 * §9 states ONE requirement about impersonation and it is about the ledger, not the feature: keep "a
 * super-admin's own actions distinguishable from actions taken while impersonating". I11a built that half
 * (`audits.acting_as_user_id`). This class is what finally writes to it — and everything else here exists
 * to make sure it is written truthfully.
 *
 * ── THE FOUR DECISIONS OF RECORD ────────────────────────────────────────────────────────────────────────
 * Taken with the product owner on 2026-08-09, because §9 is a DEFERRAL and specifies none of them:
 * full read-write impersonation, fully audited · any ACTIVE tenant member may be a target, but never
 * another super-admin and never yourself · the affected tenant sees it in their OWN `/audit-log` and the
 * Owner is notified · shipped as I11a + I11b. Two more were taken the same day: the impersonated session
 * is capped at 30 minutes ({@see App\Http\Middleware\EnforceImpersonationTimeout}), and the Owner's notice
 * honours their email preference like every other type.
 *
 * ── ELIGIBILITY IS CHECKED TWICE, AND THE SECOND TIME IS THE ONE THAT MATTERS ───────────────────────────
 * {@see self::assertEligible()} runs at MINT and again at CONSUME. A token lives 60 seconds, which is
 * plenty of time for an Owner to revoke the membership an operator is about to borrow, or for the target to
 * be granted `is_super_admin` — and a mint-only check would then let a grant outlive the authority it was
 * issued against. Re-checking costs one query on a path that runs at most once per support session.
 *
 * ⚠️ THE NEVER-YOURSELF RULE IS ENFORCED IN THREE PLACES AND THAT IS DELIBERATE. Here at mint, here again
 * at consume, and by `audits_acting_as_not_self_check` at the database. The DB constraint is not a
 * belt-and-braces duplicate of the other two: it also catches the case they cannot see — an
 * `impersonator_id` left in a session after exit, which would make an ORDINARY later action write a row
 * saying a user impersonated themselves. See the I11a migration.
 *
 * ── NO ELEVATED CONNECTION, AND THAT WAS A CHOICE ───────────────────────────────────────────────────────
 * `impersonation_tokens` is strict-RLS and the console has no ambient tenant context, so the obvious move
 * is `pgsql_superadmin` — which {@see SuperAdminService::updatePlatformSettings()} already uses for the
 * platform settings write. It is wrong here. That connection exists to read (and, for platform rows, write)
 * ACROSS tenants; this operation concerns exactly one tenant, and {@see SuperAdminService::tenantSnapshot()}
 * already owns the narrower idiom — adopt the tenant inside a transaction with
 * {@see TenantContext::applyLocal()} and restore in a `finally`. Using the elevated connection would widen
 * a surface that has a correct narrow answer, and would additionally make the audit row `tenant_id = NULL`
 * (the bypass policy's shape), putting it in the PLATFORM ledger — the one place §9 says it must not be.
 *
 * ⚠️ `applyLocal()` IS `SET LOCAL` AND IS A SILENT NO-OP OUTSIDE A TRANSACTION. Every method here that
 * adopts a context does so inside `DB::transaction()`. Called outside one it would set nothing, the strict
 * INSERT would match no policy, and the write would fail — or, worse, land under whatever context happened
 * to be open.
 */
final class ImpersonationService
{
    /**
     * How long a minted grant stays redeemable.
     *
     * Sixty seconds is a HANDOFF, not a credential: the only legitimate holder is a browser being redirected
     * into it in the same tick. Anything longer is a bearer token sitting in a proxy log, a referrer header
     * or an address bar. Paired with single-use — see {@see ImpersonationToken::scopeRedeemable()} for why
     * neither half is sufficient alone.
     */
    public const int TOKEN_TTL_SECONDS = 60;

    /**
     * The session key carrying the hard cap's deadline, as a unix timestamp.
     *
     * Declared here beside the writer rather than in the middleware that reads it, mirroring
     * {@see ImpersonationContext::SESSION_KEY}: the two ends of a session contract drift apart when the
     * string lives at only one of them.
     */
    public const string DEADLINE_SESSION_KEY = 'impersonation_expires_at';

    /** Decision of record (2026-08-09): an impersonated session dies after 30 minutes, clicked or not. */
    public const int SESSION_CAP_MINUTES = 30;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
        private readonly NotificationRecipientResolver $recipients,
    ) {}

    /**
     * Mint a grant for `$targetUserId` in `$tenant` and return the ABSOLUTE tenant-host URL that redeems it.
     *
     * The caller hands that URL to `Inertia::location()` — never a 302. An Inertia XHR cannot follow a plain
     * redirect to another origin; it is the 409 that tells the client to leave the SPA. Getting this wrong
     * is what stranded I10e for four CI cycles.
     *
     * @throws RuntimeException when the target is not eligible (the controller renders it as a 422)
     */
    public function mint(Tenant $tenant, string $operatorId, string $targetUserId, ?string $ip): string
    {
        $tenantId = (string) $tenant->getKey();

        // Generated OUTSIDE the transaction so the plaintext never sits in a variable a query log could see
        // beside the row it belongs to, and so a retry cannot reuse it. 64 hex chars from a CSPRNG.
        $plain = bin2hex(random_bytes(32));

        DB::transaction(function () use ($tenantId, $operatorId, $targetUserId, $ip, $plain): void {
            $savedTenant = TenantContext::currentTenantId();
            $savedUser = TenantContext::currentUserId();

            // Adopt the TARGET tenant, acting as the TARGET user. The user half matters: the audit row's
            // RLS INSERT compares `tenant_id` to the ambient GUC, and `BelongsToTenant` fills the column
            // from the same place, so a mismatch here writes the operator's action into the wrong ledger.
            TenantContext::applyLocal($tenantId, $targetUserId);

            try {
                $this->assertEligible($tenantId, $operatorId, $targetUserId);

                ImpersonationToken::query()->create([
                    'operator_id' => $operatorId,
                    'target_user_id' => $targetUserId,
                    'token_hash' => self::hash($plain),
                    'expires_at' => Carbon::now()->addSeconds(self::TOKEN_TTL_SECONDS),
                    'ip_address' => $ip,
                ]);

                $this->recordBoundary(AuditEvent::ImpersonationStarted, $operatorId, $targetUserId);
                $this->notifyOwner($operatorId, $targetUserId);
            } finally {
                TenantContext::applyLocal($savedTenant, $savedUser);
            }
        });

        return TenantUrl::to($tenant, 'impersonate/'.$plain);
    }

    /**
     * Redeem a plaintext token on the tenant host, returning the user to log in as.
     *
     * Runs with the tenant GUC already set by `EstablishTenantDatabaseContext` but with NO authenticated
     * user — the invitation flow's property, and the reason strict RLS is satisfiable here at all. Returns
     * null for every failure (unknown, expired, already consumed, no longer eligible) so the caller renders
     * one indistinguishable 404: an operator-facing error message that distinguished "expired" from "never
     * existed" would also distinguish them for anyone else holding a guessed string.
     */
    public function redeem(string $plain, ?string $ip): ?RedeemedImpersonation
    {
        return DB::transaction(function () use ($plain, $ip): ?RedeemedImpersonation {
            // Locked for update: two tabs opening the same link in the same tick would otherwise both read
            // `consumed_at IS NULL` and both log in, which is precisely the replay single-use exists to
            // stop. `redeemable()` carries both halves of "usable now".
            $token = ImpersonationToken::query()
                ->redeemable()
                ->where('token_hash', self::hash($plain))
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return null;
            }

            $tenantId = (string) $token->tenant_id;
            $operatorId = (string) $token->operator_id;
            $targetUserId = (string) $token->target_user_id;

            // ⚠️ RE-CHECKED, NOT TRUSTED FROM MINT TIME. Sixty seconds is enough for a membership to be
            // revoked or for the target to be promoted to super-admin; a grant must not outlive the
            // authority it was issued against.
            if (! $this->isEligible($tenantId, $operatorId, $targetUserId)) {
                return null;
            }

            $token->forceFill([
                'consumed_at' => Carbon::now(),
                // Overwritten with the REDEEMER's IP, deliberately. The mint IP is already in the
                // `impersonation_started` audit row; what this row can uniquely answer afterwards is
                // whether the address that redeemed the grant is the one that asked for it.
                'ip_address' => $ip,
            ])->save();

            // On the app connection under the tenant's own context: the target is an active member, so
            // `usersVisibilitySql()`'s membership arm resolves them even with no authenticated user yet.
            $user = User::query()->find($targetUserId);

            // Fail closed rather than returning a half-answer. `isEligible()` above already proved an
            // active membership exists, so a null here means the row moved under us inside the
            // transaction — which is exactly when NOT logging someone in is the right outcome.
            return $user === null ? null : new RedeemedImpersonation($user, $operatorId);
        });
    }

    /**
     * Record the closing boundary. Called from the exit path and from the timeout middleware, both of which
     * run on the tenant host with context already established — hence no adoption here.
     */
    public function recordEnded(string $operatorId, string $targetUserId): void
    {
        $this->recordBoundary(AuditEvent::ImpersonationEnded, $operatorId, $targetUserId);
    }

    /**
     * The members of `$tenant` an operator may impersonate, for the console's picker.
     *
     * ⚠️ THE PICKER SHOWS ONLY ELIGIBLE ROWS, WHICH IS A DELIBERATE CHOICE OVER SHOWING-AND-DISABLING. A
     * disabled row would have to explain itself, and the honest explanation for the super-admin case is
     * "this person is platform staff" — a fact the console has no business rendering into a per-tenant
     * roster. The refusal also arrives at the wrong moment: after the operator has decided who to help.
     *
     * ⚠️ DELIBERATELY NOT {@see TenantMembershipService::listMembers()}, THOUGH IT LOOKS LIKE THE SAME
     * QUERY. That method also lists INVITED members, whose placeholder `users` rows are hidden by the app
     * connection's join-shape policy, so it resolves identities on the pre-auth `pgsql_auth` connection to
     * see them. This picker lists ACTIVE members ONLY, every one of whom is visible on the app connection
     * through `usersVisibilitySql()`'s membership arm — so it needs no cross-connection read.
     *
     * That is a correctness argument, not a performance one: {@see self::isEligible()} answers on the app
     * connection, and a picker sourced from a connection with DIFFERENT visibility rules could offer a row
     * the guard then refuses. Reading both from the same place is what makes the two agree by construction.
     * (It also happens to be the difference between a testable method and one that needs committed
     * fixtures, since `pgsql_auth` is a separate session that cannot see an open test transaction.)
     *
     * @return list<array{id: string, name: string, email: string, role: string, is_owner: bool}>
     */
    public function eligibleTargets(Tenant $tenant, string $operatorId): array
    {
        $tenantId = (string) $tenant->getKey();
        $ownerId = $tenant->owner_user_id === null ? null : (string) $tenant->owner_user_id;

        /** @var list<array{id: string, name: string, email: string, role: string, is_owner: bool}> $rows */
        $rows = DB::transaction(function () use ($tenantId, $operatorId, $ownerId): array {
            $savedTenant = TenantContext::currentTenantId();
            $savedUser = TenantContext::currentUserId();
            TenantContext::applyLocal($tenantId);

            try {
                $memberIds = TenantUser::query()
                    ->where('status', TenantUserStatus::Active)
                    ->pluck('user_id')
                    ->all();

                if ($memberIds === []) {
                    return [];
                }

                // Both exclusions in the query rather than a post-filter, so "eligible" is one predicate
                // the database evaluates: never yourself, never another super-admin.
                /** @var list<array{id: string, name: string, email: string}> $users */
                $users = User::query()
                    ->whereIn('id', $memberIds)
                    ->whereKeyNot($operatorId)
                    ->where('is_super_admin', false)
                    ->orderBy('name')
                    ->get(['id', 'name', 'email'])
                    // `getAttribute()` rather than `->name`: this model's attributes are database columns
                    // with no declared properties, so a direct read is untyped to static analysis and the
                    // shape below is what the page is typed against.
                    ->map(static fn (User $user): array => [
                        'id' => (string) $user->getKey(),
                        'name' => (string) $user->getAttribute('name'),
                        'email' => (string) $user->getAttribute('email'),
                    ])
                    ->values()
                    ->all();

                $roles = $this->materializedRoles(array_column($users, 'id'));

                return array_map(
                    static fn (array $row): array => [
                        ...$row,
                        'role' => $roles[$row['id']] ?? '—',
                        'is_owner' => $ownerId !== null && $ownerId === $row['id'],
                    ],
                    $users,
                );
            } finally {
                TenantContext::applyLocal($savedTenant, $savedUser);
            }
        });

        return $rows;
    }

    /**
     * The role each member actually HOLDS, not the one their invitation reserved.
     *
     * `model_has_roles` is team-scoped by RLS, so the adopted context is what makes this the current
     * tenant's assignment rather than one from another workspace — the same read
     * {@see TenantMembershipService::listMembers()} makes for its active rows, and the reason a member who
     * appears in two workspaces shows a different role in each.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    private function materializedRoles(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var array<string, string> $map */
        $map = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('model_has_roles.model_id', $userIds)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->pluck('roles.name', 'model_has_roles.model_id')
            ->map(static fn (mixed $name): string => Str::headline((string) $name))
            ->all();

        return $map;
    }

    /** sha256 hex, matching the column. Never store or log the plaintext. */
    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** @throws RuntimeException */
    private function assertEligible(string $tenantId, string $operatorId, string $targetUserId): void
    {
        if (! $this->isEligible($tenantId, $operatorId, $targetUserId)) {
            throw new RuntimeException('That member cannot be impersonated.');
        }
    }

    /**
     * The three rules, in one place so the mint and the consume cannot disagree about them.
     *
     * ⚠️ ORDER IS NOT ARBITRARY: the cheap in-memory identity check runs before the membership query, so a
     * self-impersonation attempt costs nothing. All three must hold; there is no "unless" branch.
     */
    private function isEligible(string $tenantId, string $operatorId, string $targetUserId): bool
    {
        // Never yourself. §9's decision of record, and the one the DB also refuses to record.
        if ($operatorId === $targetUserId) {
            return false;
        }

        // Never another super-admin. Platform staff impersonating platform staff produces a ledger entry
        // that names two operators and explains neither, and it is the escalation path a compromised
        // console account would actually take.
        //
        // Expressed as a PREDICATE rather than a property read, which also makes it fail CLOSED: a target
        // whose row is invisible under the current context — or absent entirely — returns false here
        // instead of dereferencing null. `where('is_super_admin', false)` is not the same as `!== true`
        // for that reason.
        $impersonatable = User::query()
            ->whereKey($targetUserId)
            ->where('is_super_admin', false)
            ->exists();

        if (! $impersonatable) {
            return false;
        }

        // ACTIVE membership only — not `invited` (they have not accepted), not `suspended` (their own access
        // is withdrawn, so borrowing it would grant more than they have), not `removed`/`declined`.
        return TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $targetUserId)
            ->where('status', TenantUserStatus::Active)
            ->exists();
    }

    /**
     * Write one boundary row into the AFFECTED TENANT's ledger.
     *
     * ⚠️ `user_id` = the TARGET and `acting_as_user_id` = the OPERATOR, which is the same shape every action
     * taken during the session will carry. The alternative — operator as `user_id` — was rejected: it would
     * make the two boundary rows the only rows in the ledger whose actor is someone with no membership, so
     * `AuditLogPresenter::actorLabel()` would render them "Unknown user" while every row between them read
     * correctly. It would also leave `acting_as_user_id` NULL on precisely the rows the column exists for.
     *
     * The operator is supplied through {@see ImpersonationContext::set()} rather than a parameter, because
     * that static IS the ambient channel `AuditLogger` reads — the console has no session to carry it. Paired
     * with `forget()` in a `finally`, for the reason that class spells out: a static surviving into the next
     * job on a long-lived worker would attribute an unrelated action to an operator who had gone home.
     */
    private function recordBoundary(AuditEvent $event, string $operatorId, string $targetUserId): void
    {
        ImpersonationContext::set($operatorId);

        try {
            $this->audit->record(
                $event,
                // ⚠️ `users`, PLURAL — the alias registered in `AuditableTypes::LABELS` and already written
                // by three other sites. A singular `user` would not have broken anything visibly: the
                // label map fails OPEN, so the row would still render as "User". It would simply be a
                // SECOND alias for one concept, absent from the filter dropdown (`options()` fails closed)
                // — so an auditor filtering the ledger by User would never see an impersonation.
                'users',
                $targetUserId,
                null,
                null,
                $targetUserId,
            );
        } finally {
            ImpersonationContext::forget();
        }
    }

    /**
     * Tell the tenant's Owner, through I3's substrate (§9 resolved decision 2's transparency posture).
     *
     * ⚠️ THE OPERATOR IS EXCLUDED FROM THE RECIPIENT SET, and the case is real rather than theoretical:
     * I11a's review established that nothing stops a super-admin ALSO holding a membership of the tenant
     * they impersonate into. If that membership is Owner, they would otherwise be notified of their own
     * action. `forType()`'s third argument is the same "minus the person who did it" the member-invited
     * fan-out uses. Every OTHER Owner still receives it.
     *
     * ⚠️ NO NAMES IN THE PAYLOAD. `data` on a notification row is readable by every member of the tenant
     * under the strict policy (see the `notifications` migration), and I11a's whole S2 finding was that the
     * operator's real name must not reach a tenant surface. The row says an operator acted and who it was
     * done AS; `AuditLogPresenter::actingAsLabel()` renders the operator as "Platform operator" everywhere
     * else, and this must not be the one place that leaks the name.
     */
    private function notifyOwner(string $operatorId, string $targetUserId): void
    {
        // One column, not a hydrated model: the payload needs a display name and nothing else, and a
        // `find()` here would invite a later edit to reach for another attribute off the same object.
        $targetName = User::query()->whereKey($targetUserId)->value('name');

        $this->notifications->dispatch(
            NotificationType::ImpersonationStarted,
            $this->recipients->forType(NotificationType::ImpersonationStarted, null, $operatorId),
            [
                'target_user_id' => $targetUserId,
                'target_name' => is_string($targetName) && $targetName !== '' ? $targetName : 'a member',
            ],
        );
    }
}
