<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * ── `WithoutModelEvents` WAS REMOVED IN I6, AND IT WAS A LIVE BUG RATHER THAN A TIDY-UP ────────────────
 * That trait wraps the whole `run()` — and every seeder it `->call()`s — in `Model::withoutEvents()`. Three
 * of this schema's `creating` hooks are load-bearing, not cosmetic:
 *
 *   · `HasUuidv7` fills the uuid primary key. `users.id` is `uuid primary` with NO database default and
 *     `UserFactory` never sets it, so both `User::factory()->create()` calls below were inserting a NULL
 *     primary key.
 *   · `BelongsToTenant::creating` fills `tenant_id`, and without it every tenant-scoped INSERT is refused
 *     by the strict RLS `WITH CHECK`.
 *   · `Submission::booted()` fills `reference` (J2e). `submissions.reference` is `varchar(8) NOT NULL` with
 *     no database default, so suppressing this one is a hard 23502 rather than a subtle wrong value — which
 *     is an improvement on the two above, and is exactly why the list has to stay current here rather than
 *     be rediscovered per hook.
 *
 * The same caveat applies to anything else that bypasses model events: `saveQuietly()` on a NEW row (see
 * `DemoSeeder`'s note where it force-fills `created_at`), `Model::unguarded()` combined with a raw insert,
 * and `DB::table('submissions')->insert()`. The last of those is why two RLS test helpers mint their own
 * reference explicitly.
 *
 * The trait exists to suppress *observer* side effects during seeding, and this repository has no Observer
 * classes at all — so it bought nothing and cost the key. `E2eSeeder`, the only seeder that any gate has
 * ever actually run, deliberately does not use it; this file now matches. Nothing in CI runs
 * `DatabaseSeeder`, which is why the breakage survived this long — `DemoSeederIdempotencyTest` is the
 * first thing that exercises it.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The fixed RBAC catalog (roles/permissions/matrix) — global rows, seeded via pgsql_privileged.
        $this->call(RolePermissionSeeder::class);

        // The platform form-template gallery (G9a) — NULL-tenant rows, also seeded via pgsql_privileged.
        $this->call(PlatformTemplateSeeder::class);

        // The platform question library (G9b) — NULL-tenant rows, also seeded via pgsql_privileged.
        $this->call(PlatformFieldLibrarySeeder::class);

        // The global plan catalog (H5a) — the tenant_id-free `plans` table; seeded on the DEFAULT
        // connection (it has no RLS), idempotent on `code`.
        $this->call(PlanSeeder::class);

        // ── THE TWO ad-hoc `User::factory()->create()` CALLS THAT LIVED HERE WERE REMOVED IN I6 ───────────
        // Both were `create()` on a column with a UNIQUE index and no upsert, so the SECOND `db:seed` on any
        // already-seeded database raised a 23505 on the email — the same class of latent breakage as the
        // `WithoutModelEvents` bug above, and invisible for the same reason. `test@example.com` was also a
        // Laravel-skeleton leftover with no `tenant_users` row at all: it could log in and land nowhere.
        //
        // `DemoSeeder` now owns every identity in the dev database. It resolves users on the `pgsql_auth`
        // connection (the join-shape `users` RLS hides a non-member from the app connection, so a plain
        // `firstOrCreate` would try to insert a duplicate), gives each one a membership and a role, and
        // seeds the platform super-admin — deliberately UNENROLLED in two-factor, for the reason its own
        // docblock gives. The credentials are listed in `docs/TESTING-GUIDE.md` §0.
        //
        // Runs LAST: it resolves the plan catalog and the RBAC roles the four calls above create.
        // Idempotent, and guarded against production internally.
        $this->call(DemoSeeder::class);
    }
}
