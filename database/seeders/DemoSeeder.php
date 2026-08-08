<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AuditEvent;
use App\Enums\BillingInterval;
use App\Enums\FeedbackStatus;
use App\Enums\FieldType;
use App\Enums\NotificationType;
use App\Enums\PlanTier;
use App\Enums\ResourceCapacity;
use App\Enums\SettingKey;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\TenantUserStatus;
use App\Models\Audit;
use App\Models\FeedbackReport;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\ResourceGrant;
use App\Models\Role;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Authorization\ResourceGrantService;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Notifications\NotificationPreferenceResolver;
use App\Services\Settings\TenantSettingRegistry;
use App\Services\Submissions\AnswerIndexProjector;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditRedactor;
use App\Support\Entitlements\ToggleableModules;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\DeterministicIds;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * The HAND-TESTING fixture (Increment I6) — the dataset `docs/TESTING-GUIDE.md` walks.
 *
 * Two tenants reachable at `demo.{central_domain}` and `northwind.{central_domain}`, all five roles, six
 * forms with distinct jobs, ~90 days of submission history, and enough audit / notification / feedback /
 * settings variety that every Phase-1 surface has something to show. Guarded against production.
 * Idempotent: safe to re-run, and a re-run CONVERGES rather than doubling or silently skipping.
 *
 * ── IT NEVER TOUCHES `acme` ─────────────────────────────────────────────────────────────────────────────
 * {@see E2eSeeder} owns `acme`, and `E2eSeederIdempotencyTest` pins its row counts exactly (7 published
 * forms, 33 submissions, 7 notifications, Owner unread 4). Writing so much as one row into that tenant
 * would couple this convenience fixture to a merge-blocking gate. The two seeders share only the deterministic
 * key helper ({@see DeterministicIds}) and are otherwise disjoint.
 *
 * ── NO doesntExist() GUARDS ─────────────────────────────────────────────────────────────────────────────
 * Every write is an upsert on a deterministic natural key. The create-if-absent idiom is a recorded trap
 * (`E2eSeeder.php:132-136`): it goes green on CI's fresh database while every developer's box silently keeps
 * the old shape forever. The only exceptions are the once-only FORM builds, which are guarded on the form's
 * own existence because re-publishing would grow version history without end — the same carve-out
 * `seedProgrammeUptakeForm()` makes, and for the same reason.
 *
 * ── THE DATES ARE RELATIVE, AND THAT IS THE FEATURE ─────────────────────────────────────────────────────
 * `/dashboard` and `/analytics` open on rolling windows, so a fixed calendar date would fall out of them and
 * the pages would go empty the day after this was written. Every timestamp is `now()`-relative and is
 * re-stamped on each re-seed. What is deterministic is the SHAPE — the per-day volume, and the status /
 * source / locale distributions. Convergence is therefore asserted timestamp-FREE.
 *
 * Logins are listed in `docs/TESTING-GUIDE.md` §0. Password for every demo account: {@see PASSWORD}.
 */
class DemoSeeder extends Seeder
{
    use DeterministicIds;

    /** One password for every seeded demo account — low-entropy on purpose, `gitleaks` scans this repo. */
    public const PASSWORD = 'meridian-demo-2026';

    public const DEMO_SLUG = 'demo';

    public const SECOND_SLUG = 'northwind';

    /** The super-admin `DatabaseSeeder` also creates; resolved here so `--class=DemoSeeder` works standalone. */
    public const SUPER_ADMIN_EMAIL = 'admin@meridian.test';

    private const FIXTURE_PREFIX = 'i6:';

    /** The workhorse form that carries the 90-day history. */
    private const HISTORY_FORM = 'Patient Intake';

    /**
     * How many days of history `run()` writes. Ninety in every real seed; TESTS TURN IT DOWN.
     *
     * A full seed is ~520 submissions, and a suite that ran that eight times over would add minutes to a
     * run for no extra coverage — idempotency is a property of the KEYING, not of the volume. Turning this
     * down is only sound because every derived value is a function of the row INDEX and never of the row
     * count, so a short run is a genuine PREFIX of a long one; `DemoSeederIdempotencyTest` pins exactly that
     * in `it('derives every submission row from its index')`, and one test still runs at full volume to
     * assert the numbers `docs/TESTING-GUIDE.md` prints.
     */
    public int $historyDays = 90;

    /**
     * The demo workspace roster. Every role in `RolePermissionSeeder`'s catalog is represented, so each
     * permission boundary in the guide has an account that sits on the far side of it.
     *
     * @var list<array{email: string, name: string, role: string, status: string}>
     */
    private const DEMO_PEOPLE = [
        ['email' => 'owner@demo.test', 'name' => 'Olivia Owner', 'role' => 'owner', 'status' => 'active'],
        ['email' => 'admin@demo.test', 'name' => 'Adam Admin', 'role' => 'admin', 'status' => 'active'],
        ['email' => 'editor@demo.test', 'name' => 'Elena Editor', 'role' => 'form_editor', 'status' => 'active'],
        ['email' => 'reviewer@demo.test', 'name' => 'Raj Reviewer', 'role' => 'reviewer', 'status' => 'active'],
        ['email' => 'viewer@demo.test', 'name' => 'Vera Viewer', 'role' => 'viewer', 'status' => 'active'],
        // Renders the Members roster's pending Badge variant. Never loggable — the password is discarded.
        ['email' => 'invited@demo.test', 'name' => 'Ingrid Invited', 'role' => 'viewer', 'status' => 'invited'],
        // ⚠️ ALSO a member of `northwind`. This is what makes the guide's cross-tenant isolation chapter a
        // real test rather than a notional one: the same session-less identity holds two memberships, which
        // is the "a consultant in two tenants" case `notifications`' own RLS docblock calls out as the
        // reason both tables are `strict` rather than `belongs_to_user`.
        ['email' => 'consultant@demo.test', 'name' => 'Cai Consultant', 'role' => 'viewer', 'status' => 'active'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        // The catalogs this fixture resolves. Idempotent, so the duplicate call from DatabaseSeeder (which
        // has already made them) is free — and running `db:seed --class=DemoSeeder` standalone still works.
        $this->call(RolePermissionSeeder::class);
        $this->call(PlatformTemplateSeeder::class);
        $this->call(PlatformFieldLibrarySeeder::class);
        $this->call(PlanSeeder::class);

        $this->ensureSuperAdmin();
        $this->seedDemoWorkspace();
        $this->seedSecondWorkspace();
    }

    /**
     * The platform super-admin, deliberately left WITHOUT confirmed two-factor.
     *
     * ⚠️ DO NOT "improve" this with `UserFactory::confirmedTwoFactor()`. That state writes a PLACEHOLDER
     * TOTP secret, and Fortify challenges an enrolled user at login — so the account would become
     * unloggable-into by a human, trading a one-time enrolment screen for a permanent lockout. Unenrolled is
     * both the testable flow (`EnsureSuperAdminMfa` redirects to `admin.mfa.setup`) and the only one a
     * person with a real authenticator app can complete. `docs/TESTING-GUIDE.md` §15 walks it.
     */
    private function ensureSuperAdmin(): void
    {
        $admin = $this->resolveOrCreateUser(self::SUPER_ADMIN_EMAIL, 'Platform Admin', self::PASSWORD);

        if ($admin->is_super_admin !== true) {
            $admin->forceFill(['is_super_admin' => true])->save();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────
    // Tenant 1 — the walkthrough workspace
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────

    private function seedDemoWorkspace(): void
    {
        $tenant = $this->resolveTenant(self::DEMO_SLUG, 'Meridian Demo');

        // Identities are resolved BEFORE the transaction: resolveOrCreateUser() reads on `pgsql_auth`, and
        // mixing that connection's reads into the tenant transaction buys nothing.
        $people = [];
        foreach (self::DEMO_PEOPLE as $person) {
            $people[$person['email']] = $this->resolveOrCreateUser(
                $person['email'],
                $person['name'],
                $person['status'] === 'invited' ? Str::random(48) : self::PASSWORD,
            );
        }

        $owner = $people['owner@demo.test'];
        $editor = $people['editor@demo.test'];
        $reviewer = $people['reviewer@demo.test'];

        // ⚠️ THE TRANSACTION IS MANDATORY. TenantContext::applyLocal() is `SET LOCAL`, a silent no-op outside
        // one — and without the GUC every tenant-scoped INSERT below is refused by the strict RLS WITH CHECK.
        DB::transaction(function () use ($tenant, $people, $owner, $editor, $reviewer): void {
            TenantContext::applyLocal((string) $tenant->id, (string) $owner->getKey());
            app(PermissionRegistrar::class)->setPermissionsTeamId((string) $tenant->id);

            // Business is the only tier carrying `advanced_analytics`, and it is seeded `is_active: false`
            // (held from sale until the production host is stood up, ADR-0008 §D6) — so an admin assignment
            // like this is the ONLY way a tenant reaches it. Without it the guide's analytics chapter would
            // walk a 402. UPSERT, never create-if-absent: a tier change must reach an already-seeded box.
            $this->assignPlan(PlanTier::Business, $tenant);

            $this->seedMemberships($people);
            $this->seedForms($tenant, $owner, $editor);

            // ⚠️ WITHOUT THESE GRANTS THE REVIEWER'S INBOX IS EMPTY, AND THE GUIDE'S REVIEW CHAPTER FAILS ON
            // ITS FIRST STEP. Org-wide visibility is `dashboard.org.view` ({@see Submission::scopeVisibleTo()}),
            // which `owner`, `admin` and `viewer` hold and `reviewer` and `form_editor` deliberately do NOT —
            // both are collaboration-scoped roles, so they see exactly the forms they hold a grant on.
            // Granting a SUBSET is the point: logging in as Raj and seeing three forms where Olivia sees six
            // is the cheapest possible demonstration that the RBAC layer is actually live.
            $this->seedGrants($owner, $people);

            $this->seedTenantSettings($tenant, $owner, [
                // Open registration, so I5's self-join path is reachable from the guide. The platform half
                // (`registration.open_signup`) is left at its `true` default — it is a NULL-tenant row and
                // would need SuperAdminService's elevated connection, and leaving it alone is also what
                // keeps the landing page's "Create workspace" CTA rendered.
                SettingKey::RegistrationInviteOnly->value => false,
            ]);

            $this->seedSubmissionHistory($owner, $reviewer, self::historySpec($this->historyDays));

            // ⚠️ THE OTHER FORMS NEED ROWS TOO, AND THE REASON IS NOT COSMETIC. Analytics breaks down by
            // form, so a corpus concentrated on one form renders the form axis as a SINGLE BAR — a chart
            // that cannot be wrong, and therefore cannot be tested. Five forms carrying rows is what makes
            // the breakdown, its ordering and its "+N others" tail all reachable.
            foreach ([
                'Community Health Survey 2026' => 48,
                'Referral Router' => 34,
                'Field Site Assessment' => 26,
                'Staff Feedback 2025' => 18,
            ] as $title => $count) {
                $this->seedSubmissionHistory($owner, $reviewer, self::secondaryFormSpec($this->scaled($count)), $title);
            }
            $this->seedDemoAudits($owner, $editor);
            $this->seedDemoNotifications($owner, $reviewer);
            $this->seedFeedback($people);
        });

        $tenant->forceFill(['owner_user_id' => $owner->getKey()])->save();

        $this->releaseContext();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────
    // Tenant 2 — deliberately thin, and that is its whole job
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * A second workspace with one owner, one form and a handful of submissions.
     *
     * Thin on purpose. Its job is to make isolation OBSERVABLE — the guide's last chapter logs in here as
     * `consultant@demo.test`, who is an active member of both, and confirms that none of tenant 1's forms,
     * submissions, audit rows or notifications are reachable. A second rich tenant would make the difference
     * harder to see, not easier. It also runs on Starter rather than Business, so the guide can watch a
     * plan-gated surface (`/analytics`) disappear without any setting being touched.
     */
    private function seedSecondWorkspace(): void
    {
        $tenant = $this->resolveTenant(self::SECOND_SLUG, 'Northwind Health');

        $owner = $this->resolveOrCreateUser('owner@northwind.test', 'Nadia Northwind', self::PASSWORD);
        $consultant = $this->resolveOrCreateUser('consultant@demo.test', 'Cai Consultant', self::PASSWORD);

        DB::transaction(function () use ($tenant, $owner, $consultant): void {
            TenantContext::applyLocal((string) $tenant->id, (string) $owner->getKey());
            app(PermissionRegistrar::class)->setPermissionsTeamId((string) $tenant->id);

            $this->assignPlan(PlanTier::Starter, $tenant);

            $this->seedMemberships([
                'owner@northwind.test' => $owner,
                'consultant@demo.test' => $consultant,
            ], [
                'owner@northwind.test' => ['role' => 'owner', 'status' => 'active'],
                'consultant@demo.test' => ['role' => 'viewer', 'status' => 'active'],
            ]);

            if (Form::query()->where('title', 'Referral Log')->doesntExist()) {
                $form = app(FormService::class)->create(
                    $tenant, $owner, 'Referral Log', 'Northwind referral intake.'
                );
                $builder = app(FormBuilderService::class);
                $section = $builder->addSection($form);
                $section->update(['label' => 'Referral']);
                $builder->addField($form, $owner, FieldType::ShortText, $section->id)
                    ->update(['label' => 'Referred by', 'key' => 'referred_by']);
                $builder->addField($form, $owner, FieldType::Integer, $section->id)
                    ->update(['label' => 'Age', 'key' => 'age', 'is_queryable' => true, 'indexed_data_type' => 'number']);
                app(PublishService::class)->publish($form->refresh(), $owner);
            }

            $this->seedTenantSettings($tenant, $owner, [
                // Invite-only (the safe default, stated explicitly so the two workspaces differ visibly)…
                SettingKey::RegistrationInviteOnly->value => true,
                // …and ONE module switched off, as a worked example of the third entitlement layer. `webhooks`
                // is the visible choice: the nav item and the routes both disappear while the plan still
                // grants it. The demo workspace deliberately leaves every module ON so the guide can walk
                // all of them, and turns one off by hand in §8 instead.
                ToggleableModules::settingKey('webhooks') => false,
            ]);

            $this->seedSubmissionHistory($owner, $owner, self::secondWorkspaceSpec($this->scaled(14)), 'Referral Log');
        });

        $tenant->forceFill(['owner_user_id' => $owner->getKey()])->save();

        $this->releaseContext();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────
    // Forms
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Six forms, each with a job the others do not do.
     *
     * Guarded on the form's own existence rather than upserted: `PublishService::publish()` mints a new
     * version every call, so an unguarded re-run would grow version history without end. That is the same
     * carve-out `E2eSeeder::seedProgrammeUptakeForm()` makes, and the reason is identical.
     */
    private function seedForms(Tenant $tenant, User $owner, User $editor): void
    {
        $this->seedHistoryForm($tenant, $owner);
        $this->seedScheduledForm($tenant, $owner);
        $this->seedFieldRichForm($tenant, $editor);
        $this->seedClosedForm($tenant, $owner);
        $this->seedDraftForm($tenant, $editor);
        $this->seedBranchingForm($tenant, $owner);
    }

    /**
     * The workhorse: guest-enabled, save-and-resume, and five INDEXED fields covering all four indexable
     * types, so `/analytics`'s question explorer has text (categorical), number (min/max/avg/median), date
     * and boolean (the one value column with no B-tree) to render differently.
     */
    private function seedHistoryForm(Tenant $tenant, User $owner): void
    {
        if (Form::query()->where('title', self::HISTORY_FORM)->exists()) {
            return;
        }

        $form = app(FormService::class)->create(
            $tenant, $owner, self::HISTORY_FORM, 'Walk-in patient registration — the demo workhorse.'
        );
        $b = app(FormBuilderService::class);
        $section = $b->addSection($form); // non-repeatable — a field inside a repeat can never project
        $section->update(['label' => 'Patient details']);

        $b->addField($form, $owner, FieldType::ShortText, $section->id)
            ->update(['label' => 'Patient name', 'key' => 'patient_name']);
        // StructuralValidationGate enforces `is_queryable ⇒ indexed_data_type` at publish, so both together.
        $b->addField($form, $owner, FieldType::ShortText, $section->id)
            ->update(['label' => 'District', 'key' => 'district', 'is_queryable' => true, 'indexed_data_type' => 'text']);
        $b->addField($form, $owner, FieldType::Integer, $section->id)
            ->update(['label' => 'Age', 'key' => 'age', 'is_queryable' => true, 'indexed_data_type' => 'number']);
        $b->addField($form, $owner, FieldType::Date, $section->id)
            ->update(['label' => 'Visit date', 'key' => 'visit_date', 'is_queryable' => true, 'indexed_data_type' => 'date']);
        $b->addField($form, $owner, FieldType::YesNo, $section->id)
            ->update(['label' => 'Consent given', 'key' => 'consent', 'is_queryable' => true, 'indexed_data_type' => 'boolean']);
        $b->addField($form, $owner, FieldType::SingleSelect, $section->id)
            ->update([
                'label' => 'Sex', 'key' => 'sex', 'is_queryable' => true, 'indexed_data_type' => 'text',
                'config' => ['options' => [
                    ['value' => 'female', 'label' => 'Female'],
                    ['value' => 'male', 'label' => 'Male'],
                    ['value' => 'other', 'label' => 'Other'],
                ]],
            ]);
        // Deliberately NOT queryable — an array answer projects nothing (§9, scalars only), and having one
        // unindexed field on the form is what makes the explorer's coverage figure honest.
        $b->addField($form, $owner, FieldType::MultiSelect, $section->id)
            ->update([
                'label' => 'Presenting symptoms', 'key' => 'symptoms',
                'config' => ['options' => [
                    ['value' => 'fever', 'label' => 'Fever'],
                    ['value' => 'cough', 'label' => 'Cough'],
                    ['value' => 'fatigue', 'label' => 'Fatigue'],
                ]],
            ]);
        $b->addField($form, $owner, FieldType::LongText, $section->id)
            ->update(['label' => 'Clinical notes', 'key' => 'notes']);

        app(PublishService::class)->publish($form->refresh(), $owner);

        $form->update([
            'public_slug' => 'patient-intake',
            'allow_guest_submissions' => true,
            'supported_locales' => ['en', 'es'],
            'save_and_resume' => true,
        ]);
    }

    /** An OPEN scheduled window plus save-and-resume — the two per-form settings with a time dimension. */
    private function seedScheduledForm(Tenant $tenant, User $owner): void
    {
        if (Form::query()->where('title', 'Community Health Survey 2026')->exists()) {
            return;
        }

        $form = app(FormService::class)->create(
            $tenant, $owner, 'Community Health Survey 2026', 'A scheduled survey with a live open window.'
        );
        $b = app(FormBuilderService::class);
        $section = $b->addSection($form);
        $section->update(['label' => 'Your experience']);

        $b->addField($form, $owner, FieldType::LikertScale, $section->id)->update([
            'label' => 'How satisfied were you?', 'key' => 'satisfaction',
            'config' => ['options' => [
                ['value' => '1', 'label' => 'Very dissatisfied'],
                ['value' => '2', 'label' => 'Dissatisfied'],
                ['value' => '3', 'label' => 'Neutral'],
                ['value' => '4', 'label' => 'Satisfied'],
                ['value' => '5', 'label' => 'Very satisfied'],
            ]],
        ]);
        $b->addField($form, $owner, FieldType::LikertMatrix, $section->id)->update([
            'label' => 'Rate each aspect', 'key' => 'aspects',
            'config' => [
                'rows' => [
                    ['value' => 'cleanliness', 'label' => 'Cleanliness'],
                    ['value' => 'staff', 'label' => 'Staff friendliness'],
                    ['value' => 'wait_time', 'label' => 'Wait time'],
                ],
                'columns' => [
                    ['value' => '1', 'label' => 'Poor'],
                    ['value' => '2', 'label' => 'Fair'],
                    ['value' => '3', 'label' => 'Good'],
                ],
            ],
        ]);
        $b->addField($form, $owner, FieldType::LongText, $section->id)
            ->update(['label' => 'Anything else?', 'key' => 'comments']);

        app(PublishService::class)->publish($form->refresh(), $owner);

        $form->update([
            'public_slug' => 'community-survey',
            'allow_guest_submissions' => true,
            'save_and_resume' => true,
        ]);

        // Through the real writer, not a raw update: `setSchedule()` is the only thing that RECOMPUTES
        // `schedule_state` from the window, so the demo cannot drift into claiming "open" after the window
        // has passed. It also audits the change with real before/after values.
        app(FormService::class)->setSchedule(
            $form->refresh(), now()->subDays(30), now()->addDays(30), 'Asia/Manila', 200, $owner
        );
    }

    /** Geo + file upload + a repeatable section — the three controls no other demo form exercises. */
    private function seedFieldRichForm(Tenant $tenant, User $editor): void
    {
        if (Form::query()->where('title', 'Field Site Assessment')->exists()) {
            return;
        }

        $form = app(FormService::class)->create(
            $tenant, $editor, 'Field Site Assessment', 'Geo, attachments and a repeatable roster.'
        );
        $b = app(FormBuilderService::class);

        $b->addField($form, $editor, FieldType::ShortText, null)
            ->update(['label' => 'Assessor', 'key' => 'assessor']);
        $b->addField($form, $editor, FieldType::Geopoint, null)->update([
            'label' => 'Pin the site', 'key' => 'site_location',
            'config' => [
                'capture_altitude' => true,
                'accuracy_threshold' => 20,
                'default_center' => ['lat' => 14.5995, 'lon' => 120.9842],
                'default_zoom' => 11,
            ],
        ]);
        $b->addField($form, $editor, FieldType::FileUpload, null)
            ->update(['label' => 'Site photograph', 'key' => 'site_photo']);

        $rooms = $b->addSection($form);
        $rooms->update(['label' => 'Rooms surveyed', 'is_repeatable' => true, 'min_instances' => 1, 'max_instances' => 6]);
        $b->addField($form, $editor, FieldType::ShortText, $rooms->id)
            ->update(['label' => 'Room name', 'key' => 'room_name']);
        $b->addField($form, $editor, FieldType::YesNo, $rooms->id)
            ->update(['label' => 'Meets standard?', 'key' => 'meets_standard']);

        app(PublishService::class)->publish($form->refresh(), $editor);

        $form->update([
            'public_slug' => 'site-assessment',
            'allow_guest_submissions' => true,
            'single_page_mode' => true,
        ]);
    }

    /** Published, guest-enabled, but its window has passed — the runtime's full-screen "closed" state. */
    private function seedClosedForm(Tenant $tenant, User $owner): void
    {
        if (Form::query()->where('title', 'Staff Feedback 2025')->exists()) {
            return;
        }

        $form = app(FormService::class)->create(
            $tenant, $owner, 'Staff Feedback 2025', 'Last year\'s staff survey — closed.'
        );
        app(FormBuilderService::class)->addField($form, $owner, FieldType::LongText, null)
            ->update(['label' => 'Your feedback', 'key' => 'feedback']);
        app(PublishService::class)->publish($form->refresh(), $owner);

        $form->update([
            'public_slug' => 'staff-feedback-2025',
            'allow_guest_submissions' => true,
        ]);

        // A window that has passed. `setSchedule()` derives `schedule_state: closed` from it, so the guest
        // runtime renders its full-screen "this form is closed" state rather than a fill session.
        app(FormService::class)->setSchedule(
            $form->refresh(), now()->subDays(120), now()->subDays(45), 'Asia/Manila', null, $owner
        );
    }

    /** Never published — the builder's unpublished state, and the publish gate the guide exercises. */
    private function seedDraftForm(Tenant $tenant, User $editor): void
    {
        if (Form::query()->where('title', 'Quarterly Report (draft)')->exists()) {
            return;
        }

        $form = app(FormService::class)->create(
            $tenant, $editor, 'Quarterly Report (draft)', 'A work in progress — never published.'
        );
        $b = app(FormBuilderService::class);
        $section = $b->addSection($form);
        $section->update(['label' => 'Quarter']);
        $b->addField($form, $editor, FieldType::ShortText, $section->id)
            ->update(['label' => 'Reporting period', 'key' => 'period']);
        $b->addField($form, $editor, FieldType::Integer, $section->id)
            ->update(['label' => 'Households served', 'key' => 'households']);
        // Deliberately left as a DRAFT — no publish() call.
    }

    /** Relevance-derived branching, so the builder's Logic view has a real graph to draw. */
    private function seedBranchingForm(Tenant $tenant, User $owner): void
    {
        if (Form::query()->where('title', 'Referral Router')->exists()) {
            return;
        }

        $form = app(FormService::class)->create(
            $tenant, $owner, 'Referral Router', 'Routes a respondent by referral type (logic demo).'
        );
        $b = app(FormBuilderService::class);

        $routing = $b->addSection($form);
        $routing->update(['label' => 'Routing']);
        $b->addField($form, $owner, FieldType::SingleSelect, $routing->id)->update([
            'key' => 'referral_type', 'label' => 'How were you referred?',
            'config' => ['options' => [
                ['value' => 'clinic', 'label' => 'By a clinic'],
                ['value' => 'self', 'label' => 'Self-referred'],
            ]],
        ]);

        $clinic = $b->addSection($form);
        $clinic->update(['label' => 'Clinic details', 'relevant_expression' => "\${referral_type} = 'clinic'"]);
        $b->addField($form, $owner, FieldType::ShortText, $clinic->id)
            ->update(['label' => 'Referring clinic', 'key' => 'clinic_name']);

        $self = $b->addSection($form);
        $self->update(['label' => 'How you found us', 'relevant_expression' => "\${referral_type} = 'self'"]);
        $b->addField($form, $owner, FieldType::ShortText, $self->id)
            ->update(['label' => 'Where did you hear about us?', 'key' => 'heard_from']);

        app(PublishService::class)->publish($form->refresh(), $owner);

        $form->update([
            'public_slug' => 'referral-router',
            'allow_guest_submissions' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────
    // Submissions
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Write one submission per spec row — head row, the 1:1 answers row, and the projected index.
     *
     * ── WHY NOT SubmissionPipeline ──────────────────────────────────────────────────────────────────────
     * Five reasons, recorded at `E2eSeeder.php:1339-1348`, and the first alone settles it. **(1)**
     * `SubmissionPipeline::persist()` hard-codes `submitted_at => now()`, so every row would land in ONE
     * bucket — the exact degeneracy a 90-day fixture exists to remove. **(2)** It fires `SubmissionCreated`,
     * whose two deliberately-synchronous listeners create delivery rows and enqueue jobs; under
     * `QUEUE_CONNECTION=sync` those attempt real outbound HTTPS during `db:seed` and pollute the delivery
     * ledgers the webhook/connector axe scans assert on. **(3)** `FormAcceptanceGuard` refuses the closed
     * form outright. **(4)** Stages 1 and 3 refuse synthetic payloads against the branching, repeat and geo
     * forms. **(5)** `MeterSubmissionUsage` inflates the `entitlements.quotas` every page renders.
     *
     * Only the INSERT is hand-rolled. The index coercion is {@see AnswerIndexProjector}, the production
     * writer's own collaborator, so this fixture cannot drift from `SubmissionFinalizer::projectIndex()`.
     *
     * ── PUBLIC, as a seam for the idempotency test ──────────────────────────────────────────────────────
     * {@see run()} resolves identities on the separate `pgsql_auth` connection, and a second `run()` inside
     * `RefreshDatabase`'s single uncommitted transaction cannot see the first run's users — a 23505 that
     * says nothing about whether the fixture converges. This method touches no identity, so a test re-runs
     * it directly. It also takes its spec as an ARGUMENT, so the test can drive a dozen rows while a real
     * seed drives several hundred: idempotency is a property of the keying, not of the volume.
     *
     * @param  list<array{back: int, hour: int, seq: int, status: SubmissionStatus, source: SubmissionSource, locale: string, saved: bool, fill_seconds: int}>  $spec
     */
    public function seedSubmissionHistory(User $respondent, User $reviewer, array $spec, string $formTitle = self::HISTORY_FORM): void
    {
        $form = Form::query()
            ->where('title', $formTitle)
            ->whereNotNull('current_published_version_id')
            ->first();

        if (! $form instanceof Form) {
            return; // a database seeded before this form existed — degrade, never fatal
        }

        $version = FormVersion::findOrFail($form->current_published_version_id);
        /** @var Collection<int, FormField> $fields */
        $fields = $version->fields()->get();

        foreach ($spec as $row) {
            $this->writeSubmission($form, $version, $fields, $respondent, $reviewer, $row, $formTitle);
        }
    }

    /**
     * @param  Collection<int, FormField>  $fields
     * @param  array{back: int, hour: int, seq: int, status: SubmissionStatus, source: SubmissionSource, locale: string, saved: bool, fill_seconds: int}  $row
     */
    private function writeSubmission(
        Form $form,
        FormVersion $version,
        Collection $fields,
        User $respondent,
        User $reviewer,
        array $row,
        string $formTitle,
    ): void {
        $status = $row['status'];
        $isDraft = $status === SubmissionStatus::Draft;
        $createdAt = CarbonImmutable::now()->utc()->subDays($row['back'])->setTime($row['hour'], ($row['seq'] * 7) % 60);
        $submittedAt = $createdAt->addSeconds($row['fill_seconds']);
        $reviewedAt = $submittedAt->addHours(6);

        $reviewed = in_array($status, [SubmissionStatus::Approved, SubmissionStatus::Returned], true);
        $finalized = in_array($status, [SubmissionStatus::Approved, SubmissionStatus::Archived], true);

        $submission = Submission::updateOrCreate(
            ['client_submission_uuid' => self::fixtureUuid(self::FIXTURE_PREFIX."{$formTitle}:{$row['back']}:{$row['seq']}")],
            [
                'form_id' => $form->id,
                'form_version_id' => $version->id,
                'respondent_user_id' => $row['source'] === SubmissionSource::Manual ? $respondent->getKey() : null,
                'status' => $status,
                'source' => $row['source'],
                'locale' => $row['locale'],
                'submitted_at' => $isDraft ? null : $submittedAt,
                // ADR-0011 §D5's durable marker: set only on rows that were once explicitly saved as a
                // draft. It survives promotion, which is what makes the denominator computable at all.
                'last_saved_at' => $row['saved'] ? $createdAt : null,
                'draft_expires_at' => $isDraft ? $createdAt->addDays(30) : null,
                'validated_by' => $reviewed ? $reviewer->getKey() : null,
                'validated_at' => $reviewed ? $reviewedAt : null,
                'finalized_at' => $finalized ? $reviewedAt : null,
                'returned_reason' => $status === SubmissionStatus::Returned
                    ? 'Date of birth is missing — please complete and resubmit.'
                    : null,
            ],
        );

        // `created_at` is NOT mass-assignable and Eloquent would stamp the insert time over it — which is
        // exactly the column ADR-0011 §D5 reads as "first save". The tests/Pest.php seedCountableAt() device.
        // Safe here because the row already EXISTS: saveQuietly on a NEW row would suppress the `creating`
        // hooks that fill `tenant_id` and the uuid key.
        $submission->forceFill(['created_at' => $createdAt])->saveQuietly();

        // A `screened_out` row is the respondent who was shown NO questions (I9a), so a full answer document
        // would contradict the state on the very page a tester opens to understand it — and its
        // `completeness_percent` is 0, not 100. Empty rather than absent: the 1:1 answer row still exists,
        // because `SubmissionInboxPresenter::answerBlocks()` and the PDF presenter both read through it.
        $screenedOut = $status === SubmissionStatus::ScreenedOut;
        $answers = $screenedOut ? [] : $this->demoAnswers($fields, $row['seq']);

        SubmissionAnswer::updateOrCreate(
            ['submission_id' => $submission->id],
            [
                'form_version_id' => $version->id,
                'answers' => $answers,
                'attachment_refs' => [],
                'completeness_percent' => match (true) {
                    $screenedOut => 0,
                    $isDraft => 40 + ($row['seq'] % 40),
                    default => 100,
                },
                'last_saved_at' => $row['saved'] ? $createdAt : null,
            ],
        );

        $this->projectIndex($submission, $version, $fields, $answers);
    }

    /**
     * Project every flagged answer through the REAL mapper, so the fixture and the production writer agree.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $answers
     */
    private function projectIndex(Submission $submission, FormVersion $version, Collection $fields, array $answers): void
    {
        $projector = app(AnswerIndexProjector::class);

        foreach ($fields as $field) {
            $projected = $projector->project($field, $answers[$field->key] ?? null);

            if ($projected === null) {
                continue; // unflagged, empty, or array-valued — all three project nothing, all three intended
            }

            SubmissionAnswerIndex::updateOrCreate(
                ['submission_id' => $submission->id, 'form_field_id' => $field->id],
                [
                    'tenant_id' => $submission->tenant_id,
                    'form_version_id' => $version->id,
                    'field_key' => $field->key,
                    $projected['column'] => $projected['value'],
                ],
            );
        }
    }

    /**
     * A plausible answer document, derived PURELY from the row index.
     *
     * ⚠️ No `fake()` and no `Str::random()` anywhere in here. A random answer would make each re-seed write
     * different values under the same deterministic key, so the idempotency test would redden intermittently
     * and look like a fixture bug rather than a seeding one.
     *
     * @param  Collection<int, FormField>  $fields
     * @return array<string, mixed>
     */
    private function demoAnswers(Collection $fields, int $seq): array
    {
        $districts = ['Malate', 'Sampaloc', 'Tondo', 'Ermita', 'Paco'];
        $names = ['A. Reyes', 'B. Santos', 'C. Dela Cruz', 'D. Bautista', 'E. Ramos', 'F. Garcia'];
        $answers = [];

        foreach ($fields as $field) {
            $options = $field->config['options'] ?? null;
            $firstOptions = is_array($options) ? array_column($options, 'value') : [];

            $value = match ($field->field_type) {
                FieldType::ShortText => $field->key === 'district'
                    // One row in nine leaves the district blank on purpose: a 100% coverage figure proves
                    // nothing about the explorer's own disclosure, which is the thing the page must render.
                    ? ($seq % 9 === 0 ? null : $districts[$seq % count($districts)])
                    : $names[$seq % count($names)],
                FieldType::LongText => $seq % 4 === 0 ? 'Referred for follow-up in two weeks.' : 'No further notes.',
                FieldType::Integer => 18 + ($seq * 13) % 62,
                FieldType::Decimal => 1.5 + ($seq % 20) / 10,
                FieldType::YesNo => $seq % 5 !== 0,
                FieldType::Date => CarbonImmutable::now()->utc()->subDays($seq % 90)->toDateString(),
                FieldType::Email => 'respondent'.($seq % 50).'@example.test',
                FieldType::SingleSelect, FieldType::Dropdown, FieldType::LikertScale => $firstOptions === []
                    ? null
                    : $firstOptions[$seq % count($firstOptions)],
                FieldType::MultiSelect => $firstOptions === []
                    ? []
                    : array_slice($firstOptions, 0, 1 + ($seq % max(1, count($firstOptions)))),
                default => null, // geo, media, matrices, notes and page breaks are left unanswered
            };

            if ($value !== null) {
                $answers[$field->key] = $value;
            }
        }

        return $answers;
    }

    /**
     * ~420 rows over 91 days on the workhorse form, plus ~45 across the scheduled and branching forms.
     *
     * The weekly rhythm and the four genuinely empty days are what make the dashboard trend a readable shape
     * rather than a flat band, and make zero-filling visible as a floor rather than as a plausible line.
     *
     * @return list<array{back: int, hour: int, seq: int, status: SubmissionStatus, source: SubmissionSource, locale: string, saved: bool, fill_seconds: int}>
     */
    public static function historySpec(int $days = 90): array
    {
        $weekly = [6, 7, 5, 4, 6, 3, 1]; // 32/week ⇒ ≈416 over 91 days
        $rows = [];
        $seq = 0;

        for ($back = $days; $back >= 0; $back--) {
            $count = $back % 29 === 0 ? 0 : $weekly[$back % 7];

            for ($i = 0; $i < $count; $i++, $seq++) {
                $rows[] = self::specRow($back, $seq);
            }
        }

        return $rows;
    }

    /**
     * A short spread for the thin second workspace.
     *
     * @return list<array{back: int, hour: int, seq: int, status: SubmissionStatus, source: SubmissionSource, locale: string, saved: bool, fill_seconds: int}>
     */
    public static function secondWorkspaceSpec(int $count = 14): array
    {
        $rows = [];

        for ($seq = 0; $seq < $count; $seq++) {
            $rows[] = self::specRow(($seq * 5) % 60, $seq);
        }

        return $rows;
    }

    /**
     * A spread across the full 90 days for a secondary form.
     *
     * ⚠️ `$count` TRUNCATES THE SEQUENCE; IT MUST NEVER APPEAR IN A DERIVATION. Every value comes from
     * `$seq` alone, which is what makes rows 0..n of a short run byte-identical to rows 0..n of a long one
     * — and that identity is the entire reason the idempotency test can prove convergence at a dozen rows
     * and have it mean something about several hundred.
     *
     * @return list<array{back: int, hour: int, seq: int, status: SubmissionStatus, source: SubmissionSource, locale: string, saved: bool, fill_seconds: int}>
     */
    public static function secondaryFormSpec(int $count): array
    {
        $rows = [];

        for ($seq = 0; $seq < $count; $seq++) {
            $rows[] = self::specRow(($seq * 11) % 90, $seq);
        }

        return $rows;
    }

    /**
     * One spec row, derived purely from its index.
     *
     * The `% 20` distributions are the point: 45% plain submitted, 5% screened out, 20% approved, 10% under
     * review, 10% returned, 5% archived and 5% still draft gives every inbox filter and every Badge variant a
     * non-empty result. The screened-out band (I9a) was taken from `submitted` rather than appended, so the
     * draft-to-countable ratio this fixture's consumers assert stays exactly where it was — only the split
     * WITHIN the countable rows moved. The source mix matters for a different reason — ADR-0011 §D5's completion denominator is
     * `last_saved_at IS NOT NULL AND source IN (guest, manual)`, so a fixture that was all one source would
     * render the completion tiles as a suppression state instead of a number.
     *
     * @return array{back: int, hour: int, seq: int, status: SubmissionStatus, source: SubmissionSource, locale: string, saved: bool, fill_seconds: int}
     */
    private static function specRow(int $back, int $seq): array
    {
        $bucket = $seq % 20;

        return [
            'back' => $back,
            'seq' => $seq,
            'hour' => 8 + ($seq % 9),
            'status' => match (true) {
                $bucket < 9 => SubmissionStatus::Submitted,
                $bucket < 10 => SubmissionStatus::ScreenedOut,
                $bucket < 14 => SubmissionStatus::Approved,
                $bucket < 16 => SubmissionStatus::UnderReview,
                $bucket < 18 => SubmissionStatus::Returned,
                $bucket < 19 => SubmissionStatus::Archived,
                default => SubmissionStatus::Draft,
            },
            'source' => match (true) {
                $bucket < 13 => SubmissionSource::Guest,
                $bucket < 19 => SubmissionSource::Manual,
                default => SubmissionSource::ApiImport,
            },
            'locale' => $seq % 5 === 0 ? 'es' : 'en',
            'saved' => $seq % 3 === 0,
            'fill_seconds' => 120 + (($seq * 37) % 900),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────
    // Audits, notifications, feedback, settings
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Audit variety across all eight `AuditEvent` cases, spread over ~80 days.
     *
     * ── THE BACK-DATING IS A DELIBERATE, SEEDER-CONFINED DEVIATION ──────────────────────────────────────
     * `audits` is `append_only` RLS — strict SELECT, strict INSERT, and NO UPDATE OR DELETE POLICY AT ALL —
     * so the `forceFill(...)->saveQuietly()` idiom used for notifications and submissions is structurally
     * impossible here. `AuditLogger::record()` deliberately takes no `occurredAt`, and `E2eSeeder.php:1096`
     * forbids adding one for a seeder's benefit; that stands, and this does not add one.
     *
     * Instead the historical rows are INSERTed with an explicit `created_at`, which the append-only policy
     * permits: it constrains WHICH rows may be inserted (`tenant_id` must equal the ambient GUC), and
     * supplying a timestamp on an INSERT is not an UPDATE. The reason it is worth doing at all is that a
     * human is about to test this page's DATE FILTERS, and a ledger where every row landed at `now()` gives
     * them nothing to select over.
     *
     * The payloads are still redacted BY `AuditRedactor` — `AuditLogger`'s own injected collaborator, reused
     * here rather than re-implemented. That is the same move point (D) makes about `AnswerIndexProjector`:
     * hand-roll only the INSERT, and reuse the production writer's collaborator for anything that could
     * drift. Nothing about `redacted_fields` is authored; only `created_at` is supplied.
     *
     * ⚠️ `auditable_id` is `uuid NOT NULL`. For any target whose primary key is not a uuid, key on the
     * owning tenant or user instead — the I2 rule. Every row here keys on a uuid PK or on the tenant.
     */
    private function seedDemoAudits(User $owner, User $editor): void
    {
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId === null) {
            return;
        }

        $forms = Form::query()->orderBy('title')->get();

        if ($forms->isEmpty()) {
            return;
        }

        $redactor = app(AuditRedactor::class);
        $submissionId = Submission::query()->orderByDesc('id')->value('id');

        foreach (AuditEvent::cases() as $index => $event) {
            foreach ([0, 1, 2] as $repeat) {
                $ordinal = $index * 3 + $repeat;
                $at = CarbonImmutable::now()->utc()->subDays(80 - $ordinal * 3)->setTime(9 + ($ordinal % 8), 15);
                $id = self::fixtureUuid(self::FIXTURE_PREFIX."audit:{$event->value}:{$repeat}");

                if (Audit::query()->whereKey($id)->exists()) {
                    continue; // append-only: an existing row can never be rewritten, and need not be
                }

                // Every third row describes a SUBMISSION rather than a form, and carries the two keys
                // `AuditRedactor::PII['submission']` names — so the back-dated tail contains genuinely
                // redacted rows too, rather than only the two live ones below.
                $onSubmission = $submissionId !== null && $ordinal % 3 === 2;
                $form = $forms->get($ordinal % $forms->count());

                if (! $form instanceof Form) {
                    continue;
                }

                $old = $event === AuditEvent::Created ? null : ($onSubmission
                    ? ['status' => 'submitted', 'guest_contact_email' => 'respondent'.$ordinal.'@example.test']
                    : ['title' => $form->title.' (previous)']);
                $new = $event === AuditEvent::Deleted ? null : ($onSubmission
                    ? ['status' => 'approved', 'guest_contact_email' => 'respondent'.$ordinal.'@example.test']
                    : ['title' => $form->title]);

                // The redaction is PRODUCED by AuditLogger's own collaborator, never authored here. A
                // hand-written `redacted_fields` would be a fiction that the audit viewer then renders as
                // fact — `E2eSeeder.php:1092-1094` makes the same argument about `Audit::factory()`.
                $auditableType = $onSubmission ? 'submission' : 'form';
                $shaped = $redactor->redact($auditableType, $old, $new);

                // A system row every seventh: nothing else in the seeded data produces `user_id = null`
                // beside `is_system_action = true`, and the viewer has to render it.
                $systemRow = $ordinal % 7 === 6;

                Audit::query()->forceCreate([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'auditable_type' => $auditableType,
                    'auditable_id' => $onSubmission ? (string) $submissionId : (string) $form->getKey(),
                    'event' => $event->value,
                    'old_values' => $shaped['old'],
                    'new_values' => $shaped['new'],
                    'redacted_fields' => $shaped['redacted_fields'],
                    'user_id' => $systemRow ? null : ($ordinal % 2 === 0 ? $owner->getKey() : $editor->getKey()),
                    'is_system_action' => $systemRow,
                    'created_at' => $at,
                ]);
            }
        }

        // ── The recent rows, through the REAL logger, so redaction is genuinely exercised ────────────────
        // Guarded on a sentinel rather than on "does any audit row exist", because the block above has
        // already written some — an emptiness guard here would never fire again after the first seed.
        $sentinel = self::fixtureUuid(self::FIXTURE_PREFIX.'audit:live-sentinel');

        if (Audit::query()->where('auditable_id', $sentinel)->exists()) {
            return;
        }

        $logger = app(AuditLogger::class);

        // A PII-bearing payload, so `AuditRedactor` has something to redact and the guide's §9 has a row
        // that visibly shows `[redacted]` rather than only describing that the mechanism exists.
        $logger->record(
            AuditEvent::Updated,
            'user',
            (string) $owner->getKey(),
            old: ['email' => 'olivia.owner.old@demo.test', 'password' => 'not-a-real-secret'],
            new: ['email' => 'owner@demo.test', 'password' => 'not-a-real-secret-either'],
            actorId: (string) $owner->getKey(),
        );

        $logger->record(
            AuditEvent::Exported,
            'submission',
            $sentinel,
            new: ['format' => 'csv', 'rows' => 412],
            actorId: (string) $owner->getKey(),
        );
    }

    /**
     * One notification of every {@see NotificationType}, plus a couple of preference divergences.
     *
     * Keyed on a deterministic uuid and written DIRECTLY rather than through
     * `NotificationDispatcher::record()`, which mints a random key — the one thing an upsert key must never
     * be. Back-dating with `forceFill(...)->saveQuietly()` IS legitimate here, unlike on `audits`:
     * `notifications` carries the ordinary strict UPDATE policy and is deliberately unaudited.
     */
    private function seedDemoNotifications(User $owner, User $reviewer): void
    {
        $submission = Submission::query()->whereNotNull('submitted_at')->orderByDesc('id')->first();
        $form = Form::query()->where('title', self::HISTORY_FORM)->first();
        $formTitle = $form instanceof Form ? $form->title : self::HISTORY_FORM;

        $rows = [];
        foreach (NotificationType::cases() as $index => $type) {
            foreach ([['user' => $owner, 'slot' => 'owner'], ['user' => $reviewer, 'slot' => 'reviewer']] as $target) {
                $ordinal = $index * 2 + ($target['slot'] === 'owner' ? 0 : 1);

                $rows[] = [
                    'key' => "{$type->value}:{$target['slot']}",
                    'user' => (string) $target['user']->getKey(),
                    'type' => $type,
                    'ago' => 15 + $ordinal * 180,
                    // A deterministic read/unread mix, so the bell shows a non-zero badge AND the popover
                    // shows both states rather than a uniform wall.
                    'read' => $ordinal % 3 === 0,
                    'emailed' => $ordinal % 4 === 0,
                    'data' => array_filter([
                        'submission_id' => $submission?->getKey(),
                        'form_title' => $formTitle,
                    ], static fn (mixed $value): bool => $value !== null),
                ];
            }
        }

        foreach ($rows as $row) {
            $id = self::fixtureUuid(self::FIXTURE_PREFIX.'notification:'.$row['key']);
            $at = CarbonImmutable::now()->subMinutes($row['ago']);

            $attributes = [
                'user_id' => $row['user'],
                'type' => $row['type']->value,
                'data' => $row['data'],
                'read_at' => $row['read'] ? $at->addMinutes(5) : null,
                'emailed_at' => $row['emailed'] ? $at : null,
                'created_at' => $at,
                'updated_at' => $at,
            ];

            $existing = Notification::query()->whereKey($id)->first();

            if ($existing instanceof Notification) {
                $existing->forceFill($attributes)->saveQuietly();

                continue;
            }

            // forceCreate, NOT `(new Notification)->forceFill(...)->saveQuietly()`: saveQuietly suppresses
            // model EVENTS, which on this model are BelongsToTenant::creating (which fills tenant_id, and
            // without which the strict-RLS WITH CHECK rejects the row) and HasUuidv7::creating. forceCreate
            // fires both, and HasUuidv7 only fills an EMPTY key, so the deterministic id survives.
            Notification::query()->forceCreate([...$attributes, 'id' => $id]);
        }

        // ⚠️ BOTH booleans, always. The columns default `true/true`, which DISAGREES with
        // `NotificationType::SubmissionReceived::defaultEmail() === false` — so writing only one of them
        // would silently mean something other than what it reads as. The table is sparse by design, so this
        // is two rows rather than fourteen.
        $resolver = app(NotificationPreferenceResolver::class);
        $resolver->set($owner, NotificationType::WebhookFailed, true, true);
        $resolver->set($reviewer, NotificationType::ReviewRequested, false, false);
    }

    /**
     * Feedback across all four statuses.
     *
     * `FeedbackReport` has `public $timestamps = false` and its timeline is `submitted_at`/`resolved_at`, so
     * the spread needs no `forceFill` back-dating at all. There is no factory and no service — the
     * controller's bare `create()` is the only writer in the tree, so this mirrors it.
     *
     * @param  array<string, User>  $people
     */
    private function seedFeedback(array $people): void
    {
        $rows = [
            ['slot' => 'owner@demo.test', 'route' => '/dashboard', 'status' => FeedbackStatus::New, 'ago' => 2, 'remarks' => 'The date range picker resets when I switch tabs.'],
            ['slot' => 'reviewer@demo.test', 'route' => '/submissions', 'status' => FeedbackStatus::Reviewed, 'ago' => 9, 'remarks' => 'Could the inbox remember my last filter?'],
            ['slot' => 'editor@demo.test', 'route' => '/forms', 'status' => FeedbackStatus::Resolved, 'ago' => 21, 'remarks' => 'Duplicating a field lost its options — fixed now, thanks.'],
            ['slot' => 'viewer@demo.test', 'route' => '/analytics', 'status' => FeedbackStatus::WontFix, 'ago' => 40, 'remarks' => 'Please add a pie chart.'],
        ];

        foreach ($rows as $row) {
            $user = $people[$row['slot']] ?? null;

            if (! $user instanceof User) {
                continue;
            }

            $id = self::fixtureUuid(self::FIXTURE_PREFIX.'feedback:'.$row['slot'].':'.$row['route']);
            $submittedAt = CarbonImmutable::now()->utc()->subDays($row['ago']);
            $resolved = in_array($row['status'], [FeedbackStatus::Resolved, FeedbackStatus::WontFix], true);

            $attributes = [
                'user_id' => $user->getKey(),
                'route' => $row['route'],
                'remarks' => $row['remarks'],
                'browser_info' => ['viewport' => '1440x900', 'platform' => 'demo-seed'],
                'status' => $row['status']->value,
                'submitted_at' => $submittedAt,
                'resolved_at' => $resolved ? $submittedAt->addDays(2) : null,
                'resolved_by' => $resolved ? ($people['owner@demo.test'] ?? $user)->getKey() : null,
            ];

            $existing = FeedbackReport::query()->whereKey($id)->first();

            if ($existing instanceof FeedbackReport) {
                $existing->forceFill($attributes)->save();

                continue;
            }

            FeedbackReport::query()->forceCreate([...$attributes, 'id' => $id]);
        }
    }

    /**
     * Write tenant settings through the real registry, which also emits the single audit row per change.
     *
     * Safe inside the seeder's outer transaction: `put()` opens a nested one (a savepoint), reads under the
     * AMBIENT context and writes an explicit `tenant_id` — it never moves the GUC.
     *
     * ⚠️ THE NO-OP DIFF IS WHAT MAKES THE WHOLE SEEDER CONVERGE. `put()` audits unconditionally and is right
     * to — "nobody touched it" and "somebody opened it and saved" are different facts, which is its own
     * documented rule. But `audits` is append-only, so an unconditional call here would add a
     * `settings updated` row on EVERY re-seed, for a change that did not happen, and the ledger would grow
     * without bound on a developer's box. Diffing first means the seeder makes no act to record.
     *
     * @param  array<string, bool|string>  $values
     */
    private function seedTenantSettings(Tenant $tenant, User $actor, array $values): void
    {
        $registry = app(TenantSettingRegistry::class);
        $current = $registry->all();

        $changed = array_filter(
            $values,
            static fn (bool|string $value, string $key): bool => ($current[$key] ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changed === []) {
            return;
        }

        $registry->put($tenant, $changed, $actor);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────
    // Shared helpers
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Tenants and domains are RLS-exempt central tables, so this runs outside any tenant context. */
    private function resolveTenant(string $slug, string $name): Tenant
    {
        $tenant = Tenant::firstOrCreate(['slug' => $slug], ['name' => $name]);

        if (! $tenant->domains()->where('domain', $slug)->exists()) {
            $tenant->domains()->create(['domain' => $slug]);
        }

        // ⚠️ NO CUSTOM DOMAIN IS EVER SEEDED HERE, ACTIVATED OR OTHERWISE. An activated row becomes visible
        // to Domain's global `resolvable` scope, which is what `TenantUrl::toPublic()` reads — so it would
        // repoint every public and resume link in the demo onto a hostname no browser can resolve, and the
        // failure would present as a public-runtime defect. The guide's §14 has the tester CLAIM one instead,
        // which exercises the same surface and stops at verification, exactly as the product intends.
        return $tenant;
    }

    /**
     * Identities are resolved on the pre-auth connection: the join-shape `users` RLS admits only the current
     * user and ACTIVE co-tenants, so a seeded identity is INVISIBLE rather than absent from the default
     * connection, and a naive lookup would try to insert a duplicate email.
     *
     * ⚠️ THE PER-RUN MEMO IS LOAD-BEARING, NOT AN OPTIMISATION. `consultant@demo.test` belongs to BOTH
     * workspaces, so it is resolved twice in one `run()`. Outside a transaction the first `create()` has
     * already committed and the second lookup finds it — but under `RefreshDatabase` the whole run sits in
     * ONE uncommitted transaction on the default connection, which `pgsql_auth` (a separate connection)
     * cannot see. Without this memo the second resolve would take the create arm and raise a 23505 on the
     * unique email, in tests only, for a seeder that works perfectly from the CLI.
     *
     * @var array<string, User>
     */
    private array $resolvedUsers = [];

    /**
     * The identities this run resolved or created, keyed by email.
     *
     * A seam for `DemoSeederIdempotencyTest`, which cannot re-read them itself: under `RefreshDatabase` the
     * users sit in an uncommitted transaction on the default connection, invisible to `pgsql_auth`, while
     * the default connection's own join-shape `users` RLS hides anyone the ambient context is not a
     * co-member of. Handing back the models the seeder actually wrote sidesteps both, and asserts the thing
     * that matters anyway — what was seeded, not what a second query can find.
     *
     * @return array<string, User>
     */
    public function seededUsers(): array
    {
        return $this->resolvedUsers;
    }

    private function resolveOrCreateUser(string $email, string $name, string $password): User
    {
        if (isset($this->resolvedUsers[$email])) {
            return $this->resolvedUsers[$email];
        }

        $existing = User::on('pgsql_auth')->where('email', $email)->first();

        if ($existing instanceof User) {
            $existing->setConnection((string) config('database.default'));

            return $this->resolvedUsers[$email] = $existing;
        }

        return $this->resolvedUsers[$email] = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Memberships + roles for the ambient tenant.
     *
     * @param  array<string, User>  $people
     * @param  array<string, array{role: string, status: string}>|null  $overrides
     */
    private function seedMemberships(array $people, ?array $overrides = null): void
    {
        $spec = $overrides ?? collect(self::DEMO_PEOPLE)
            ->mapWithKeys(static fn (array $p): array => [$p['email'] => ['role' => $p['role'], 'status' => $p['status']]])
            ->all();

        foreach ($spec as $email => $definition) {
            $user = $people[$email] ?? null;

            if (! $user instanceof User) {
                continue;
            }

            $invited = $definition['status'] === 'invited';

            if (TenantUser::query()->where('user_id', $user->getKey())->exists()) {
                continue;
            }

            TenantUser::create(array_filter([
                'user_id' => $user->getKey(),
                'status' => $invited ? TenantUserStatus::Invited : TenantUserStatus::Active,
                'joined_at' => $invited ? null : now(),
                'invited_role_id' => $this->roleId($definition['role']),
                'invited_by' => $invited ? ($people['owner@demo.test'] ?? $user)->getKey() : null,
                'invited_at' => $invited ? now() : null,
                'invite_expires_at' => $invited ? now()->addDays(7) : null,
                'invite_token' => $invited ? hash('sha256', 'i6:invite:'.$email) : null,
            ], static fn (mixed $value): bool => $value !== null));

            if (! $invited) {
                $user->syncRoles([$definition['role']]);
            }
        }
    }

    /**
     * Assign the tier, then DROP THE ENTITLEMENT MEMO.
     *
     * ⚠️ The memo is why the order matters. `EntitlementService` caches its snapshot per tenant, and
     * `FormService::create()` hard-blocks on the `forms_count` quota — so a read taken before the
     * subscription is written would memoize the *previous* tier for the rest of the process, and every quota
     * bar the demo renders would then disagree with the plan the tenant actually holds. Assign first, forget
     * second, build forms third.
     */
    private function assignPlan(PlanTier $tier, Tenant $tenant): void
    {
        $planId = Plan::query()->where('code', $tier->value)->value('id');

        if ($planId === null) {
            return;
        }

        Subscription::updateOrCreate(
            ['name' => 'default'],
            [
                'plan_id' => $planId,
                'stripe_status' => 'active',
                'billing_interval' => BillingInterval::Monthly,
            ],
        );

        app(EntitlementService::class)->forget((string) $tenant->getKey());
    }

    /**
     * Per-form grants for the two collaboration-scoped roles.
     *
     * `ResourceGrantService::grant()` re-resolves and updates an existing grant, so it is already an upsert
     * and can be called unconditionally on a re-seed.
     *
     * @param  array<string, User>  $people
     */
    private function seedGrants(User $actor, array $people): void
    {
        // ⚠️ DELIBERATELY EXCLUDES THE WORKHORSE FORM, AND THAT IS THE WHOLE POINT OF THE GRANT SET.
        // `Patient Intake` carries ~420 of the ~520 seeded submissions. Granting it would leave a reviewer
        // seeing 446 rows where an Owner sees 497 — correct, but a difference nobody would notice, so the
        // permission model would look like it was not doing anything. Granting the other three instead makes
        // the contrast plain (roughly a hundred rows against five hundred) while still giving the reviewer a
        // real queue across three forms. `docs/TESTING-GUIDE.md` §6 has the tester compare exactly this.
        $granted = Form::query()
            ->whereNotNull('current_published_version_id')
            ->where('title', '!=', self::HISTORY_FORM)
            ->orderBy('title')
            ->limit(3)
            ->get();

        $service = app(ResourceGrantService::class);

        foreach ($granted as $form) {
            foreach ([
                ['user' => $people['reviewer@demo.test'] ?? null, 'capacity' => ResourceCapacity::Reviewer],
                ['user' => $people['editor@demo.test'] ?? null, 'capacity' => ResourceCapacity::Editor],
            ] as $recipient) {
                if (! $recipient['user'] instanceof User) {
                    continue;
                }

                // `grant()` is already an upsert, but it audits unconditionally — and `audits` is
                // append-only, so calling it on every re-seed would add six `permission_changed` rows a
                // seed, forever. Skip when the grant already reads exactly as intended; same argument as
                // seedTenantSettings().
                $exists = ResourceGrant::query()
                    ->where('scopeable_type', $form->getMorphClass())
                    ->where('scopeable_id', $form->getKey())
                    ->where('user_id', $recipient['user']->getKey())
                    ->where('capacity', $recipient['capacity']->value)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $service->grant($actor, $recipient['user'], $form, $recipient['capacity']);
            }
        }
    }

    /**
     * Scale a secondary-form row count by {@see $historyDays}, so turning the history down turns the whole
     * fixture down together. Never below two — a one-row form cannot show an ordering or a trend.
     */
    private function scaled(int $count): int
    {
        if ($this->historyDays >= 90) {
            return $count;
        }

        return max(2, (int) round($count * $this->historyDays / 90));
    }

    private function roleId(string $name): string
    {
        return (string) Role::query()->whereNull('tenant_id')->where('name', $name)->value('id');
    }

    private function releaseContext(): void
    {
        TenantContext::flush();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
