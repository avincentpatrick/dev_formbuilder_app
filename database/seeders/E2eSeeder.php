<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnalyticsAxis;
use App\Enums\AuditEvent;
use App\Enums\BillingInterval;
use App\Enums\ConnectionStatus;
use App\Enums\DomainEventType;
use App\Enums\DomainVerificationFailure;
use App\Enums\FeedbackStatus;
use App\Enums\FieldType;
use App\Enums\FormScheduleState;
use App\Enums\NotificationType;
use App\Enums\PlanTier;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionPdfOutcome;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\TenantUserStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEndpointStatus;
use App\Models\Audit;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Domain;
use App\Models\FeedbackReport;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SavedReportView;
use App\Models\ScopeNode;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Authorization\ResourceGrantService;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Notifications\NotificationPreferenceResolver;
use App\Services\Scoping\ScopeNodeService;
use App\Services\Submissions\AnswerIndexProjector;
use App\Services\Webhooks\WebhookEndpointService;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditRedactor;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\DeterministicIds;
use Database\Seeders\Concerns\SeedsGamificationLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deterministic fixture for the Playwright e2e / responsive-axe gate (and a convenient local demo).
 * Creates a tenant `acme` reachable at acme.{central_domain}, an Owner login, and one pending invite so
 * the Members roster exercises multiple Badge variants. Guarded against production. Idempotent: safe to
 * re-run (identities are looked up on the pre-auth connection since the join-shape users RLS hides them).
 *
 * Login: demo@meridian.test / meridian-e2e-2026
 */
class E2eSeeder extends Seeder
{
    use DeterministicIds;
    use SeedsGamificationLedger;

    private const OWNER_EMAIL = 'demo@meridian.test';

    /**
     * ⚠️ DO NOT "FIX" THIS TO SATISFY J3a'S FOUR CHARACTER CLASSES. IT IS EXEMPT BY CONSTRUCTION.
     *
     * Every seeded password is written through `Hash::make()` and read back by `Hash::check()` on the LOGIN
     * path, which has no opinion whatever about character classes — `Password::defaults()` governs a password
     * being CHOSEN, not one being verified. Nothing in the suite re-registers or resets with these strings:
     * their only consumers are `POST /login` here and in `tests/e2e/global-setup.ts`.
     *
     * Changing them would churn this file, {@see DemoSeeder}, `tests/e2e/global-setup.ts`,
     * `tests/e2e/support/console.ts`, `docs/TESTING-GUIDE.md` and the tracker's next-prompt — for zero
     * behavioural gain, while breaking every credential the user has memorised.
     */
    private const OWNER_PASSWORD = 'meridian-e2e-2026';

    /** The central-domain console operator (Increment I10e) — see seedSuperAdmin(). */
    private const SUPER_ADMIN_EMAIL = 'console@meridian.test';

    private const SUPER_ADMIN_PASSWORD = 'meridian-console-2026';

    private const PENDING_EMAIL = 'pending@meridian.test';

    /**
     * A KNOWN password for the unverified placeholder (J3b). It previously carried `Str::random(48)`,
     * which is right for a placeholder nobody signs in as — but the verify-email accessibility scan has
     * to actually BE that person, and `/email/verify` sits behind `auth`. Signing in is the only way to
     * reach the page a locked-out member sees.
     */
    private const PENDING_PASSWORD = 'meridian-e2e-2026';

    /**
     * A deterministic invite token (J3b), so `/invitations/{token}` is addressable by a spec.
     *
     * The stored column is the SHA-256 of this string — `InvitationController::resolvePendingInvite()`
     * hashes what it is given and matches on the digest, so the plaintext never touches the database and
     * a fixed value here leaks nothing that a fixed seeded password does not. It was `Str::random(48)`
     * before, which is correct for a real invite and makes the page unreachable to a test.
     */
    private const INVITE_TOKEN = 'e2e-invitation-token-do-not-reuse-in-production';

    /**
     * A user enrolled in two-factor (J3b), for the `/two-factor-challenge` scan.
     *
     * ⚠️ DELIBERATELY NOT A MEMBER OF ANY WORKSPACE. Fortify authenticates on credentials alone, and
     * `RedirectIfTwoFactorAuthenticatable` diverts to the challenge BEFORE the login completes — so no
     * membership is needed to reach that page. Giving them one would add a row to `/members`, which
     * `responsive-axe` already loads, for no benefit: this identity exists to be stopped at the door.
     */
    private const TWO_FACTOR_EMAIL = 'twofactor@meridian.test';

    private const TWO_FACTOR_PASSWORD = 'meridian-e2e-2026';

    /**
     * A syntactically valid base32 TOTP secret. It is never used to derive a code — the scan renders the
     * challenge form and stops — but `hasEnabledTwoFactorAuthentication()` reads the column for
     * null-ness, so a placeholder that could not be decoded would be a trap for the next person who
     * tries to extend this fixture into a full sign-in.
     */
    private const TWO_FACTOR_SECRET = 'ABCDEFGHIJKLMNOP';

    /**
     * The password-reset fixture (J3b). Plaintext here, `Hash::make()`d into `password_reset_tokens`,
     * because Laravel's DatabaseTokenRepository stores a hash and compares with `Hash::check()`.
     *
     * ⚠️ THE PAGE ITSELF NEEDS NONE OF THIS, AND THAT IS WORTH KNOWING BEFORE SOMEBODY "FIXES" A SCAN
     * THAT LOOKS UNDER-BUILT. Fortify's `GET /reset-password/{token}` renders the form without
     * consulting the token at all — validation happens on POST — so the DOM an accessibility scan sees
     * is identical for any token string. The row is seeded anyway so the fixture is HONEST: a future
     * test that submits the form has a token that actually resolves, rather than discovering on the day
     * that the scan had been passing over a dead link.
     */
    private const RESET_TOKEN = 'e2e-password-reset-token';

    /** A second ACTIVE member (G10b2) — the grant modal needs a recipient other than the acting Owner. */
    private const REVIEWER_EMAIL = 'reviewer@meridian.test';

    private const REVIEWER_PASSWORD = 'meridian-e2e-2026';

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        // The fixed role/permission catalog must exist before we assign roles.
        $this->call(RolePermissionSeeder::class);

        // The platform form-template gallery (G9a) — NULL-tenant rows so the demo/e2e env has content in
        // the "New from template" gallery. Seeded via the privileged connection, like the RBAC catalog.
        $this->call(PlatformTemplateSeeder::class);

        // The platform question library (G9b) — NULL-tenant rows so the builder's Library picker has content.
        $this->call(PlatformFieldLibrarySeeder::class);

        // The global plan catalog (H5a) — the tenant_id-free `plans` table, on the default connection.
        $this->call(PlanSeeder::class);

        // Tenant + subdomain (tenants/domains are RLS-exempt central tables).
        $tenant = Tenant::firstOrCreate(['slug' => 'acme'], ['name' => 'Acme Research']);
        if (! $tenant->domains()->where('domain', 'acme')->exists()) {
            $tenant->domains()->create(['domain' => 'acme']);
        }

        // I10e — the central-domain console operator. Before the tenant work below and deliberately outside
        // it: this writes one central `users` row and touches nothing tenant-scoped.
        $this->seedSuperAdmin();

        $this->seedAuthScanFixtures();

        $owner = $this->resolveOrCreateUser(self::OWNER_EMAIL, 'Demo Owner', self::OWNER_PASSWORD);
        // ⚠️ THE ONE IDENTITY THAT MUST STAY UNVERIFIED, AND IT IS NOT AN OVERSIGHT.
        // Stamping this placeholder turns the invitation page from "set a name and a password" into "sign in
        // as the invited account" — silently breaking the invite fixture, and with it the invitation
        // accessibility scan J3b adds. It is also the only unverified user in the fixture, which makes it the
        // natural subject for the `verified` gate's own e2e coverage.
        //
        // ⚠️ M8 CHANGED THE REASON WITHOUT CHANGING THE REQUIREMENT, WHICH IS EXACTLY THE KIND OF NOTE THAT
        // GOES STALE UNREAD. This used to say `InvitationController::show()` reads `email_verified_at === null`
        // as `needsRegistration`. It no longer reads that column directly: it asks
        // {@see \App\Services\Tenancy\TenantMembershipService::identityIsEstablished()}, for which a verified
        // address is one of FOUR positive signals — the others being a confirmed second factor, a linked
        // Google account, and a `tenant_users` row this person actually joined. This fixture has none of the
        // other three, so it stays a registration case and the axe scan keeps scanning the password form.
        // **Give it any of them and the page changes shape just as surely as verifying it would.**
        $pending = $this->resolveOrCreateUser(self::PENDING_EMAIL, 'Pending Teammate', self::PENDING_PASSWORD, verified: false);
        $reviewer = $this->resolveOrCreateUser(self::REVIEWER_EMAIL, 'Rita Reviewer', self::REVIEWER_PASSWORD);

        // Active Owner membership + a pending invite. Wrapped in a transaction so applyLocal's
        // transaction-local RLS GUC (app.current_tenant_id) is actually in force for the INSERTs —
        // outside a transaction it wouldn't stick and the strict RLS WITH CHECK would reject the rows.
        DB::transaction(function () use ($tenant, $owner, $pending, $reviewer): void {
            TenantContext::applyLocal((string) $tenant->id, $owner->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId((string) $tenant->id);

            // Give acme a BUSINESS plan (H24a / ADR-0011 §D9). Business is the only tier carrying
            // `advanced_analytics`, and it is seeded `is_active: false` — held from sale until the production
            // host is stood up (ADR-0008 §D6) — so a super-admin assignment like this one is the ONLY way a
            // tenant reaches it. Without it H24b's merge-blocking axe spec would stay green over a page it
            // could never load, which §D9 names as a blocking obligation on this fixture.
            //
            // Strictly additive over the Professional plan this replaced: PlanCatalog defines
            // `$business = [...$professional, 'custom_domain', 'advanced_analytics']` and Business quotas are
            // unlimited, so every quota guard stays as inert as before and no seeded artifact changes.
            //
            // UPSERT, not create-if-absent. The previous shape was guarded by
            // `if (! Subscription::where('name','default')->exists())`, so on any already-seeded database a
            // tier change here would SILENTLY NO-OP: CI (a fresh database) would go green while every
            // developer's box stayed on Professional and the analytics routes 402'd. PROGRESS.md records the
            // same trap for this seeder's doesntExist()-guarded blocks generally.
            $businessId = Plan::query()->where('code', PlanTier::Business->value)->value('id');
            if ($businessId !== null) {
                Subscription::updateOrCreate(
                    ['name' => 'default'],
                    [
                        'plan_id' => $businessId,
                        'stripe_status' => 'active',
                        'billing_interval' => BillingInterval::Monthly,
                    ],
                );
            }

            if (! TenantUser::query()->where('user_id', $owner->id)->exists()) {
                TenantUser::create([
                    'user_id' => $owner->id,
                    'status' => TenantUserStatus::Active,
                    'joined_at' => now(),
                    'invited_role_id' => $this->roleId('owner'),
                ]);
                $owner->syncRoles(['owner']);
            }

            if (! TenantUser::query()->where('user_id', $pending->id)->exists()) {
                TenantUser::create([
                    'user_id' => $pending->id,
                    'status' => TenantUserStatus::Invited,
                    'invited_role_id' => $this->roleId('viewer'),
                    'invited_by' => $owner->id,
                    'invited_at' => now(),
                    'invite_expires_at' => now()->addDays(7),
                    'invite_token' => hash('sha256', self::INVITE_TOKEN),
                ]);
            }

            // A second ACTIVE member (Increment G10b2). Until now the tenant had exactly one active member
            // (the Owner) plus one *invited* row — and the `users_visibility` RLS policy admits only ACTIVE
            // co-tenants, so the grant modal's recipient list would render EMPTY and the scopes e2e spec
            // could not complete a grant. Must exist before the grant below, which resolves them.
            if (! TenantUser::query()->where('user_id', $reviewer->id)->exists()) {
                TenantUser::create([
                    'user_id' => $reviewer->id,
                    'status' => TenantUserStatus::Active,
                    'joined_at' => now(),
                    'invited_role_id' => $this->roleId('reviewer'),
                ]);
                $reviewer->syncRoles(['reviewer']);
            }

            // A demo form for the Forms page (D3) + the builder (D4a): a section + a few fields on the
            // draft, published once (so version history is exercised) and cloned forward into the current
            // draft the builder edits. The content makes the builder auto-select a field so the config
            // panel actually mounts for the interaction/axe gate.
            if (Form::query()->where('title', 'Community Health Survey')->doesntExist()) {
                $form = app(FormService::class)->create(
                    $tenant, $owner, 'Community Health Survey', 'Monthly field data collection.'
                );
                $builder = app(FormBuilderService::class);
                $section = $builder->addSection($form);
                // Repeatable so the builder's section → Advanced tab renders the min/max MdsNumberInputs
                // by default (the interaction-driven builder-axe.spec.ts scans them without a stateful toggle).
                $section->update(['is_repeatable' => true, 'min_instances' => 1, 'max_instances' => 5]);
                $builder->addField($form, $owner, FieldType::ShortText, $section->id);
                $builder->addField($form, $owner, FieldType::SingleSelect, $section->id);
                $builder->addField($form, $owner, FieldType::Integer, null);
                app(PublishService::class)->publish($form->refresh(), $owner);
            }

            // A second form left as an empty draft — the builder's empty-canvas state must also be
            // axe-clean (Increment D4b builder-axe.spec.ts scans it).
            if (Form::query()->where('title', 'Blank Intake Form')->doesntExist()) {
                app(FormService::class)->create($tenant, $owner, 'Blank Intake Form', 'An empty draft.');
            }

            // An all-scalar, repeat-free published form — the manual-encode target (Increment F4b). One of
            // every Phase-1 encodable control (text, number, select, multi-select, yes/no, date, long text)
            // so the encode page's responsive/axe gate scans them all. The seeded "Community Health Survey"
            // has a repeatable section (unsupported for manual entry), so it can't be that target.
            if (Form::query()->where('title', 'Clinic Intake')->doesntExist()) {
                $intake = app(FormService::class)->create(
                    $tenant, $owner, 'Clinic Intake', 'All-scalar manual-encoding demo.'
                );
                $b = app(FormBuilderService::class);
                $section = $b->addSection($intake); // non-repeatable by default → its fields are encodable
                $b->addField($intake, $owner, FieldType::ShortText, $section->id);
                $b->addField($intake, $owner, FieldType::Integer, $section->id);
                $b->addField($intake, $owner, FieldType::SingleSelect, $section->id)
                    ->update(['config' => ['options' => [
                        ['value' => 'female', 'label' => 'Female'],
                        ['value' => 'male', 'label' => 'Male'],
                        ['value' => 'other', 'label' => 'Other'],
                    ]]]);
                $b->addField($intake, $owner, FieldType::MultiSelect, $section->id)
                    ->update(['config' => ['options' => [
                        ['value' => 'fever', 'label' => 'Fever'],
                        ['value' => 'cough', 'label' => 'Cough'],
                        ['value' => 'fatigue', 'label' => 'Fatigue'],
                    ]]]);
                $b->addField($intake, $owner, FieldType::YesNo, $section->id);
                $b->addField($intake, $owner, FieldType::Date, $section->id);
                $b->addField($intake, $owner, FieldType::LongText, $section->id);
                app(PublishService::class)->publish($intake->refresh(), $owner);

                // Guest-enable it so the public runtime SPA (Increment F6b) can be reached at
                // /f/clinic-intake, and give it two supported locales so the language switcher renders in
                // the public-runtime axe scan. Targeted update — leaves the publish columns untouched.
                $intake->update([
                    'public_slug' => 'clinic-intake',
                    'allow_guest_submissions' => true,
                    'supported_locales' => ['en', 'es'],
                    // Increment H10 — opt this guest form into save-and-resume so the public runtime renders the
                    // "Save and finish later" control (acme's Professional plan includes the feature), giving the
                    // control + its dialog a11y coverage in the public-runtime axe scan.
                    'save_and_resume' => true,
                ]);
            }

            // A guest-enabled form with a REPEATABLE section (Increment G2) — the add/remove-instance runtime
            // + manual-encode loop. Reached publicly at /f/household-roster and as a "New submission" encode
            // target. Members are optional so an empty guest load is axe-clean; min 1 seeds one encode row.
            if (Form::query()->where('title', 'Household Roster')->doesntExist()) {
                $roster = app(FormService::class)->create(
                    $tenant, $owner, 'Household Roster', 'Repeatable household-member roster (G2 demo).'
                );
                $rb = app(FormBuilderService::class);
                $rb->addField($roster, $owner, FieldType::ShortText, null)->update(['label' => 'Prepared by']);
                $members = $rb->addSection($roster);
                $members->update(['label' => 'Household members', 'is_repeatable' => true, 'min_instances' => 1, 'max_instances' => 4]);
                $rb->addField($roster, $owner, FieldType::ShortText, $members->id)->update(['label' => 'Member name']);
                $rb->addField($roster, $owner, FieldType::YesNo, $members->id)->update(['label' => 'Is a dependent?']);
                app(PublishService::class)->publish($roster->refresh(), $owner);

                $roster->update([
                    'public_slug' => 'household-roster',
                    'allow_guest_submissions' => true,
                    'supported_locales' => ['en'],
                    // Single-page so the runtime renders the repeat section (and its Add control) on load rather
                    // than behind a Next step — the public-runtime repeat axe scan interacts with it directly.
                    'single_page_mode' => true,
                ]);
            }

            // A guest-enabled form showcasing the Increment G4a/G4b controls — a Likert rating scale (radio
            // group), an N-level cascading (dependent) select, a Likert matrix (radiogroup per row), and a
            // matrix (per-cell select). All optional so an empty guest load is axe-clean. Reached at
            // /f/field-types; the public-runtime axe scan renders every control in light + dark at all widths
            // (the grids must reflow with no horizontal scroll — the merge-blocking G4b a11y requirement).
            if (Form::query()->where('title', 'Field Types Showcase')->doesntExist()) {
                $showcase = app(FormService::class)->create(
                    $tenant, $owner, 'Field Types Showcase', 'Likert scale, cascading select, and grids (G4a/G4b demo).'
                );
                $sb = app(FormBuilderService::class);
                $showSection = $sb->addSection($showcase);
                $sb->addField($showcase, $owner, FieldType::LikertScale, $showSection->id)->update([
                    'label' => 'How satisfied were you?',
                    'config' => ['options' => [
                        ['value' => '1', 'label' => 'Very dissatisfied'],
                        ['value' => '2', 'label' => 'Dissatisfied'],
                        ['value' => '3', 'label' => 'Neutral'],
                        ['value' => '4', 'label' => 'Satisfied'],
                        ['value' => '5', 'label' => 'Very satisfied'],
                    ]],
                ]);
                $sb->addField($showcase, $owner, FieldType::CascadingSelect, $showSection->id)->update([
                    'label' => 'Where are you located?',
                    'config' => [
                        'levels' => [
                            ['key' => 'region', 'label' => 'Region'],
                            ['key' => 'province', 'label' => 'Province'],
                        ],
                        'options' => [
                            ['value' => 'ncr', 'label' => 'NCR', 'level' => 'region', 'parent' => null],
                            ['value' => 'central_visayas', 'label' => 'Central Visayas', 'level' => 'region', 'parent' => null],
                            ['value' => 'manila', 'label' => 'Manila', 'level' => 'province', 'parent' => 'ncr'],
                            ['value' => 'quezon_city', 'label' => 'Quezon City', 'level' => 'province', 'parent' => 'ncr'],
                            ['value' => 'cebu', 'label' => 'Cebu', 'level' => 'province', 'parent' => 'central_visayas'],
                        ],
                    ],
                ]);
                // Likert matrix (Increment G4b): one radio group per row over a shared 1–5 scale.
                $sb->addField($showcase, $owner, FieldType::LikertMatrix, $showSection->id)->update([
                    'label' => 'Rate each aspect of your visit',
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
                            ['value' => '4', 'label' => 'Very good'],
                            ['value' => '5', 'label' => 'Excellent'],
                        ],
                    ],
                ]);
                // Matrix (Increment G4b): a per-cell single-select over a shared choice pool.
                $sb->addField($showcase, $owner, FieldType::Matrix, $showSection->id)->update([
                    'label' => 'Availability by service and day',
                    'config' => [
                        'rows' => [
                            ['value' => 'consult', 'label' => 'Consultation'],
                            ['value' => 'lab', 'label' => 'Lab work'],
                        ],
                        'columns' => [
                            ['value' => 'morning', 'label' => 'Morning'],
                            ['value' => 'afternoon', 'label' => 'Afternoon'],
                        ],
                        'cells' => [
                            ['value' => 'available', 'label' => 'Available'],
                            ['value' => 'limited', 'label' => 'Limited'],
                            ['value' => 'closed', 'label' => 'Closed'],
                        ],
                    ],
                ]);
                // Geo (Increment G5b2): a point (with altitude + accuracy target), a trace, and a shape. All
                // optional so an empty guest load stays axe-clean; the Leaflet map is a progressive enhancement
                // over the always-present manual coordinate inputs + keyboard vertex list.
                $sb->addField($showcase, $owner, FieldType::Geopoint, $showSection->id)->update([
                    'label' => 'Pin your location',
                    'config' => [
                        'capture_altitude' => true,
                        'accuracy_threshold' => 20,
                        'default_center' => ['lat' => 14.5995, 'lon' => 120.9842],
                        'default_zoom' => 11,
                    ],
                ]);
                $sb->addField($showcase, $owner, FieldType::Geotrace, $showSection->id)->update([
                    'label' => 'Trace your route',
                ]);
                $sb->addField($showcase, $owner, FieldType::Geoshape, $showSection->id)->update([
                    'label' => 'Outline the area',
                ]);
                app(PublishService::class)->publish($showcase->refresh(), $owner);

                $showcase->update([
                    'public_slug' => 'field-types',
                    'allow_guest_submissions' => true,
                    'supported_locales' => ['en'],
                    'single_page_mode' => true,
                ]);
            }

            // A guest-enabled but CLOSED scheduled form (Increment H12b) — reached at /f/closed-survey. Its
            // window has passed (closes_at in the past), so the public runtime renders the full-screen "This
            // form is closed" state INSTEAD of the fill session (the schema is still served — H12a). Gives the
            // closed state a11y coverage in the public-runtime axe scan.
            if (Form::query()->where('title', 'Closed Survey')->doesntExist()) {
                $closed = app(FormService::class)->create(
                    $tenant, $owner, 'Closed Survey', 'A survey whose open window has passed (H12b demo).'
                );
                app(FormBuilderService::class)->addField($closed, $owner, FieldType::ShortText, null)
                    ->update(['label' => 'Your name']);
                app(PublishService::class)->publish($closed->refresh(), $owner);

                $closed->update([
                    'public_slug' => 'closed-survey',
                    'allow_guest_submissions' => true,
                    'closes_at' => now()->subDay(),
                    'schedule_state' => FormScheduleState::Closed,
                ]);
            }

            // A guest-enabled BRANCHING form (Increment H21b, Doc #27) — reached at /f/branching-router.
            // This is §4.1's own worked example, built literally: a routing section holding NOTHING but a
            // URL-prefilled `hidden` field (so predicate 2 drops the section itself), gating every other
            // section. Visited bare, the whole graph is empty and the runtime must render §4.1's terminal
            // state — suppressed counter, terminal panel, one explicitly-labelled Submit. Visited as
            // `?role=staff` it renders an ordinary single-step branch. Both get axe coverage, and the empty
            // case is the one no other seeded form can reach.
            if (Form::query()->where('title', 'Branching Router')->doesntExist()) {
                $router = app(FormService::class)->create(
                    $tenant, $owner, 'Branching Router', 'Relevance-derived step skipping (H21b demo).'
                );
                $rb = app(FormBuilderService::class);

                $routing = $rb->addSection($router);
                $routing->update(['label' => 'Routing']);
                $rb->addField($router, $owner, FieldType::Hidden, $routing->id)->update([
                    'key' => 'role',
                    'label' => 'Role',
                    // Increment H7 — `url` is the only client-fillable source; the param name defaults to the key.
                    'config' => ['prefill_source' => 'url'],
                ]);

                $staff = $rb->addSection($router);
                $staff->update(['label' => 'Staff details', 'relevant_expression' => "\${role} = 'staff'"]);
                $rb->addField($router, $owner, FieldType::ShortText, $staff->id)->update(['label' => 'Staff number']);

                $visitor = $rb->addSection($router);
                $visitor->update(['label' => 'Visitor details', 'relevant_expression' => "\${role} = 'visitor'"]);
                $rb->addField($router, $owner, FieldType::ShortText, $visitor->id)->update(['label' => 'Who are you visiting?']);

                app(PublishService::class)->publish($router->refresh(), $owner);

                $router->update([
                    'public_slug' => 'branching-router',
                    'allow_guest_submissions' => true,
                    'supported_locales' => ['en'],
                    'single_page_mode' => false,
                ]);
            }

            // A DRAFT form whose first field is a matrix (Increment G4b), so the builder auto-selects it on
            // load and the builder-axe tab-walk mounts + scans the MatrixEditor's Grid tab (rows/columns/cells)
            // — the composite config editors — at all viewports in light + dark, with no palette interaction.
            if (Form::query()->where('title', 'Grid Builder Demo')->doesntExist()) {
                $gridDemo = app(FormService::class)->create($tenant, $owner, 'Grid Builder Demo', 'Composite grid config editors (G4b).');
                $gsb = app(FormBuilderService::class);
                $gsb->addField($gridDemo, $owner, FieldType::Matrix, null)->update([
                    'label' => 'Coverage matrix',
                    'config' => [
                        'rows' => [['value' => 'a', 'label' => 'Row A'], ['value' => 'b', 'label' => 'Row B']],
                        'columns' => [['value' => 'q1', 'label' => 'Column 1'], ['value' => 'q2', 'label' => 'Column 2']],
                        'cells' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']],
                    ],
                ]);
                $gsb->addField($gridDemo, $owner, FieldType::LikertMatrix, null)->update([
                    'label' => 'Rating grid',
                    'config' => [
                        'rows' => [['value' => 'speed', 'label' => 'Speed'], ['value' => 'quality', 'label' => 'Quality']],
                        'columns' => [['value' => '1', 'label' => 'Low'], ['value' => '2', 'label' => 'Mid'], ['value' => '3', 'label' => 'High']],
                    ],
                ]);
                // Left as a DRAFT (not published) so it opens straight into the builder.
            }

            // A DRAFT form built to exercise every visual state of the builder's LOGIC view (Increment H21d1).
            // `Branching Router` above is the CLEAN case — real conditions, no notices — and this is the
            // opposite: one form carrying each state the rail can render, so the axe scan sees all of them.
            //
            //  - a described condition (`${age} > 18`), the ordinary case;
            //  - an OPAQUE one (`${age} + 1 > 18`) — arithmetic, which the describer refuses to paraphrase,
            //    so the card falls back to the raw text alone;
            //  - an INVALID one (`${age} = = 1`) — the state that used to 500 the sidecar before H21d1 made
            //    `StepGraphInspector::emptyAtOpen()` syntax-safe, and the reason this form is never published;
            //  - a FORWARD REFERENCE (the `Summary` section is gated on a field in the section AFTER it), so
            //    a server-derived notice hangs on a node;
            //  - a section holding only `hidden`/`calculated` fields, which can never be a step at all.
            if (Form::query()->where('title', 'Logic Notices Demo')->doesntExist()) {
                $logicDemo = app(FormService::class)->create(
                    $tenant, $owner, 'Logic Notices Demo', 'Every state the logic rail can draw (H21d1).'
                );
                $lb = app(FormBuilderService::class);

                $intake = $lb->addSection($logicDemo);
                $intake->update(['label' => 'Intake']);
                $lb->addField($logicDemo, $owner, FieldType::Integer, $intake->id)->update([
                    'key' => 'age', 'label' => 'Your age',
                ]);

                $adults = $lb->addSection($logicDemo);
                $adults->update(['label' => 'Adults only', 'relevant_expression' => '${age} > 18']);
                $lb->addField($logicDemo, $owner, FieldType::ShortText, $adults->id)->update(['label' => 'Occupation']);

                $odd = $lb->addSection($logicDemo);
                $odd->update(['label' => 'Arithmetic gate', 'relevant_expression' => '${age} + 1 > 18']);
                $lb->addField($logicDemo, $owner, FieldType::ShortText, $odd->id)->update([
                    'label' => 'Anything else?',
                    // A FIELD-level condition that does not parse — the field arm of the rail, not the
                    // section one, and the pair is deliberate: they render through different code paths.
                    'relevant_expression' => '${age} = = 1',
                ]);

                $summary = $lb->addSection($logicDemo);
                $summary->update(['label' => 'Summary', 'relevant_expression' => "\${late_answer} = 'yes'"]);
                $lb->addField($logicDemo, $owner, FieldType::ShortText, $summary->id)->update(['label' => 'Confirm']);

                // …declared AFTER Summary, which is what makes the reference above a forward one.
                $tail = $lb->addSection($logicDemo);
                $tail->update(['label' => 'Tail']);
                $lb->addField($logicDemo, $owner, FieldType::ShortText, $tail->id)->update([
                    'key' => 'late_answer', 'label' => 'Answered later',
                ]);

                $refs = $lb->addSection($logicDemo);
                $refs->update(['label' => 'Reference values']);
                $lb->addField($logicDemo, $owner, FieldType::Hidden, $refs->id)->update([
                    'key' => 'campaign', 'label' => 'Campaign', 'config' => ['prefill_source' => 'url'],
                ]);

                // Increment H21d2 extends this form with the two states the structured CONDITION EDITOR
                // adds to the config panel, so the same scan covers the write half as well as the read one:
                //
                //  - a MULTI-SELECT with real options, so a `selected()` row offers a dropdown of choices
                //    rather than a text box (the two arms render different controls);
                //  - a NESTED-GROUP condition, `(A or B) and selected(...)`, which is the only shape that
                //    draws the recursive group control, its indent rule and a "Condition 1.2 …" ordinal.
                $prefs = $lb->addSection($logicDemo);
                $prefs->update(['label' => 'Preferences']);
                $lb->addField($logicDemo, $owner, FieldType::MultiSelect, $prefs->id)->update([
                    'key' => 'colours',
                    'label' => 'Favourite colours',
                    'config' => ['options' => [
                        ['value' => 'red', 'label' => 'Red'],
                        ['value' => 'blue', 'label' => 'Blue'],
                    ]],
                ]);

                $nested = $lb->addSection($logicDemo);
                $nested->update([
                    'label' => 'Grouped gate',
                    'relevant_expression' => "(\${age} > 18 or \${age} < 5) and selected(\${colours}, 'red')",
                ]);
                $lb->addField($logicDemo, $owner, FieldType::ShortText, $nested->id)->update(['label' => 'Tell us more']);

                // Left as a DRAFT, and it could not be published anyway — the invalid expression is exactly
                // what `ExpressionValidationGate` refuses, which is the point the rail makes on the card.
            }

            // A DRAFT form whose first field is a geopoint (Increment G5b2b), so the builder auto-selects it on
            // load and the builder-axe tab-walk mounts + scans the GeoEditor's "Map" tab (capture options +
            // default map view) — the geospatial config editor — at all viewports in light + dark, with no
            // palette interaction. A trace + a shape follow so all three geo types exist in one demo.
            if (Form::query()->where('title', 'Geo Builder Demo')->doesntExist()) {
                $geoDemo = app(FormService::class)->create($tenant, $owner, 'Geo Builder Demo', 'Geospatial map config editor (G5b2b).');
                $gsb = app(FormBuilderService::class);
                $gsb->addField($geoDemo, $owner, FieldType::Geopoint, null)->update([
                    'label' => 'Pin the site',
                    'config' => [
                        'capture_altitude' => true,
                        'accuracy_threshold' => 20,
                        'default_center' => ['lat' => 14.5995, 'lon' => 120.9842],
                        'default_zoom' => 11,
                    ],
                ]);
                $gsb->addField($geoDemo, $owner, FieldType::Geotrace, null)->update(['label' => 'Trace the path']);
                $gsb->addField($geoDemo, $owner, FieldType::Geoshape, null)->update(['label' => 'Outline the plot']);
                // Left as a DRAFT (not published) so it opens straight into the builder.
            }

            // A DRAFT form whose first field is an image_capture (Increment G6), so the builder auto-selects it
            // on load and the builder-axe tab-walk mounts + scans the MediaEditor's "Media" tab (accepted types
            // + size/count caps + capture source) at all viewports in light + dark, with no palette interaction.
            // A file upload + audio + video follow so several media types exist in one demo.
            if (Form::query()->where('title', 'Media Builder Demo')->doesntExist()) {
                $mediaDemo = app(FormService::class)->create($tenant, $owner, 'Media Builder Demo', 'Media capture config editor (G6).');
                $msb = app(FormBuilderService::class);
                $msb->addField($mediaDemo, $owner, FieldType::ImageCapture, null)->update([
                    'label' => 'Photo of the site',
                    'config' => [
                        'accepted_types' => ['image/jpeg', 'image/png'],
                        'max_file_size_bytes' => 10 * 1_048_576,
                        'max_count' => 3,
                        'min_count' => 1,
                        'capture_source' => 'camera',
                    ],
                ]);
                $msb->addField($mediaDemo, $owner, FieldType::FileUpload, null)->update(['label' => 'Supporting document']);
                $msb->addField($mediaDemo, $owner, FieldType::AudioCapture, null)->update(['label' => 'Voice note']);
                $msb->addField($mediaDemo, $owner, FieldType::VideoCapture, null)->update(['label' => 'Short video']);
                // Left as a DRAFT (not published) so it opens straight into the builder.
            }

            $this->seedAnalyticsFixture($owner, $tenant);

            // A handful of submissions against Clinic Intake so the inbox (Increment F7) has rows in a spread
            // of review states (Badge variety) and the detail page has answers to render for the axe gate.
            $intakeForm = Form::query()->where('title', 'Clinic Intake')->first();
            if ($intakeForm?->current_published_version_id !== null
                && Submission::query()->where('form_id', $intakeForm->id)->doesntExist()) {
                $version = FormVersion::findOrFail($intakeForm->current_published_version_id);
                $answers = $this->sampleAnswers($version);

                $specs = [
                    ['status' => SubmissionStatus::Submitted, 'extra' => []],
                    ['status' => SubmissionStatus::Approved, 'extra' => [
                        'validated_by' => $owner->id, 'validated_at' => now(), 'finalized_at' => now(),
                    ]],
                    ['status' => SubmissionStatus::Returned, 'extra' => [
                        'validated_by' => $owner->id, 'validated_at' => now(),
                        'returned_reason' => 'Please confirm the date of birth.',
                    ]],
                ];

                foreach ($specs as $spec) {
                    $submission = Submission::create(array_merge([
                        'form_id' => $intakeForm->id,
                        'form_version_id' => $version->id,
                        'respondent_user_id' => $owner->id,
                        'status' => $spec['status'],
                        'source' => SubmissionSource::Manual,
                        'submitted_at' => now(),
                    ], $spec['extra']));
                    SubmissionAnswer::create([
                        'submission_id' => $submission->id,
                        'form_version_id' => $version->id,
                        'answers' => $answers,
                        'attachment_refs' => [],
                    ]);
                }
            }

            $this->seedWebhooks($owner);

            $this->seedConnections($owner);

            $this->seedCustomDomains($tenant);

            $this->seedScopingHierarchy($owner, $reviewer);

            // K1c. After every submission block above, so the fixture's own collection and review history
            // reaches the ledger — nothing here drives `SubmissionPipeline`, so no `SubmissionCreated` was
            // ever raised for any of it. Announcements are suppressed, which is what keeps the notification
            // counts several Playwright specs assert on exactly where they were.
            $this->seedGamificationLedger();
            $this->seedNotifications($owner, $reviewer);

            $this->seedFeedback($owner, $reviewer);

            // Last, so the ledger it inspects already contains everything the seeders above wrote.
            $this->seedAuditLog($owner, $reviewer);
        });

        $tenant->forceFill(['owner_user_id' => $owner->id])->save();

        TenantContext::flush();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /**
     * Webhook fixtures (Increment H14) — so the /webhooks list + a populated /webhooks/{id} delivery log
     * render for the responsive-axe scan (acme is on BUSINESS since H24a, which inherits every Professional
     * feature, so the `webhooks` feature + endpoint quota are available). One active tenant-wide endpoint with a spread of deliveries across every status (Badge
     * variety), and one breaker-paused endpoint so the Show page's paused notice + failure metadata are covered.
     * Endpoints go through WebhookEndpointService (the same writer the UI uses); idempotent on the endpoint name.
     */
    private function seedWebhooks(User $owner): void
    {
        if (WebhookEndpoint::query()->where('name', 'Zapier')->exists()) {
            return;
        }

        $service = app(WebhookEndpointService::class);

        $active = $service->create([
            'name' => 'Zapier',
            'url' => 'https://hooks.zapier.example.com/hooks/catch/123456/abcdef',
            'event_types' => DomainEventType::values(),
            'form_id' => null,
        ], $owner);

        // A second endpoint the circuit breaker auto-paused — renders the Show page's paused state + failures.
        $paused = $service->create([
            'name' => 'CRM sync',
            'url' => 'https://api.crm.example.com/webhooks/forms',
            'event_types' => [DomainEventType::SubmissionCreated->value],
            'form_id' => null,
        ], $owner);
        $paused->forceFill([
            'status' => WebhookEndpointStatus::Paused,
            'disabled_reason' => 'too_many_failures',
            'consecutive_failure_count' => 20,
            'last_failure_at' => now(),
        ])->save();

        // A spread of deliveries on the active endpoint so the log + every delivery-status Badge render.
        $specs = [
            ['status' => WebhookDeliveryStatus::Succeeded, 'attempts' => 1, 'code' => 200, 'ms' => 118, 'body' => '{"ok":true}'],
            ['status' => WebhookDeliveryStatus::Succeeded, 'attempts' => 1, 'code' => 202, 'ms' => 143, 'body' => 'Accepted'],
            ['status' => WebhookDeliveryStatus::Failed, 'attempts' => 3, 'code' => 500, 'ms' => 87, 'body' => 'Internal Server Error'],
            ['status' => WebhookDeliveryStatus::DeadLettered, 'attempts' => 10, 'code' => 500, 'ms' => 91, 'body' => 'Internal Server Error'],
            ['status' => WebhookDeliveryStatus::Pending, 'attempts' => 0, 'code' => null, 'ms' => null, 'body' => null],
        ];

        foreach ($specs as $spec) {
            WebhookDelivery::factory()->forEndpoint($active)->create([
                'status' => $spec['status'],
                'attempt_count' => $spec['attempts'],
                'response_status_code' => $spec['code'],
                'response_time_ms' => $spec['ms'],
                'response_body_excerpt' => $spec['body'],
                'last_attempted_at' => $spec['status'] === WebhookDeliveryStatus::Pending ? null : now(),
            ]);
        }
    }

    /**
     * Native-connector fixtures (Increment H15b) — so /integrations and a populated /integrations/rules/{id}
     * render for the responsive-axe scan (acme is on BUSINESS since H24a, which inherits Professional, so
     * `native_connectors` is available).
     *
     * Grants go through the FACTORY, not ConnectionService: a real grant can only come from an OAuth exchange,
     * and there is no provider to call in e2e. That is also why nothing here triggers an outbound request —
     * the channel picker only calls Slack when a human opens the rule modal, which the specs never do.
     *
     * Two workspaces so both connection-status badges render (`active` and the amber "Reconnect needed"), and
     * a rule spread that covers active / form-scoped / breaker-paused plus every delivery-status badge.
     * Idempotent on the workspace label.
     */
    private function seedConnections(User $owner): void
    {
        if (Connection::query()->where('external_account_label', 'Acme HQ')->exists()) {
            return;
        }

        $live = Connection::factory()->create([
            'external_account_label' => 'Acme HQ',
            'status' => ConnectionStatus::Active,
            'connected_by' => $owner->id,
        ]);

        // A grant that died on its own — renders the "Reconnect needed" badge + the not-delivering notice.
        Connection::factory()->refreshFailed()->create([
            'external_account_label' => 'Acme Field Ops',
            'connected_by' => $owner->id,
        ]);

        $active = ConnectionSubscription::factory()->forConnection($live)->create([
            'name' => 'New submissions → #ops',
            'event_types' => DomainEventType::values(),
            'config' => ['channel_id' => 'C0OPS00001', 'channel_name' => 'ops'],
            'created_by' => $owner->id,
        ]);

        $intake = Form::query()->where('title', 'Clinic Intake')->first();

        if ($intake !== null) {
            ConnectionSubscription::factory()->forConnection($live)->forForm($intake->id)->create([
                'name' => 'Clinic Intake → #clinic',
                'config' => ['channel_id' => 'C0CLINIC01', 'channel_name' => 'clinic'],
                'created_by' => $owner->id,
            ]);
        }

        ConnectionSubscription::factory()->forConnection($live)->paused()->create([
            'name' => 'Legacy alerts → #archive',
            'config' => ['channel_id' => 'C0ARCHIVE1', 'channel_name' => 'archive'],
            'consecutive_failure_count' => 20,
            'last_failure_at' => now(),
            'created_by' => $owner->id,
        ]);

        // ── H16b: a Google Sheets grant, so /integrations' EXISTING axe scan covers the tabular surface —
        // the destination column, the 7-day caution and a drift-paused rule with its reason card. No new spec
        // is needed: responsive-axe.spec.ts already visits this page across 3 viewports × 2 themes, and a
        // surface the seeder never mounts is a surface those six runs cannot see.
        //
        // Through the FACTORY, not ConnectionService, for the reason the Slack fixture above records: a real
        // grant can only come from an OAuth exchange and there is no provider to call in e2e. Nothing here
        // triggers an outbound request either — the sheet sidecars fire only when a human opens the rule
        // modal, which no spec does.
        $sheets = Connection::factory()->googleSheets()->create([
            'status' => ConnectionStatus::Active,
            'connected_by' => $owner->id,
        ]);

        $sheetsMapping = [
            'fingerprint' => hash('sha256', 'full name|colour|submission id'),
            'columns' => [
                ['header' => 'full name', 'field_key' => 'full_name'],
                ['header' => 'colour', 'field_key' => 'colour'],
                ['header' => 'submission id', 'field_key' => '__submission_id'],
            ],
        ];

        ConnectionSubscription::factory()->forConnection($sheets)->create([
            'name' => 'Submissions → Q3 Intake sheet',
            // Only `submission.*` events can reach a spreadsheet — a row IS a submission's answers, which is
            // why SubscriptionConfigRules::eventTypeGuard() refuses the rest. Seeding the whole catalog here
            // would seed a rule the product itself now rejects.
            'event_types' => ['submission.created', 'submission.updated'],
            'config' => [
                'spreadsheet_id' => 'E2E_SHEET_0000000000000001',
                'spreadsheet_title' => 'Q3 Intake',
                'sheet_name' => 'Responses',
                'mapping' => $sheetsMapping,
            ],
            'created_by' => $owner->id,
        ]);

        // The drift case — the one a tenant actually meets, and the reason the reason-card exists at all.
        $drifted = ConnectionSubscription::factory()->forConnection($sheets)->paused()->create([
            'name' => 'Clinic Intake → responses sheet',
            'event_types' => ['submission.created'],
            'config' => [
                'spreadsheet_id' => 'E2E_SHEET_0000000000000002',
                'spreadsheet_title' => 'Clinic responses',
                'sheet_name' => 'Responses',
                'mapping' => $sheetsMapping,
            ],
            'last_failure_at' => now(),
            'created_by' => $owner->id,
        ]);

        // `paused_reason` is READ FROM THE LEDGER rather than stored on the rule, so the reason card renders
        // only when a blocked delivery exists to carry it. A paused rule without one would mount it empty.
        WebhookDelivery::factory()->forSubscription($drifted)->create([
            'status' => WebhookDeliveryStatus::DeadLettered,
            'attempt_count' => 1,
            'response_status_code' => null,
            'response_time_ms' => 210,
            // ⚠️ THE WORDING IS `MappingDrift::summary()`'s, NOT AN APPROXIMATION OF IT. This line used to
            // read "The spreadsheet's columns changed…", which the engine has never produced — and it carried
            // the `[column_drift]` prefix the ADAPTERS did not add until H16c, so the e2e was certifying a
            // reason card that could not render in production. A seeded string that only resembles the real
            // one is a test asserting its own fixture.
            'response_body_excerpt' => '[column_drift] The columns changed: added “reviewer”; moved “colour”.',
        ]);

        // ── Airtable (H16c) ─────────────────────────────────────────────────────────────────────────────
        // A third provider card and a third destination shape on the same page, so the responsive/axe sweep
        // sees a rules table carrying all three at once. Deliberately NOT given a drifted twin: the reason
        // card it would render is byte-identical to the Sheets one above, and a second scan of the same
        // markup buys nothing — the H16b note on `responsive-axe.spec.ts` makes that argument already.
        $airtable = Connection::factory()->airtable()->create([
            'external_account_label' => 'Airtable',
            'connected_by' => $owner->id,
        ]);

        ConnectionSubscription::factory()->forConnection($airtable)->create([
            'name' => 'Submissions → Applicant tracker',
            'event_types' => ['submission.created'],
            'config' => [
                // The base id, the table id, and the table name as a caption — the id is the identity, so a
                // rename in Airtable cannot break this rule.
                'spreadsheet_id' => 'appE2E00000000001',
                'spreadsheet_title' => 'Applicant tracker',
                'sheet_id' => 'tblE2E00000000001',
                'sheet_name' => 'Applicants',
                'mapping' => [
                    'fingerprint' => hash('sha256', 'full name|colour|submission id'),
                    'columns' => [
                        ['header' => 'full name', 'field_key' => 'full_name'],
                        ['header' => 'colour', 'field_key' => 'colour'],
                        ['header' => 'submission id', 'field_key' => '__submission_id'],
                    ],
                ],
            ],
            'created_by' => $owner->id,
        ]);

        // A spread across the shared ledger so the rule detail's log + every delivery badge render. The
        // `Result` column is the diagnostic here (Slack fails at HTTP 200), so each row carries a real excerpt.
        $specs = [
            ['status' => WebhookDeliveryStatus::Succeeded, 'attempts' => 1, 'code' => 200, 'ms' => 141, 'body' => 'ok'],
            ['status' => WebhookDeliveryStatus::Succeeded, 'attempts' => 1, 'code' => 200, 'ms' => 96, 'body' => 'ok'],
            ['status' => WebhookDeliveryStatus::Failed, 'attempts' => 3, 'code' => 200, 'ms' => 88, 'body' => '[not_in_channel] Slack rejected the message.'],
            ['status' => WebhookDeliveryStatus::DeadLettered, 'attempts' => 10, 'code' => 200, 'ms' => 92, 'body' => '[channel_not_found] Slack rejected the message.'],
            ['status' => WebhookDeliveryStatus::Pending, 'attempts' => 0, 'code' => null, 'ms' => null, 'body' => null],
        ];

        foreach ($specs as $spec) {
            WebhookDelivery::factory()->forSubscription($active)->create([
                'status' => $spec['status'],
                'attempt_count' => $spec['attempts'],
                'response_status_code' => $spec['code'],
                'response_time_ms' => $spec['ms'],
                'response_body_excerpt' => $spec['body'],
                'last_attempted_at' => $spec['status'] === WebhookDeliveryStatus::Pending ? null : now(),
            ]);
        }
    }

    /**
     * Custom-domain fixtures (Increment H22b / ADR-0012) — so /domains renders real cards for the
     * responsive-axe scan rather than an empty state. acme is on BUSINESS since H24a, which is the tier
     * carrying `custom_domain`, so the write affordances render too.
     *
     * ⚠️ NEITHER ROW IS ACTIVATED, AND THAT IS A CONSTRAINT ON THIS FIXTURE RATHER THAN A GAP IN IT. An
     * activated row becomes visible to {@see Domain}'s global scope, which is what
     * `TenantUrl::toPublic()` reads — so seeding one would repoint every resume link in the e2e database
     * onto a hostname the browser cannot resolve, breaking the guest-runtime specs in a way that would look
     * like a public-runtime defect. The `live` state is covered in Pest (DomainWebTest, CustomDomainApiTest)
     * and the badge in Vitest; do not "improve" this by activating one here.
     *
     * UPSERT-KEYED ON THE HOSTNAME, so a re-seed CONVERGES — the H24b2 analytics-block precedent. Every
     * other block in this seeder is `doesntExist()`-guarded and therefore drifts: change a field here and a
     * developer's already-seeded box silently keeps the old row while CI, which provisions a fresh database
     * every run, goes green. `created_at` is stamped explicitly because `forTenant()` orders by it and two
     * rows written in the same instant would otherwise render in an arbitrary order.
     *
     * PUBLIC for the same reason {@see seedAnalyticsFixture()} is: `run()` cannot be re-run under
     * `RefreshDatabase` (its `pgsql_auth` identity lookup cannot see the uncommitted transaction), so the
     * convergence claim above is only testable through this seam. `domains` is RLS-exempt, so unlike the
     * analytics fixture this one needs no tenant context to re-run.
     */
    public function seedCustomDomains(Tenant $tenant): void
    {
        $rows = [
            // Pending: the tenant has claimed it and not yet published the TXT record. Exercises the DNS
            // block, the "check DNS" affordance and the neutral badge.
            ['domain' => 'forms.acme-example.com', 'verified' => false, 'age' => 2],
            // Verified but AWAITING THE OPERATOR — the honest manual-TLS state, and the one card on the page
            // whose whole purpose is to say that the tenant is done and the hostname still serves nothing.
            ['domain' => 'apply.acme-example.com', 'verified' => true, 'age' => 1],
        ];

        foreach ($rows as $row) {
            /** @var Domain|null $existing */
            $existing = Domain::unscopedQuery()->where('domain', $row['domain'])->first();

            $attributes = [
                'tenant_id' => $tenant->id,
                // Deterministic rather than random: a re-seed must converge on the same record, and the
                // token is public by design (ADR-0012 §D11) so there is nothing to protect here.
                'verification_token' => str_repeat(substr(md5($row['domain']), 0, 8), 8),
                'token_issued_at' => now()->subDays($row['age']),
                'verified_at' => $row['verified'] ? now()->subDays($row['age']) : null,
                'activated_at' => null,
                'verification_checked_at' => now()->subMinutes(20),
                'verification_failure_reason' => $row['verified'] ? null : DomainVerificationFailure::NotFound,
                'is_primary' => false,
                'created_at' => now()->subDays($row['age']),
            ];

            if ($existing !== null) {
                $existing->forceFill($attributes)->save();

                continue;
            }

            Domain::query()->forceCreate([...$attributes, 'domain' => $row['domain']]);
        }
    }

    /**
     * The tenant's scoping hierarchy (Increment G10b2) — the fixture the /scopes page and its axe spec run on.
     *
     * Deliberately FIVE levels deep. The minimum useful hierarchy is three, but the indent is
     * `depth * --indent-step`, so a shallow fixture would leave the 375px horizontal-overflow case
     * unreachable and make responsive-axe a false negative on the exact failure mode it is there to catch.
     *
     * Nodes go through ScopeNodeService — the only writer of `path`/`depth` — and forms through
     * FormService::assignScope, so the fixture exercises the same writers the UI does rather than raw
     * inserts that could drift from them.
     */
    private function seedScopingHierarchy(User $owner, User $reviewer): void
    {
        if (ScopeNode::query()->where('name', 'Luzon')->exists()) {
            return;
        }

        $nodes = app(ScopeNodeService::class);

        $luzon = $nodes->create(null, 'Luzon', ['code' => 'L', 'node_type' => 'island_group'], $owner);
        $ncr = $nodes->create($luzon, 'National Capital Region', ['code' => 'NCR', 'node_type' => 'region'], $owner);
        $manila = $nodes->create($ncr, 'City of Manila', ['code' => 'MNL', 'node_type' => 'city'], $owner);
        $malate = $nodes->create($manila, 'Malate', ['code' => 'MLT', 'node_type' => 'barangay'], $owner);
        $nodes->create($malate, 'Zone 82', ['code' => 'Z82', 'node_type' => 'zone'], $owner);
        $nodes->create($ncr, 'Quezon City', ['code' => 'QC', 'node_type' => 'city'], $owner);

        $visayas = $nodes->create(null, 'Visayas', ['code' => 'V', 'node_type' => 'island_group'], $owner);
        $cebu = $nodes->create($visayas, 'Central Visayas', ['code' => 'VII', 'node_type' => 'region'], $owner);
        // A node the e2e move spec owns exclusively. The spec runs once per viewport project against ONE
        // seeded database, so it re-roots this node repeatedly; keeping it off the Luzon branch means the
        // ARIA-structure assertions there (sibling sets, levels) stay stable no matter how often it runs.
        $nodes->create($cebu, 'Movable District', ['code' => 'MOV', 'node_type' => 'district'], $owner);

        // A deactivated branch, so the UI's "inactive" affordance and the resolver's zero-reach preview
        // ("a grant here confers nothing until it is reactivated") both have a fixture to render.
        $mindanao = $nodes->create(null, 'Mindanao', ['code' => 'M', 'node_type' => 'island_group'], $owner);
        $nodes->setActive($mindanao, false);

        // Forms assigned at DIFFERENT depths — a grant on NCR must demonstrably reach the Manila-level form
        // through `includes_descendants` while the Cebu one stays out of range.
        $forms = app(FormService::class);
        foreach ([
            'Community Health Survey' => $manila,
            'Household Roster' => $ncr,
            'Clinic Intake' => $cebu,
        ] as $title => $node) {
            $form = Form::query()->where('title', $title)->first();
            if ($form !== null) {
                $forms->assignScope($form, (string) $node->getKey());
            }
        }

        // A descendant-inclusive Reviewer grant for the second active member: the state the whole G10 line
        // exists to make reachable, and what makes the grant list on /scopes non-empty on first load.
        app(ResourceGrantService::class)->grant($owner, $reviewer, $ncr, ResourceCapacity::Reviewer, true);
    }

    /** The `fixtureUuid()` namespace for the notification rows below. */
    private const NOTIFICATION_FIXTURE_PREFIX = 'i4:notification:';

    /**
     * Notification fixtures (Increment I4) — so the bell carries a badge, the popover has rows to scan, and
     * the Settings preferences card renders a NON-default state.
     *
     * ⚠️ **THE TABLE IS EMPTY WHEN THIS RUNS, AND THAT IS EXACTLY WHY THE GUARD IS NOT `exists()`.** This
     * seeder writes submissions with `Submission::create()`, memberships with `TenantUser::create()` and
     * performs no review transitions at all — never `SubmissionPipeline`, `TenantMembershipService` or
     * `SubmissionReviewService`, which are the only announce sites for I3's four notification listeners
     * (see {@see self::seedAnalyticsFixture()}'s reason (2) for the same deliberate bypass). So an
     * emptiness guard would WORK today and start silently skipping the moment a later seed routes anything
     * through the real writers. Every row is keyed on a DETERMINISTIC primary key from
     * {@see self::fixtureUuid()} and upserted — the {@see self::seedCustomDomains()} posture — so a re-seed
     * CONVERGES rather than doubling or skipping.
     *
     * ⚠️ **AND DO NOT "FIX" THAT BY ROUTING A SEEDED SUBMISSION THROUGH `SubmissionPipeline`.**
     * `E2eSeederIdempotencyTest` pins the exact submission counts, and the pipeline's synchronous listeners
     * would attempt real outbound HTTPS during `db:seed` while polluting the delivery ledgers the H14/H15b
     * axe scans assert on.
     *
     * ⚠️ **ONE ROW BELONGS TO THE REVIEWER, ON PURPOSE.** Playwright only ever logs in as the Owner, so a
     * regression that dropped `Notification::scopeForUser()` would be INVISIBLE in a fixture where every
     * row is the Owner's — the badge would read the same either way. With the reviewer's row present the
     * Owner's badge is 7 while the table holds 10. (It was 4 and 7 until K1b: the Owner genuinely earns
     * three gamification badges here, because `seedForms()` drives the real services and each announces.
     * K1c's own ledger seeding adds no further rows — it suppresses announcements deliberately, precisely
     * so this fixture's counts stay where the Playwright specs expect them.)
     *
     * ⚠️ **THE ONE DIVERGING PREFERENCE IS ON THE ONE TYPE WITH NO NOTIFICATION.** `review_requested` is
     * silenced on both channels for the Owner and is the only type the Owner holds no row for, so the
     * fixture cannot contradict itself — a seeded bell row for a type the seeded preference says is
     * silenced is exactly what a later reader would "fix". Written through the real
     * {@see NotificationPreferenceResolver::set()}, never the factory, for the reason
     * {@see self::seedAuditLog()} records for `AuditLogger`: the both-booleans rule has to be PRODUCED by
     * the code path the product uses.
     *
     * `created_at` is back-dated with `forceFill(...)->saveQuietly()`, which is legitimate here and NOT the
     * `audits` case: `notifications` is not append-only and carries the ordinary strict UPDATE policy —
     * precisely why {@see self::seedAuditLog()} cannot spread ITS rows in time and says so.
     *
     * Public, not private, on the {@see self::seedCustomDomains()} seam, so the idempotency test can re-run
     * this block alone.
     */
    public function seedNotifications(User $owner, User $reviewer): void
    {
        $intakeSubmissionId = Submission::query()
            ->whereHas('form', fn (Builder $query) => $query->where('title', 'Clinic Intake'))
            ->orderBy('id')
            ->value('id');

        $endpoint = WebhookEndpoint::query()->where('name', 'CRM sync')->first();

        // Real ids, so every link in the demo actually loads. Degrade rather than fatal if an upstream
        // block was skipped — the analyticsFixtureRows() posture.
        $submissionId = is_string($intakeSubmissionId) ? $intakeSubmissionId : null;
        $endpointId = $endpoint === null ? null : (string) $endpoint->getKey();

        /** @var list<array{key: string, user: string, type: NotificationType, ago: int, read: bool, emailed: bool, data: array<string, mixed>}> $rows */
        $rows = [
            [
                'key' => 'received',
                'user' => (string) $owner->getKey(),
                'type' => NotificationType::SubmissionReceived,
                'ago' => 6,
                'read' => false,
                'emailed' => false,
                'data' => ['submission_id' => $submissionId, 'form_title' => 'Clinic Intake'],
            ],
            [
                'key' => 'returned',
                'user' => (string) $owner->getKey(),
                'type' => NotificationType::SubmissionReturned,
                'ago' => 180,
                'read' => false,
                'emailed' => true,
                'data' => ['submission_id' => $submissionId, 'form_title' => 'Clinic Intake'],
            ],
            [
                // form_title null on purpose — the two review outcomes write `$form?->title`, so a trashed
                // form yields null and NotificationCopy's "one of your forms" fallback has to render.
                'key' => 'approved',
                'user' => (string) $owner->getKey(),
                'type' => NotificationType::SubmissionApproved,
                'ago' => 1_440,
                'read' => true,
                'emailed' => true,
                'data' => ['submission_id' => $submissionId, 'form_title' => null],
            ],
            [
                'key' => 'invited',
                'user' => (string) $owner->getKey(),
                'type' => NotificationType::MemberInvited,
                'ago' => 2_880,
                'read' => true,
                'emailed' => true,
                'data' => ['email' => self::PENDING_EMAIL, 'role' => 'viewer', 'invited_by' => (string) $owner->getKey()],
            ],
            [
                'key' => 'webhook',
                'user' => (string) $owner->getKey(),
                'type' => NotificationType::WebhookFailed,
                'ago' => 4_320,
                'read' => false,
                'emailed' => true,
                'data' => ['webhook_endpoint_id' => $endpointId, 'endpoint_name' => 'CRM sync', 'failure_count' => 20],
            ],
            [
                // A submission id that names nothing, so NotificationPresenter's reachability pass resolves
                // `url` to NULL and the popover renders its non-interactive row — the only fixture that
                // would catch an <a href=""> regression. A FAILED export at the same time, so the bell's
                // "Export failed" title (which corrects a live I3 defect) is on screen for the axe scan.
                'key' => 'export',
                'user' => (string) $owner->getKey(),
                'type' => NotificationType::ExportReady,
                'ago' => 5_760,
                'read' => false,
                'emailed' => true,
                'data' => [
                    'submission_id' => self::fixtureUuid(self::NOTIFICATION_FIXTURE_PREFIX.'missing-target'),
                    'form_title' => 'Clinic Intake',
                    'outcome' => SubmissionPdfOutcome::Failed->value,
                ],
            ],
            [
                'key' => 'reviewer-received',
                'user' => (string) $reviewer->getKey(),
                'type' => NotificationType::SubmissionReceived,
                'ago' => 120,
                'read' => false,
                'emailed' => false,
                'data' => ['submission_id' => $submissionId, 'form_title' => 'Clinic Intake'],
            ],
        ];

        foreach ($rows as $row) {
            $id = self::fixtureUuid(self::NOTIFICATION_FIXTURE_PREFIX.$row['key']);
            $at = now()->subMinutes($row['ago']);

            $attributes = [
                'user_id' => $row['user'],
                'type' => $row['type']->value,
                'data' => array_filter($row['data'], static fn (mixed $value): bool => $value !== null),
                'read_at' => $row['read'] ? $at->copy()->addMinutes(5) : null,
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

        // The one divergence, so the preferences card renders something other than the platform defaults.
        app(NotificationPreferenceResolver::class)
            ->set($owner, NotificationType::ReviewRequested, false, false);
    }

    /** Deterministic ids for the feedback fixture (I7a), so re-seeding updates rather than duplicates. */
    private const FEEDBACK_FIXTURE_IDS = [
        '0192e2e0-0000-7000-8000-00000000fb01',
        '0192e2e0-0000-7000-8000-00000000fb02',
        '0192e2e0-0000-7000-8000-00000000fb03',
        '0192e2e0-0000-7000-8000-00000000fb04',
    ];

    /**
     * Feedback fixtures (I7a, PRD Feature #11) — so `/feedback` renders a POPULATED table for the
     * responsive-axe scan rather than its empty state.
     *
     * ⚠️ **ONE ROW PER STATUS, AND THAT IS THE POINT.** All four `FeedbackStatus` cases map to four
     * DIFFERENT badge variants (info / warning / success / neutral), and a scan over a table where every
     * pill is the same colour proves nothing about the other three — the same argument
     * {@see self::seedAuditLog()} makes for its event spread. `resolved` and `wont_fix` also exercise the
     * resolver column, which nothing else in this fixture fills.
     *
     * ⚠️ **THE LAST ROW CARRIES A DELIBERATELY LONG, UNBROKEN REMARK.** The remarks cell is the widest
     * free-text column on the page, and 375px overflow is the trap that has now caught three surfaces
     * (Domains, Audit log, and this one before it shipped).
     *
     * One row belongs to the REVIEWER for the reason `seedNotifications()` gives: Playwright only ever
     * signs in as the Owner, so a fixture where every row is the Owner's would hide a regression that
     * accidentally scoped this list to the viewer.
     *
     * Written with `forceCreate`, not through {@see App\Services\Feedback\FeedbackService}: the service
     * requires an `UploadedFile` and an open tenant context to attach a screenshot, and this fixture wants
     * neither. `status` is written from the enum, never a literal — the column has no CHECK constraint.
     */
    private function seedFeedback(User $owner, User $reviewer): void
    {
        $rows = [
            ['user' => $owner, 'route' => '/dashboard', 'status' => FeedbackStatus::New, 'remarks' => 'The date range picker resets when I switch tabs.'],
            ['user' => $reviewer, 'route' => '/submissions', 'status' => FeedbackStatus::Reviewed, 'remarks' => 'Could the inbox remember my last filter?'],
            ['user' => $owner, 'route' => '/forms', 'status' => FeedbackStatus::Resolved, 'remarks' => 'Duplicating a field lost its options — fixed now, thanks.'],
            [
                'user' => $reviewer,
                'route' => '/analytics',
                'status' => FeedbackStatus::WontFix,
                'remarks' => 'Please add a pie chart to the analytics page https://example.test/reference/dashboards/comparison-of-chart-types-for-categorical-data',
            ],
        ];

        foreach ($rows as $index => $row) {
            $id = self::FEEDBACK_FIXTURE_IDS[$index];
            $closed = $row['status']->isTerminal();

            $attributes = [
                'user_id' => $row['user']->getKey(),
                'route' => $row['route'],
                'remarks' => $row['remarks'],
                'browser_info' => ['viewport' => '1440x900', 'platform' => 'e2e-seed'],
                'status' => $row['status']->value,
                'submitted_at' => now()->subDays(($index + 1) * 3),
                'resolved_at' => $closed ? now()->subDay() : null,
                'resolved_by' => $closed ? $owner->getKey() : null,
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
     * A uuid that names nothing, doubling as the "target no longer exists" fixture (label and url both
     * resolve to null) AND as this seeder's idempotency sentinel.
     */
    private const AUDIT_FIXTURE_ID = '0192e2e0-0000-7000-8000-00000000a11d';

    /**
     * Audit-ledger fixtures (Increment I2) — so /audit-log renders with every badge variant and both kinds
     * of redacted diff for the responsive-axe scan.
     *
     * ⚠️ **THE TENANT ALREADY HAS AUDIT ROWS, so this is about SPREAD, not non-emptiness.** `PublishService`,
     * `WebhookEndpointService::create` and `ResourceGrantService::grant` all audit inside this seeder's own
     * transaction, and since I2 so does every `FormService` write — the page is populated with `created`
     * and `published` rows before this method runs. What is MISSING without it is the tail: no `deleted`,
     * `restored`, `archived` or `exported` row exists anywhere in the seeded data, and no diff carries a
     * redacted field. The scan would be green over three badge variants and a redaction notice that never
     * rendered.
     *
     * Two consequences of that, both easy to get wrong:
     *  - The idempotency guard CANNOT be "does any audit row exist" — it always will. It keys on
     *    {@see self::AUDIT_FIXTURE_ID}, a uuid nothing else writes.
     *  - Rows are written through the real {@see AuditLogger}, never `Audit::factory()`. The factory
     *    bypasses {@see AuditRedactor}, so a hand-written `redacted_fields` would be a fiction that the axe
     *    scan then validates. The redaction has to be PRODUCED by the code path the product uses.
     *
     * **Every row lands at `now()`, and that is an accepted limitation rather than an oversight.**
     * `Audit::UPDATED_AT` is null, `record()` takes no `occurredAt`, and back-dating would need an UPDATE
     * the append-only RLS shape denies. So the E2E exercises the date filters' PRESENCE AND LAYOUT, not
     * their selectivity. Do not add an `occurredAt` parameter to AuditLogger for a seeder's benefit.
     */
    private function seedAuditLog(User $owner, User $reviewer): void
    {
        if (Audit::query()->where('auditable_id', self::AUDIT_FIXTURE_ID)->exists()) {
            return;
        }

        $audit = app(AuditLogger::class);
        $ownerId = (string) $owner->getKey();
        $submissionId = (string) Str::uuid7();

        // `deleted` (danger) against a target that is GONE — the label/url-null path in the target cell.
        $audit->record(
            AuditEvent::Deleted,
            'form',
            self::AUDIT_FIXTURE_ID,
            old: ['title' => 'Pilot Survey (2025)', 'status' => 'archived'],
            new: null,
            actorId: $ownerId,
        );

        // `restored` (info) — emitted nowhere else in the seeded data.
        $audit->record(
            AuditEvent::Restored,
            'form',
            self::AUDIT_FIXTURE_ID,
            new: ['draft_version_id' => (string) Str::uuid7(), 'draft_version_number' => 3, 'source_version_number' => 2],
            actorId: $ownerId,
        );

        // `archived` — on screen this must read NEUTRAL, not the amber a form STATUS badge would use.
        $audit->record(
            AuditEvent::Archived,
            'form',
            self::AUDIT_FIXTURE_ID,
            old: ['status' => 'draft'],
            new: ['status' => 'archived', 'archived_at' => now()->toIso8601String()],
            actorId: $ownerId,
        );

        // `exported` (warning) with NO payload — the zero-changes row, which renders the disabled
        // "No field changes recorded" action state.
        $audit->record(AuditEvent::Exported, 'submission', $submissionId, actorId: $ownerId);

        // THE §2.1 FIXTURE. `guest_contact_email` and `guest_ip` are in AuditRedactor::PII['submission'],
        // so the real redactor placeholders BOTH sides and fills redacted_fields — giving one diff that
        // carries a redacted change AND an unredacted one, which is what makes the flag legible.
        $audit->record(
            AuditEvent::Updated,
            'submission',
            $submissionId,
            old: ['status' => 'submitted', 'guest_contact_email' => 'jane@example.test', 'guest_ip' => '203.0.113.9'],
            new: ['status' => 'approved', 'guest_contact_email' => 'jane@example.test', 'guest_ip' => '203.0.113.9'],
            actorId: $ownerId,
        );

        // A many-field diff whose secret is redacted from a DIFFERENT map (SECRETS, not PII), and whose
        // long URL is the widest unbreakable string on the page — the 375px overflow trap arriving on a
        // second surface after Domains.
        $audit->record(
            AuditEvent::Updated,
            'webhook_endpoint',
            (string) Str::uuid7(),
            old: ['name' => 'CRM sync', 'url' => 'https://api.crm.example.com/webhooks/forms', 'secret' => 'whsec_'.str_repeat('a', 48), 'status' => 'active'],
            new: ['name' => 'CRM sync (v2)', 'url' => 'https://api.crm.example.com/webhooks/forms/v2/inbound-with-a-deliberately-long-path', 'secret' => 'whsec_'.str_repeat('b', 48), 'status' => 'paused'],
            actorId: $ownerId,
        );

        // A RESOLVABLE target for the modal E2E to locate by its row text.
        $audit->record(
            AuditEvent::PermissionChanged,
            'users',
            (string) $reviewer->getKey(),
            old: ['role' => 'viewer'],
            new: ['role' => 'reviewer'],
            actorId: $ownerId,
        );
    }

    /** Resolve an existing identity on the pre-auth connection (users RLS hides non-members), or create it. */
    /**
     * The central-domain console operator, for Increment I10e's admin-console accessibility scan.
     *
     * ⚠️ THIS FIXTURE ASSERTS SOMETHING UNTRUE ABOUT THE ACCOUNT, AND THAT IS A DELIBERATE, BOUNDED TRADE.
     * It sets `two_factor_confirmed_at` while leaving `two_factor_secret` NULL — a state no real enrolment
     * produces. It works because the two gates read different things: {@see EnsureSuperAdminMfa} checks ONLY
     * `two_factor_confirmed_at`, so the console opens; Fortify's `hasEnabledTwoFactorAuthentication()`
     * additionally requires a SECRET when `fortify.features.two-factor.confirm` is true (it is), so login
     * issues no TOTP challenge. The account therefore reaches `/admin/*` in one POST, with no authenticator
     * app and no TOTP implementation in the test suite.
     *
     * Acceptable ONLY because this is an accessibility gate, not a security one: the middleware's real
     * behaviour — that an unenrolled super-admin is redirected to `admin.mfa.setup` — is covered in Pest by
     * `SuperAdminConsoleTest`, and nothing here weakens that. If a future increment wants to e2e the
     * ENROLMENT flow, it needs a genuine TOTP, not this.
     *
     * ⚠️ DO NOT reuse `UserFactory::confirmedTwoFactor()`. It writes `encrypt('PLACEHOLDERSECRET')`, which
     * flips `hasEnabledTwoFactorAuthentication()` to TRUE and makes the account TOTP-challenged at login with
     * a secret nobody can compute against — a permanent lockout. `DemoSeeder::ensureSuperAdmin()` carries the
     * same warning for the same reason, and deliberately leaves its own admin UNENROLLED so a human can
     * complete the real flow. These two seeders want opposite things and both are right.
     *
     * Idempotent, and outside the tenant transaction: it writes one central `users` row and no tenant-scoped
     * rows at all — idempotency comes from {@see self::resolveOrCreateUser()}, the same primitive every other
     * seeded account uses, plus a `forceFill` that is a no-op on a second run.
     *
     * ⚠️ THE PROMOTION MUST RUN ON `pgsql_privileged`, AND THE FIRST VERSION OF THIS METHOD DID NOT.
     * `$admin->forceFill([...])->save()` on the DEFAULT connection issues `UPDATE users … WHERE id = ?` as
     * `meridian_app`. `users` carries ENABLE **and FORCE** row-level security ({@see TenantIsolation::enableAndForce()}
     * applies it even to the table owner, and CI creates the database `OWNER meridian_app`, so the owner
     * exemption does not save it); the SELECT policy is join-shaped and fails closed with no context, the
     * permissive carve-out being `TO meridian_auth` only; and PostgreSQL applies SELECT policies to an UPDATE
     * that reads columns, which `WHERE id = ?` does. This operator has NO tenant membership BY DESIGN, so it
     * is invisible from every context and the promotion affected ZERO ROWS — silently, with no error, leaving
     * `is_super_admin` false and the console 404ing behind {@see EnsureSuperAdmin}. Proven, not reasoned:
     * `tests/Feature/Auth/CentralHostLoginTest.php` reproduces the no-op over the app connection.
     * `DemoSeeder::ensureSuperAdmin()` had the identical defect and is fixed the same way.
     *
     * The same invisibility is why `E2eSeederIdempotencyTest` has no case here: that file asserts through the
     * DEFAULT connection inside a tenant context, where this row cannot be seen, so a case there could only
     * assert “did not throw” — the near-vacuous shape this codebase keeps catching in review. The promotion is
     * covered instead by the connection-level test above, which is where the bug actually lived.
     */
    private function seedSuperAdmin(): void
    {
        $admin = $this->resolveOrCreateUser(self::SUPER_ADMIN_EMAIL, 'Console Operator', self::SUPER_ADMIN_PASSWORD);

        $this->promoteToSuperAdmin((string) $admin->id, $admin->two_factor_confirmed_at ?? now());
    }

    /**
     * Promote an operator over `pgsql_privileged`, and REFUSE TO REPORT SUCCESS ON ZERO ROWS.
     *
     * A query-builder UPDATE rather than `$model->save()` for one reason: it returns the affected row count,
     * which is the only observable that distinguishes "promoted" from "silently promoted nothing". Eloquent's
     * `performUpdate()` discards that number, which is precisely why the original bug could not be seen.
     *
     * The postcondition is deliberately conditional on the row being VISIBLE to this connection. Zero rows
     * means two different things: under `php artisan db:seed` (the real path, and the one CI's e2e job runs)
     * the row is committed and visible, so zero can only mean the write was refused — a bug, and it throws.
     * Inside `RefreshDatabase` the row was created on the DEFAULT connection's open transaction and this
     * separate session genuinely cannot see it, so zero is expected and says nothing. Distinguishing them is
     * what lets this guard be strict where it matters without breaking `DatabaseSeederSmokeTest`.
     *
     * This also covers a hazard the fix itself relies on: `config/database.php` defaults
     * `DB_PRIVILEGED_USERNAME` to a superuser, but on a managed Postgres where that role is merely the table
     * OWNER, `FORCE ROW LEVEL SECURITY` still applies and this write would regress to zero rows. Same class
     * of failure `OcrCompatibilityBackfill::assertPrivilegedRole()` already refuses to ship past.
     */
    private function promoteToSuperAdmin(string $userId, mixed $confirmedAt): void
    {
        $privileged = DB::connection('pgsql_privileged');

        $affected = $privileged->table('users')->where('id', $userId)->update([
            'is_super_admin' => true,
            'two_factor_confirmed_at' => $confirmedAt,
            'two_factor_secret' => null,
        ]);

        if ($affected === 0 && $privileged->table('users')->where('id', $userId)->exists()) {
            throw new RuntimeException(
                "Failed to promote {$userId} to super-admin: the row exists but the UPDATE affected zero rows. "
                .'The pgsql_privileged role is not bypassing FORCE row-level security on `users`.'
            );
        }
    }

    /**
     * ⚠️ `$verified` IS LOAD-BEARING FROM J3a, AND THE DEFAULT IS THE ONE THAT KEEPS THE E2E SUITE ALIVE.
     * `routes/tenant.php`'s authenticated group now carries `verified`, so an unverified identity is bounced
     * to `/email/verify` — including at `tests/e2e/global-setup.ts`'s very first navigation, which waits on a
     * dashboard URL. Before J3a this seeder set no verification timestamp at all, so every e2e identity was
     * unverified and mounting the gate would have failed all 506 specs before one of them ran.
     *
     * ⚠️⚠️ ONE INSERT — `forceFill()` ON A NEW MODEL — AND NEVER `create()` THEN `save()`. THIS COST A RED
     * E2E RUN, AND THE FIRST FIX FOR IT WAS ITSELF THE BUG.
     *
     * Two separate traps stack here, and only the second is fatal:
     *   1. `User` declares `#[Fillable(['name', 'email', 'password'])]`, so `email_verified_at` is not
     *      mass-assignable. `artisan db:seed` wraps the run in `Model::unguarded()`
     *      (`SeedCommand::handle()`), so a fourth key in `create()` works THERE and silently drops the
     *      moment the seeder is constructed and run directly — which is what `E2eSeederIdempotencyTest`
     *      and `DemoSeederIdempotencyTest` do.
     *   2. **The follow-up `save()` that looks like the obvious fix updates ZERO ROWS AND THROWS NOTHING.**
     *      `users` carries a permissive INSERT policy but an **own-row UPDATE** policy keyed on
     *      `app.current_user_id`, which is null throughout seeding — so a create-then-stamp pair matches no
     *      UPDATE policy, reports success, and leaves the account unverified. With `verified` mounted on the
     *      authenticated group that is a LOCKOUT: `tests/e2e/global-setup.ts` signs the demo owner in and is
     *      redirected to `/email/verify`, and all 506 specs die before one runs. `promoteToSuperAdmin()`
     *      below already carries the privileged-connection version of this same lesson.
     *
     * `forceFill()` on a NEW model carries the non-fillable column into the INSERT itself, where the
     * permissive policy applies — the shape Lane B's P1b JIT provisioning independently arrived at.
     */
    /**
     * The two standalone fixtures the J3b accessibility scans need, neither of which belongs to a
     * workspace (see the constants above for why the two-factor identity deliberately has no membership).
     *
     * ⚠️ THE 2FA USER IS ONE INSERT, NOT A CREATE-THEN-STAMP. `two_factor_secret` and
     * `two_factor_confirmed_at` go into the INSERT itself, where `users_app_insert`'s permissive policy
     * applies. A follow-up `save()` would match no row — `app.current_user_id` is null throughout seeding
     * and PostgreSQL applies SELECT policies to an UPDATE whose WHERE reads a column — so it would report
     * success, write nothing, and leave an identity that never challenges. That is the defect J3b's first
     * PR fixed in the product and the one this seeder has already been bitten by once.
     */
    private function seedAuthScanFixtures(): void
    {
        if (User::on('pgsql_auth')->where('email', self::TWO_FACTOR_EMAIL)->doesntExist()) {
            $user = new User;
            $user->forceFill([
                'name' => 'Tessa Twofactor',
                'email' => self::TWO_FACTOR_EMAIL,
                'password' => Hash::make(self::TWO_FACTOR_PASSWORD),
                'email_verified_at' => now(),
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt(self::TWO_FACTOR_SECRET),
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([])),
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        // `updateOrInsert`, because the table's primary key is the EMAIL: a second seeder run would
        // otherwise violate it rather than refresh the row. Not tenant-scoped and not under RLS.
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => self::OWNER_EMAIL],
            ['token' => Hash::make(self::RESET_TOKEN), 'created_at' => now()],
        );
    }

    private function resolveOrCreateUser(string $email, string $name, string $password, bool $verified = true): User
    {
        $existing = User::on('pgsql_auth')->where('email', $email)->first();
        if ($existing !== null) {
            $existing->setConnection((string) config('database.default'));

            return $existing;
        }

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => $verified ? now() : null,
        ])->save();

        return $user;
    }

    private function roleId(string $name): string
    {
        return (string) Role::query()->whereNull('tenant_id')->where('name', $name)->value('id');
    }

    /**
     * The H24b2 analytics fixture — a time-spread, multi-form, multi-source, `last_saved_at`-marked
     * submission set, plus the one flagged-for-reporting form the question explorer needs.
     *
     * ── Why this is not the Clinic Intake block below ───────────────────────────────────────────────────
     * That block is guarded `Submission::where('form_id', $intakeForm->id)->doesntExist()`, so writing a
     * single Clinic Intake row HERE would trip it on a fresh database and its three status-varied rows
     * would never be created — silently deleting the Badge-variety fixture `responsive-axe`'s "Submission
     * detail" scan depends on. This block therefore writes nothing on Clinic Intake, and runs BEFORE it so
     * those rows keep the highest uuidv7 ids and stay the inbox's first page (it orders by id, not by
     * `submitted_at`), leaving that spec on exactly the row it opens today.
     *
     * ── NO doesntExist() GUARD, deliberately ────────────────────────────────────────────────────────────
     * Every write is an UPSERT keyed on a deterministic `client_submission_uuid` (its per-tenant partial
     * unique index is the natural key), so a re-seed CONVERGES this fixture rather than skipping it. That
     * is the same trap the Business-plan upsert above already records, and it is the whole reason
     * "re-seeding does not heal Playwright fixture drift" was ever true of this file.
     *
     * ── The dates are RELATIVE, and that is the feature ─────────────────────────────────────────────────
     * `/analytics` opens on a rolling window. A fixed calendar date would fall out of it and the page would
     * go empty the day after this was written. What is deterministic here is the SHAPE — the per-form,
     * per-source, per-status and per-locale distributions below, and the four genuinely empty days that
     * make zero-filling visible as a floor rather than as a plausible line.
     *
     * ── PUBLIC, as a seam for the idempotency test ──────────────────────────────────────────────────────
     * `E2eSeederIdempotencyTest` re-runs THIS rather than the whole seeder, because {@see run()} resolves
     * identities on the separate `pgsql_auth` connection and a second run inside `RefreshDatabase`'s
     * uncommitted transaction cannot see the first run's user — a 23505 that says nothing about whether the
     * fixture converges. This method touches no identity, so it re-runs cleanly and proves exactly the
     * obligation ("upsert-safe") that was otherwise only prose.
     */
    public function seedAnalyticsFixture(User $owner, Tenant $tenant): void
    {
        $uptake = $this->seedProgrammeUptakeForm($owner, $tenant);

        foreach (self::analyticsFixtureRows() as $index => $row) {
            $form = Form::query()
                ->where('title', $row['form'])
                ->whereNotNull('current_published_version_id')
                ->first();

            if ($form === null) {
                continue; // a database seeded before this form existed — degrade, never fatal
            }

            $version = FormVersion::findOrFail($form->current_published_version_id);
            $draft = $row['status'] === SubmissionStatus::Draft;
            $createdAt = now()->utc()->subDays($row['back'])->setTime($row['hour'], 0);
            $submittedAt = $createdAt->copy()->addSeconds($row['fill_seconds'] ?? 0);

            $submission = Submission::updateOrCreate(
                ['client_submission_uuid' => self::fixtureUuid("h24b2:{$row['form']}:{$row['back']}:{$index}")],
                [
                    'form_id' => $form->id,
                    'form_version_id' => $version->id,
                    'respondent_user_id' => $row['source'] === SubmissionSource::Manual ? $owner->id : null,
                    'status' => $row['status'],
                    'source' => $row['source'],
                    'locale' => $row['locale'],
                    'submitted_at' => $draft ? null : $submittedAt,
                    // ADR-0011 §D5's durable marker: set only on rows that were once explicitly saved as a
                    // draft. It survives promotion, which is what makes the denominator computable at all.
                    'last_saved_at' => $row['saved'] ? $createdAt : null,
                    'draft_expires_at' => $draft ? $createdAt->copy()->addDays(30) : null,
                ],
            );

            // `created_at` is NOT mass-assignable and Eloquent would stamp the insert time over it — which
            // is exactly the column §D5 reads as "first save". The tests/Pest.php seedCountableAt() device.
            $submission->forceFill(['created_at' => $createdAt])->saveQuietly();

            SubmissionAnswer::updateOrCreate(
                ['submission_id' => $submission->id],
                [
                    'form_version_id' => $version->id,
                    // Empty for `screened_out` (I9a) — that state MEANS the respondent was shown no
                    // questions, so a populated document would contradict it on the detail page. The 1:1
                    // answer row is still written, because the inbox and PDF presenters read through it.
                    'answers' => $row['status'] === SubmissionStatus::ScreenedOut ? [] : $this->sampleAnswers($version),
                    'attachment_refs' => [],
                ],
            );

            if ($form->is($uptake)) {
                $this->projectUptakeIndex($submission, $version, $index);
            }
        }

        $this->seedSavedReportViews($owner, $tenant);
    }

    /**
     * The seventh PUBLISHED form, and the only one carrying `is_queryable` fields.
     *
     * Two jobs, both load-bearing for the axe gate. **(a)** Six published forms make the `form` axis
     * overflow the default top-5 by exactly one, so `other.categories` is 1 and only the SINGULAR copy
     * branch is ever rendered; a seventh reaches 2 and exercises the plural. **(b)** No seeded form has a
     * field flagged for reporting, so without this the question explorer could only ever render refusals
     * — the populated chart, the numeric summary and the coverage disclosure would go unscanned.
     *
     * `notes` is deliberately left UNFLAGGED so the picker's two groups are both non-empty on one screen.
     */
    private function seedProgrammeUptakeForm(User $owner, Tenant $tenant): Form
    {
        $existing = Form::query()->where('title', 'Programme Uptake')->first();

        if ($existing !== null) {
            return $existing; // once-only: re-publishing would grow version history without end
        }

        $form = app(FormService::class)->create(
            $tenant, $owner, 'Programme Uptake', 'Field-team uptake reporting (H24b2 analytics demo).'
        );
        $b = app(FormBuilderService::class);
        $section = $b->addSection($form); // non-repeatable — a field inside a repeat can never project

        // StructuralValidationGate enforces `is_queryable ⇒ indexed_data_type` at publish, so both are set
        // together. The four types below are the four the explorer renders differently: text and date are
        // categorical, number takes the min/max/average/median summary, and boolean is the one value column
        // with no B-tree — which is the `indexed_column: false` disclosure the page has to be able to show.
        $b->addField($form, $owner, FieldType::ShortText, $section->id)
            ->update(['label' => 'District', 'key' => 'district', 'is_queryable' => true, 'indexed_data_type' => 'text']);
        $b->addField($form, $owner, FieldType::Integer, $section->id)
            ->update(['label' => 'Households reached', 'key' => 'households_reached', 'is_queryable' => true, 'indexed_data_type' => 'number']);
        $b->addField($form, $owner, FieldType::Date, $section->id)
            ->update(['label' => 'Visited on', 'key' => 'visited_on', 'is_queryable' => true, 'indexed_data_type' => 'date']);
        $b->addField($form, $owner, FieldType::YesNo, $section->id)
            ->update(['label' => 'Follow-up needed', 'key' => 'follow_up_needed', 'is_queryable' => true, 'indexed_data_type' => 'boolean']);
        $b->addField($form, $owner, FieldType::LongText, $section->id)
            ->update(['label' => 'Notes', 'key' => 'notes']);

        app(PublishService::class)->publish($form->refresh(), $owner);

        // The fixture seeds `fil` submissions, and the locale FILTER is derived from what forms declare
        // (never from a DISTINCT over `submissions`, which is unbounded and free-text on the ingest paths).
        // Without this the locale breakdown would show a `fil` bar that the filter beside it cannot select
        // — a chart and its own control disagreeing about which languages exist.
        $form->update(['supported_locales' => ['en', 'es', 'fil']]);

        return $form->refresh();
    }

    /**
     * Project the four flagged answers into `submission_answer_index` through the REAL mapper.
     *
     * ── Why not SubmissionPipeline ──────────────────────────────────────────────────────────────────────
     * Five reasons, and the first one alone settles it. **(1)** `SubmissionPipeline::persist()` hard-codes
     * `submitted_at => now()`, so every row would land in ONE bucket — the exact degeneracy this fixture
     * exists to remove. **(2)** It fires `SubmissionCreated`, whose two deliberately-synchronous listeners
     * create delivery rows and enqueue jobs; the e2e job runs `QUEUE_CONNECTION: sync`, so those would
     * attempt real outbound HTTPS during `db:seed` AND pollute the delivery ledgers the webhook/connector
     * axe scans assert on. **(3)** `FormAcceptanceGuard` refuses the closed form outright. **(4)** Stages 1
     * and 3 would refuse synthetic payloads against the branching, repeat and geo forms. **(5)**
     * `MeterSubmissionUsage` would inflate the `entitlements.quotas` every page renders.
     *
     * Only the INSERT is hand-rolled: the coercion is {@see AnswerIndexProjector}, the production writer's
     * own collaborator, so this fixture cannot drift from what `SubmissionFinalizer::projectIndex()` does.
     *
     * One row's `district` is deliberately LEFT EMPTY, so coverage reads 4 of 5. A 100% coverage figure
     * proves nothing about §D3(iii)'s disclosure, which is the thing the page must be able to render.
     */
    private function projectUptakeIndex(Submission $submission, FormVersion $version, int $index): void
    {
        $projector = app(AnswerIndexProjector::class);

        /** @var array<int, array<string, mixed>> $values */
        $values = [
            ['district' => 'Malate', 'households_reached' => 12, 'visited_on' => '2026-06-02', 'follow_up_needed' => true],
            ['district' => 'Sampaloc', 'households_reached' => 34, 'visited_on' => '2026-06-09', 'follow_up_needed' => false],
            ['district' => null, 'households_reached' => 34, 'visited_on' => '2026-06-16', 'follow_up_needed' => true],
            ['district' => 'Tondo', 'households_reached' => 51, 'visited_on' => '2026-06-23', 'follow_up_needed' => true],
            ['district' => 'Sampaloc', 'households_reached' => 88, 'visited_on' => '2026-06-30', 'follow_up_needed' => false],
        ];

        $answers = $values[$index % count($values)];

        foreach ($version->fields()->get() as $field) {
            $projected = $projector->project($field, $answers[$field->key] ?? null);

            if ($projected === null) {
                continue; // `notes` is unflagged, and the null district projects nothing — both intended
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
     * Two saved reports for the Owner, so `/analytics` has a non-empty list for the axe scan WITHOUT any
     * spec having to create one — which under `workers: 1` across three viewport projects would leave a
     * row behind on any run that died mid-way and collide on the unique name the next time round.
     *
     * The second is written DIRECTLY rather than through the service, because the service would refuse it.
     * That is the point: a `v: 99` definition can only arise from an older build, and ADR-0011 §D8 requires
     * it to render as a stated refusal beside the working one. Seeding it is what puts that state inside a
     * merge-blocking scan.
     */
    private function seedSavedReportViews(User $owner, Tenant $tenant): void
    {
        $today = now()->utc();

        SavedReportView::updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'name' => 'Field team — last 30 days'],
            ['definition' => (new AnalyticsQuery(
                from: CarbonImmutable::parse($today->copy()->subDays(AnalyticsQuery::DEFAULT_RANGE_DAYS)->toDateString()),
                to: CarbonImmutable::parse($today->toDateString()),
                axis: AnalyticsAxis::Source,
            ))->toArray()],
        );

        SavedReportView::updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'name' => 'Legacy monthly rollup'],
            ['definition' => ['v' => 99, 'from' => '2026-01-01', 'to' => '2026-01-31']],
        );
    }

    /**
     * The fixture's shape, as data.
     *
     * Reconciles to: form 9/6/5/4/3(legacy)/2/1 → top-5 plus `other{count: 3, categories: 2}`;
     * source guest 14 / manual 8 / offline_sync 4 / api_import 2 / ocr_single 2 (five categories, NO
     * overflow — so `other === null` and `other !== null` are BOTH reachable from real seeded data);
     * locale en / es / fil plus five NULLs, giving one axis both a multi-bucket breakdown and a non-zero
     * Unassigned at once.
     *
     * §D5's denominator is `last_saved_at IS NOT NULL` AND `source IN (guest, manual)`: five converted plus
     * three unconverted drafts → denominator 8, converted 5, 62.5%, median 900s. The `offline_sync` row at
     * d-22 ALSO carries `last_saved_at` and must NOT enter it — an adversarial row, and the only reason
     * deleting that source restriction is a detectable mutation.
     *
     * @return list<array{form: string, back: int, hour: int, status: SubmissionStatus, source: SubmissionSource, locale: ?string, saved: bool, fill_seconds: ?int}>
     */
    private static function analyticsFixtureRows(): array
    {
        $s = SubmissionStatus::Submitted;
        $a = SubmissionStatus::Approved;
        $r = SubmissionStatus::Returned;
        $u = SubmissionStatus::UnderReview;
        $x = SubmissionStatus::Archived;
        $d = SubmissionStatus::Draft;
        $so = SubmissionStatus::ScreenedOut;

        $chs = 'Community Health Survey';
        $hr = 'Household Roster';
        $pu = 'Programme Uptake';
        $fts = 'Field Types Showcase';
        $br = 'Branching Router';
        $cs = 'Closed Survey';

        $g = SubmissionSource::Guest;
        $m = SubmissionSource::Manual;
        $o = SubmissionSource::OfflineSync;
        $api = SubmissionSource::ApiImport;
        $ocr = SubmissionSource::OcrSingle;

        return [
            // ── Community Health Survey ×9 ────────────────────────────────────────────────────────────
            ['form' => $chs, 'back' => 27, 'hour' => 9, 'status' => $s, 'source' => $g, 'locale' => 'en', 'saved' => false, 'fill_seconds' => null],
            ['form' => $chs, 'back' => 24, 'hour' => 10, 'status' => $a, 'source' => $g, 'locale' => 'en', 'saved' => true, 'fill_seconds' => 240],
            ['form' => $chs, 'back' => 24, 'hour' => 14, 'status' => $s, 'source' => $g, 'locale' => 'es', 'saved' => false, 'fill_seconds' => null],
            ['form' => $chs, 'back' => 19, 'hour' => 11, 'status' => $s, 'source' => $ocr, 'locale' => 'en', 'saved' => false, 'fill_seconds' => null],
            ['form' => $chs, 'back' => 15, 'hour' => 8, 'status' => $a, 'source' => $g, 'locale' => 'en', 'saved' => true, 'fill_seconds' => 5700],
            ['form' => $chs, 'back' => 15, 'hour' => 12, 'status' => $s, 'source' => $g, 'locale' => 'fil', 'saved' => false, 'fill_seconds' => null],
            ['form' => $chs, 'back' => 15, 'hour' => 16, 'status' => $u, 'source' => $g, 'locale' => null, 'saved' => false, 'fill_seconds' => null],
            ['form' => $chs, 'back' => 8, 'hour' => 9, 'status' => $s, 'source' => $g, 'locale' => 'es', 'saved' => false, 'fill_seconds' => null],
            ['form' => $chs, 'back' => 3, 'hour' => 15, 'status' => $s, 'source' => $api, 'locale' => 'en', 'saved' => false, 'fill_seconds' => null],
            // ── Household Roster ×6 ───────────────────────────────────────────────────────────────────
            ['form' => $hr, 'back' => 26, 'hour' => 9, 'status' => $s, 'source' => $g, 'locale' => 'en', 'saved' => false, 'fill_seconds' => null],
            ['form' => $hr, 'back' => 21, 'hour' => 13, 'status' => $a, 'source' => $g, 'locale' => 'fil', 'saved' => true, 'fill_seconds' => 540],
            ['form' => $hr, 'back' => 16, 'hour' => 10, 'status' => $r, 'source' => $g, 'locale' => 'en', 'saved' => false, 'fill_seconds' => null],
            ['form' => $hr, 'back' => 11, 'hour' => 11, 'status' => $s, 'source' => $o, 'locale' => 'en', 'saved' => false, 'fill_seconds' => null],
            ['form' => $hr, 'back' => 6, 'hour' => 14, 'status' => $s, 'source' => $o, 'locale' => null, 'saved' => false, 'fill_seconds' => null],
            ['form' => $hr, 'back' => 1, 'hour' => 9, 'status' => $s, 'source' => $g, 'locale' => 'es', 'saved' => false, 'fill_seconds' => null],
            // ── Programme Uptake ×5 (the only index-projecting form) ──────────────────────────────────
            ['form' => $pu, 'back' => 25, 'hour' => 9, 'status' => $s, 'source' => $m, 'locale' => 'en', 'saved' => false, 'fill_seconds' => null],
            ['form' => $pu, 'back' => 20, 'hour' => 10, 'status' => $a, 'source' => $m, 'locale' => 'en', 'saved' => true, 'fill_seconds' => 900],
            ['form' => $pu, 'back' => 14, 'hour' => 11, 'status' => $s, 'source' => $m, 'locale' => 'fil', 'saved' => false, 'fill_seconds' => null],
            ['form' => $pu, 'back' => 9, 'hour' => 12, 'status' => $s, 'source' => $m, 'locale' => 'en', 'saved' => false, 'fill_seconds' => null],
            ['form' => $pu, 'back' => 2, 'hour' => 13, 'status' => $r, 'source' => $m, 'locale' => 'es', 'saved' => false, 'fill_seconds' => null],
            // ── Field Types Showcase ×4 ───────────────────────────────────────────────────────────────
            ['form' => $fts, 'back' => 23, 'hour' => 9, 'status' => $s, 'source' => $ocr, 'locale' => 'en', 'saved' => false, 'fill_seconds' => null],
            ['form' => $fts, 'back' => 17, 'hour' => 10, 'status' => $a, 'source' => $g, 'locale' => 'en', 'saved' => true, 'fill_seconds' => 1260],
            ['form' => $fts, 'back' => 10, 'hour' => 11, 'status' => $s, 'source' => $o, 'locale' => 'es', 'saved' => false, 'fill_seconds' => null],
            ['form' => $fts, 'back' => 4, 'hour' => 15, 'status' => $x, 'source' => $api, 'locale' => null, 'saved' => false, 'fill_seconds' => null],
            // ── Branching Router ×2. The d-22 row is the ADVERSARIAL one: offline_sync WITH last_saved_at,
            //    which §D5's `source IN (guest, manual)` restriction must keep out of the denominator.
            //    The d-5 row carries `screened_out` (I9a), and it is on THIS form deliberately: the Branching
            //    Router is Doc #27 §4.1's own example — every section gated on a URL-prefilled hidden field —
            //    so a bare visit is exactly the empty step projection the state is derived from. It was
            //    CONVERTED from `under_review` rather than appended, so `submissions` (33) and `countable`
            //    (30) both hold; only the split within the countable rows moved.
            ['form' => $br, 'back' => 22, 'hour' => 9, 'status' => $s, 'source' => $o, 'locale' => 'en', 'saved' => true, 'fill_seconds' => 1800],
            ['form' => $br, 'back' => 5, 'hour' => 16, 'status' => $so, 'source' => $g, 'locale' => 'fil', 'saved' => false, 'fill_seconds' => null],
            // ── Closed Survey ×1 — capacity-closed, but a direct write is not the ingest path. ────────
            ['form' => $cs, 'back' => 12, 'hour' => 10, 'status' => $s, 'source' => $g, 'locale' => null, 'saved' => false, 'fill_seconds' => null],
            // ── Three UNCONVERTED drafts. Excluded from every countable aggregate by scopeCountable(), so
            //    any chart that forgot ->countable() jumps by exactly 3, visibly, on the page.
            ['form' => $chs, 'back' => 13, 'hour' => 9, 'status' => $d, 'source' => $g, 'locale' => 'en', 'saved' => true, 'fill_seconds' => null],
            ['form' => $hr, 'back' => 7, 'hour' => 10, 'status' => $d, 'source' => $g, 'locale' => 'es', 'saved' => true, 'fill_seconds' => null],
            ['form' => $pu, 'back' => 2, 'hour' => 8, 'status' => $d, 'source' => $m, 'locale' => 'en', 'saved' => true, 'fill_seconds' => null],
        ];
    }

    /**
     * A plausible answer document keyed by each field's `key`, for the inbox detail demo.
     *
     * @return array<string, mixed>
     */
    private function sampleAnswers(FormVersion $version): array
    {
        $answers = [];
        foreach ($version->fields()->get() as $field) {
            $value = $this->sampleValue($field);
            if ($value !== null) {
                $answers[$field->key] = $value;
            }
        }

        return $answers;
    }

    private function sampleValue(FormField $field): mixed
    {
        $firstOption = $field->config['options'][0]['value'] ?? null;

        return match ($field->field_type) {
            FieldType::ShortText => 'Jane Doe',
            FieldType::LongText => 'No further notes.',
            FieldType::Integer => 34,
            FieldType::YesNo => true,
            FieldType::Date => '2026-06-15',
            FieldType::SingleSelect => $firstOption,
            FieldType::MultiSelect => $firstOption !== null ? [$firstOption] : [],
            default => null,
        };
    }
}
