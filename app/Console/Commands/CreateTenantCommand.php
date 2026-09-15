<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BillingInterval;
use App\Enums\PlanTier;
use App\Enums\TenantUserStatus;
use App\Enums\UsageMetric;
use App\Events\MemberJoined;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Rules\SubdomainLabel;
use App\Services\Admin\SuperAdminService;
use App\Services\Auth\OperatorAccounts;
use App\Services\Entitlements\EntitlementService;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Auth\UserName;
use App\Support\Tenancy\PlatformHost;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantUrl;
use Database\Seeders\PlanSeeder;
use Database\Seeders\PlatformFieldLibrarySeeder;
use Database\Seeders\PlatformTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create a workspace with its founding Owner (M95) — the operator path onto a fresh server.
 *
 * WHY A COMMAND. The console lists, suspends and re-plans workspaces but creates none, and open registration
 * makes an account that joins no workspace on the central host. An invitation cannot stand in either:
 * {@see TenantMembershipService::invite()} refuses the owner role, and multi-tenancy-rbac-design.md §7.1 makes
 * Owner reachable only by transfer. The founding Owner row is designed to be written WITH the tenant, with
 * `invited_by` NULL, and that is what this does. It sits in `tenants:` beside `tenants:extract`, noun:verb.
 *
 * ── A WORKSPACE IS MORE WRITES THAN "TENANT, DOMAIN, MEMBERSHIP, PLAN" ───────────────────────────────────
 * One transaction on the ORDINARY app role, in this order:
 *   1. the Owner account, when the address has none — one INSERT through {@see OperatorAccounts};
 *   2. the tenant, WITH `owner_user_id` in the INSERT, then `refresh()` for its database defaults. Every Owner
 *      invariant keys on that pointer rather than on the role: `remove()`, `changeRole()`,
 *      `transferOwnership()` and the member list all read it, so a workspace without it has an Owner an Admin
 *      can remove. A hand-provisioned development tenant was measured in exactly that shape;
 *   3. the bare subdomain label row in `domains` (a dotted value would be a custom domain, and refused);
 *   4. under the tenant's own context and Spatie team: the `default` subscription (mirroring
 *      {@see SuperAdminService::assignPlan()}'s write, without its audit, which needs a human actor), the
 *      entitlement memo dropped, the Active `tenant_users` row, the owner role row, and `MemberJoined` — raised
 *      INSIDE the transaction as its docblock requires, so the founding Owner earns the welcome award every
 *      other door gives, while the notification listener excludes the joiner and so tells nobody;
 *   5. the Spatie team id restored in a `finally` that issues no SQL, so a failure is never replaced by a
 *      second failure on an aborted connection.
 * A unique violation (a slug or label taken concurrently) rolls all of it back and is reported as such. Then,
 * AFTER commit, the postconditions: the pointer, the membership, exactly one role row, the plan, and host
 * resolution through {@see PlatformHost::tenantFor()} — the scoped query a request uses. Success is never
 * reported on writes nobody observed.
 *
 * ⚠️ NEVER ON `pgsql_privileged`. A superuser bypasses row-level security, so the strict WITH CHECK on
 * `tenant_users`, `subscriptions` and `model_has_roles` would prove nothing.
 *
 * ── IDEMPOTENT, AND NEVER A REPAIR ──────────────────────────────────────────────────────────────────────
 * An identical re-run (same slug, same Owner, pointer, label, membership, role and an active subscription on
 * the same plan) succeeds and writes nothing. The same workspace on a DIFFERENT plan is refused and pointed at
 * the console, where a plan change is audited. Any other existing state is refused with every discrepancy
 * named — this command never adopts, repairs or transfers. ⚠️ Those reads run inside `TenantContext::runFor()`:
 * `tenant_users`, `model_has_roles` and `subscriptions` return no rows without the tenant GUC, and `SET LOCAL`
 * does nothing outside a transaction, so reading them bare would call every identical re-run half-provisioned.
 *
 * ── OWNERS IT REFUSES ───────────────────────────────────────────────────────────────────────────────────
 * The address is resolved on `pgsql_auth` by exact equality. A deleted account is refused. An account that
 * never verified its address is refused, because while Open signup is on a stranger can register the intended
 * Owner's address first. A platform super-admin is refused (C5): support can never impersonate one, and the
 * console's two-factor gate does not cover workspace hosts.
 *
 * EXIT CODES: 0 created or an identical re-run; 1 a state this command will not act on; 2 bad input, including
 * a non-interactive run that would have to create the Owner's account.
 *
 * ⚠️ IT WRITES NO AUDIT ROW for the tenant, the founding membership or the initial subscription. That is a
 * deliberate gap, for the reason the audit spec's `domain` row gives for artisan-only acts: `AuditLogger`
 * hard-codes `is_system_action = false`, so a console row would carry a NULL actor beside it, which is malformed
 * by the logger's own contract. A system-actor audit shape is filed as a before-launch row in
 * `docs/feature-backlog.md`, and `CreateTenantCommandTest` pins the absence.
 *
 * ⚠️ NEVER RUN stancl's OTHER `tenants:` COMMANDS on this single-database application — `tenants:migrate`,
 * `tenants:migrate-fresh`, `tenants:rollback`, `tenants:seed` and `tenants:run` are database-per-tenant tooling,
 * and `tenants:seed` runs `DatabaseSeeder`, which includes the demo seeder outside production.
 */
final class CreateTenantCommand extends Command
{
    /** The catalogs a server seeds BY CLASS, in `DatabaseSeeder`'s order. A bare `db:seed` also runs the demo seeder. */
    public const array CATALOG_SEEDERS = [
        RolePermissionSeeder::class,
        PlatformTemplateSeeder::class,
        PlatformFieldLibrarySeeder::class,
        PlanSeeder::class,
    ];

    private const string AUTH_CONNECTION = 'pgsql_auth';

    private const string OWNER_ROLE = 'owner';

    /** `tenants.name` is `varchar(255)`. */
    private const int NAME_MAX = 255;

    protected $signature = 'tenants:create
                            {slug : The subdomain label, reached at SLUG.CENTRAL_DOMAIN: lower-case letters, digits and hyphens}
                            {name : The workspace display name}
                            {owner : The founding Owner email address}
                            {--plan= : Required. The plan tier: free, starter, professional, business or enterprise}
                            {--owner-name= : Display name for a NEW Owner account (default: the part of the address before the @)}';

    protected $description = 'Create a workspace, its subdomain, its plan and its founding Owner (run after the catalogs are seeded)';

    public function handle(OperatorAccounts $accounts): int
    {
        // ── Input ───────────────────────────────────────────────────────────────────────────────────────
        $slug = Str::lower(trim((string) $this->argument('slug')));
        $name = trim((string) $this->argument('name'));
        $ownerEmail = $accounts->canonicalEmail((string) $this->argument('owner'));

        $planOption = $this->option('plan');
        $planInput = is_string($planOption) ? Str::lower(trim($planOption)) : '';
        $tier = PlanTier::tryFrom($planInput);

        // A typed `--owner-name` is REFUSED past the column by `nameErrors()` below, because the operator can shorten
        // it. The DEFAULT is derived from the address, which nobody typed as a name, so it is FITTED instead —
        // refusing it would blame an "owner name" on an operator who never passed one (M96, {@see UserName}).
        $ownerNameOption = $this->option('owner-name');
        $ownerName = is_string($ownerNameOption) && trim($ownerNameOption) !== ''
            ? trim($ownerNameOption)
            : UserName::fit(Str::before($ownerEmail, '@'));

        $errors = [
            ...array_values(Validator::make(
                ['slug' => $slug, 'name' => $name],
                [
                    'slug' => ['required', 'string', new SubdomainLabel],
                    'name' => ['required', 'string', 'max:'.self::NAME_MAX],
                ],
            )->errors()->all()),
            ...$accounts->emailErrors($ownerEmail),
            ...$accounts->nameErrors($ownerName, 'owner_name'),
        ];

        if ($tier === null) {
            $errors[] = ($planInput === '' ? '--plan is required.' : "Unknown plan '{$planInput}'.")
                .' Choose one of: '.implode(', ', PlanTier::values()).'.';
        }

        if ($errors !== [] || $tier === null) {
            return $this->invalid($errors);
        }

        // ── Catalog ─────────────────────────────────────────────────────────────────────────────────────
        $ownerRole = Role::query()->whereNull('tenant_id')->where('name', self::OWNER_ROLE)->first();
        $plan = Plan::query()->where('code', $tier->value)->first();

        if ($ownerRole === null || $plan === null) {
            return $this->refuseMissingCatalog($ownerRole === null, $plan === null, $tier);
        }

        // ── Owner identity ──────────────────────────────────────────────────────────────────────────────
        $owner = $accounts->find(self::AUTH_CONNECTION, $ownerEmail);

        if ($owner !== null) {
            if ($owner->trashed()) {
                return $this->refuse(
                    "An account for {$ownerEmail} exists but is deleted. Restore it deliberately, or use a different "
                    .'address. Nothing was written.'
                );
            }

            if ($owner->is_super_admin) {
                return $this->refuse(
                    "{$ownerEmail} is a platform super-admin, and a workspace Owner must be a separate identity: "
                    .'support can never impersonate a super-admin, and the console two-factor gate does not cover '
                    .'workspace hosts. Use a different address; a plus-address works. Nothing was written.'
                );
            }

            if ($owner->email_verified_at === null) {
                return $this->refuse(
                    "{$ownerEmail} has an account whose address was never verified. It may have been registered by "
                    .'someone else while Open signup was on, so it cannot become an Owner. Turn off Open signup at '
                    .route('admin.settings.index').', then use a different address. Nothing was written.'
                );
            }

            // Read on the pre-auth role; every write below belongs to the app role, and `meridian_auth` could not
            // make them anyway.
            $owner->setConnection((string) config('database.default'));
        }

        // ── Collisions and idempotency ──────────────────────────────────────────────────────────────────
        $existing = Tenant::query()->where('slug', $slug)->first();
        // UNSCOPED: a label row is a label row whatever the resolvable scope thinks of it.
        $label = Domain::unscopedQuery()->where('domain', $slug)->first();

        if ($existing !== null) {
            return $this->reconcile($existing, $label, $owner, $ownerEmail, $ownerRole, $tier);
        }

        if ($label !== null) {
            return $this->refuse(
                "The subdomain label '{$slug}' already routes to another workspace. Choose a different slug. "
                .'Nothing was written.'
            );
        }

        // ── A new Owner's password, before any transaction opens ────────────────────────────────────────
        $password = null;

        if ($owner === null) {
            if (! $this->input->isInteractive()) {
                return $this->invalid([
                    "No account exists for {$ownerEmail}, and a new Owner's password is typed at a hidden prompt, "
                    .'which this non-interactive run cannot show. Run it again from an interactive console session, '
                    .'without --no-interaction.',
                ]);
            }

            $this->line("No account exists for {$ownerEmail}, so one is created as the Owner. Type its password twice; the input is hidden.");

            $prompted = $accounts->promptForNewPassword($this);

            if ($prompted['password'] === null) {
                return $this->invalid($prompted['errors']);
            }

            $password = $prompted['password'];
        }

        // ── The write ───────────────────────────────────────────────────────────────────────────────────
        try {
            $created = $this->provision($accounts, $slug, $name, $ownerEmail, $ownerName, $owner, $password, $ownerRole, $plan);
        } catch (UniqueConstraintViolationException) {
            return $this->refuse(
                "The slug '{$slug}', its label or the Owner address was taken by something else while this command "
                .'was running. Nothing was written; run it again to see what exists now.'
            );
        }

        // ── Postconditions, after commit ────────────────────────────────────────────────────────────────
        $problems = $this->postconditions($created['tenant'], (string) $created['owner']->getKey(), $ownerRole, $tier, $slug);

        if ($problems !== []) {
            $this->error("Workspace '{$name}' was committed, but these checks of what was written failed:");

            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }

            $this->line('Inspect the database before anyone uses this workspace.');

            return self::FAILURE;
        }

        $this->report($created['tenant'], $created['owner'], $owner === null, $plan);

        return self::SUCCESS;
    }

    /**
     * The class docblock's steps 1 to 5, in one transaction on the default connection.
     *
     * @return array{tenant: Tenant, owner: User}
     */
    private function provision(
        OperatorAccounts $accounts,
        string $slug,
        string $name,
        string $ownerEmail,
        string $ownerName,
        ?User $owner,
        ?string $password,
        Role $ownerRole,
        Plan $plan,
    ): array {
        $registrar = app(PermissionRegistrar::class);
        $savedTeam = $registrar->getPermissionsTeamId();

        try {
            /** @var array{tenant: Tenant, owner: User} $created */
            $created = DB::transaction(function () use ($accounts, $registrar, $slug, $name, $ownerEmail, $ownerName, $owner, $password, $ownerRole, $plan): array {
                // 1. The Owner account, when new. ONE INSERT; `users_app_insert` is WITH CHECK (true).
                $user = $owner ?? $accounts->createVerifiedAccount(
                    (string) config('database.default'),
                    $ownerName,
                    $ownerEmail,
                    (string) $password,
                    superAdmin: false,
                );

                // 2. The tenant, with the Owner pointer in the INSERT itself. `tenants` is RLS-exempt.
                /** @var Tenant $tenant */
                $tenant = Tenant::create([
                    'name' => $name,
                    'slug' => $slug,
                    'default_locale' => 'en',
                    'owner_user_id' => $user->getKey(),
                ]);
                // `status`, `supported_locales` and `maintenance_mode` come from database defaults that Eloquent
                // does not back-fill, so an unrefreshed instance reports `isActive()` false.
                $tenant->refresh();

                // 3. The bare subdomain label. `domains` is RLS-exempt.
                $tenant->domains()->create(['domain' => $slug]);

                // 4. Everything strict-RLS, under the tenant's own context and Spatie team.
                $tenantId = (string) $tenant->getKey();
                $registrar->setPermissionsTeamId($tenantId);

                TenantContext::runFor($tenantId, function () use ($tenant, $tenantId, $user, $ownerRole, $plan): void {
                    // A brand-new tenant has no subscription, so this is always the create arm of assignPlan()'s
                    // upsert. `tenant_id` fills from the context; `quantity` takes its database default.
                    $subscription = new Subscription;
                    $subscription->forceFill([
                        'plan_id' => $plan->getKey(),
                        'name' => 'default',
                        'stripe_status' => 'active',
                        'billing_interval' => BillingInterval::Monthly,
                    ])->save();

                    // Before MemberJoined: its points listener reads the plan through this memo.
                    app(EntitlementService::class)->forget($tenantId);

                    TenantUser::create([
                        'user_id' => $user->getKey(),
                        'status' => TenantUserStatus::Active,
                        'joined_at' => now(),
                        'invited_role_id' => $ownerRole->getKey(),
                        'invited_by' => null,
                    ]);

                    $user->syncRoles([$ownerRole]);

                    event(MemberJoined::for($tenant, $user, self::OWNER_ROLE));
                });

                return ['tenant' => $tenant, 'owner' => $user];
            });
        } finally {
            // 5. PHP state only — no SQL on the way out of a failure.
            $registrar->setPermissionsTeamId($savedTeam);
        }

        return $created;
    }

    /**
     * Decide an existing slug: an identical re-run succeeds with zero writes, anything else is refused.
     */
    private function reconcile(Tenant $tenant, ?Domain $label, ?User $owner, string $ownerEmail, Role $ownerRole, PlanTier $tier): int
    {
        $slug = (string) $tenant->slug;

        if ($owner === null) {
            return $this->refuse(
                "A workspace with the slug '{$slug}' already exists, and no account exists for {$ownerEmail}, so this "
                .'is not a re-run of the command that created it. Choose a different slug. Nothing was written.'
            );
        }

        $tenantId = (string) $tenant->getKey();
        $ownerId = (string) $owner->getKey();

        /** @var array{discrepancies: list<string>, plan: PlanTier|null} $state */
        $state = TenantContext::runFor($tenantId, function () use ($tenant, $label, $tenantId, $ownerId, $ownerEmail, $ownerRole, $slug): array {
            $discrepancies = [];

            if ($tenant->owner_user_id === null) {
                $discrepancies[] = 'it has no Owner (tenants.owner_user_id is empty)';
            } elseif ($tenant->owner_user_id !== $ownerId) {
                $discrepancies[] = 'its Owner is a different account';
            }

            if ($label === null || $label->tenant_id !== $tenantId) {
                $discrepancies[] = "it has no subdomain label row '{$slug}'";
            }

            if (! TenantUser::query()->where('user_id', $ownerId)->where('status', TenantUserStatus::Active->value)->exists()) {
                $discrepancies[] = "{$ownerEmail} is not an active member of it";
            }

            if (! DB::table('model_has_roles')
                ->where('tenant_id', $tenantId)
                ->where('model_id', $ownerId)
                ->where('role_id', $ownerRole->getKey())
                ->exists()) {
                $discrepancies[] = "{$ownerEmail} does not hold the owner role in it";
            }

            $plan = $this->activePlanTier();

            if ($plan === null) {
                $discrepancies[] = 'it has no active subscription';
            }

            return ['discrepancies' => $discrepancies, 'plan' => $plan];
        });

        if ($state['discrepancies'] !== []) {
            $this->error("A workspace with the slug '{$slug}' already exists and is not the one this command would create:");

            foreach ($state['discrepancies'] as $discrepancy) {
                $this->line("  - {$discrepancy}");
            }

            $this->line('This command never adopts, repairs or transfers an existing workspace. Choose a different slug. Nothing was written.');

            return self::FAILURE;
        }

        if ($state['plan'] !== $tier) {
            return $this->refuse(
                "The workspace '{$slug}' already exists for {$ownerEmail}, on the ".($state['plan']->value ?? 'no')
                ." plan rather than {$tier->value}. Change a workspace plan in the console at "
                .route('admin.tenants.index').', where the change is audited. Nothing was written.'
            );
        }

        $this->info("Workspace '{$tenant->name}' is already provisioned for {$ownerEmail} on the {$tier->value} plan. Nothing was written.");
        $this->line('  Sign in at: '.TenantUrl::to($tenant, 'login'));

        return self::SUCCESS;
    }

    /**
     * Read back what the transaction committed, the way a request would see it.
     *
     * @return list<string>
     */
    private function postconditions(Tenant $tenant, string $ownerId, Role $ownerRole, PlanTier $tier, string $slug): array
    {
        $tenantId = (string) $tenant->getKey();

        /** @var list<string> $problems */
        $problems = TenantContext::runFor($tenantId, function () use ($tenantId, $ownerId, $ownerRole, $tier): array {
            $problems = [];

            if ((string) Tenant::query()->whereKey($tenantId)->value('owner_user_id') !== $ownerId) {
                $problems[] = 'tenants.owner_user_id does not name the Owner';
            }

            if (! TenantUser::query()->where('user_id', $ownerId)->where('status', TenantUserStatus::Active->value)->exists()) {
                $problems[] = 'the Owner has no active tenant_users row';
            }

            $roleIds = DB::table('model_has_roles')
                ->where('tenant_id', $tenantId)
                ->where('model_id', $ownerId)
                ->pluck('role_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            if ($roleIds !== [(string) $ownerRole->getKey()]) {
                $problems[] = 'the Owner does not hold exactly one role row, the owner role';
            }

            if ($this->activePlanTier() !== $tier) {
                $problems[] = "the active subscription is not on the {$tier->value} plan";
            }

            return $problems;
        });

        $host = $slug.'.'.config('tenancy.central_domain');

        if ((string) PlatformHost::tenantFor($host)?->getKey() !== $tenantId) {
            $problems[] = "{$host} does not resolve to this workspace";
        }

        return $problems;
    }

    /** The ambient tenant's active subscription tier — `EntitlementService`'s selection, minus its free fallback. */
    private function activePlanTier(): ?PlanTier
    {
        $plan = Subscription::query()->active()->with('plan')->latest('created_at')->first()?->plan;

        return $plan instanceof Plan ? $plan->code : null;
    }

    private function report(Tenant $tenant, User $owner, bool $ownerIsNew, Plan $plan): void
    {
        $this->info("Workspace '{$tenant->name}' is ready.");
        $this->line('  Sign in at: '.TenantUrl::to($tenant, 'login'));
        $this->line("  Owner:      {$owner->email} (".($ownerIsNew ? 'new account' : 'existing account').')');
        $this->line("  Plan:       {$plan->name} ({$plan->code->value})");

        foreach ([[UsageMetric::ActiveSeats, 'Active seats'], [UsageMetric::FormsCount, 'Forms'], [UsageMetric::SubmissionsCount, 'Submissions']] as [$metric, $label]) {
            $limit = $plan->quotaFor($metric);
            $this->line('    '.str_pad($label.':', 14).($limit === null ? 'unlimited' : (string) $limit));
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $central = (string) config('tenancy.central_domain');

        if ($appHost !== $central) {
            $this->warn(
                'APP_URL names the host '.(is_string($appHost) ? $appHost : '(none)')." but CENTRAL_DOMAIN is {$central}. "
                .'The link above is built from APP_URL while routing uses CENTRAL_DOMAIN, so it may not reach this '
                .'workspace. Make them agree, then run php artisan config:cache.'
            );
        }

        if ($plan->code === PlanTier::Free) {
            $this->warn(
                'The Free plan allows '.($plan->quotaFor(UsageMetric::ActiveSeats) ?? 'unlimited').' active seats, '
                .'which is the Owner plus one invited tester. A testing workspace usually needs Starter or above; an '
                .'operator can change it at '.route('admin.tenants.index').'.'
            );
        }

        if (! $plan->is_active) {
            $this->warn("{$plan->name} is held from sale, so only an operator can assign it.");
        }

        $this->newLine();
        $this->line(
            'Next: sign in as the Owner and invite testers from Members. Invitations are sent by email from the queue, '
            .'so MAIL_* must point at a real mail server and the queue worker must be running.'
        );
    }

    private function refuseMissingCatalog(bool $roleMissing, bool $planMissing, PlanTier $tier): int
    {
        $missing = [];

        if ($roleMissing) {
            $missing[] = 'the role catalog (there is no global owner role)';
        }

        if ($planMissing) {
            $missing[] = "the plan catalog (there is no {$tier->value} plan)";
        }

        $this->error('A workspace cannot be created without '.implode(' or ', $missing).'. Nothing was written.');
        $this->line(
            'Seed the catalogs by class. Never run a bare db:seed on a server: outside APP_ENV=production it also runs '
            .'the demo seeder, which creates demo workspaces and a super-admin whose password is published in the '
            .'repository.'
        );

        foreach (self::CATALOG_SEEDERS as $seeder) {
            $this->line('  php artisan db:seed --class='.class_basename($seeder).' --force');
        }

        return self::FAILURE;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /** @param  list<string>  $errors */
    private function invalid(array $errors): int
    {
        foreach ($errors as $error) {
            $this->error($error);
        }

        $this->line('Nothing was written.');

        return self::INVALID;
    }
}
