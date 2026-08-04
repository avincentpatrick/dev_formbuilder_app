<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnalyticsAxis;
use App\Enums\BillingInterval;
use App\Enums\ConnectionStatus;
use App\Enums\DomainEventType;
use App\Enums\FieldType;
use App\Enums\FormScheduleState;
use App\Enums\PlanTier;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\TenantUserStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEndpointStatus;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
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
use App\Services\Scoping\ScopeNodeService;
use App\Services\Submissions\AnswerIndexProjector;
use App\Services\Webhooks\WebhookEndpointService;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
    private const OWNER_EMAIL = 'demo@meridian.test';

    private const OWNER_PASSWORD = 'meridian-e2e-2026';

    private const PENDING_EMAIL = 'pending@meridian.test';

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

        $owner = $this->resolveOrCreateUser(self::OWNER_EMAIL, 'Demo Owner', self::OWNER_PASSWORD);
        $pending = $this->resolveOrCreateUser(self::PENDING_EMAIL, 'Pending Teammate', Str::random(48));
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
                    'invite_token' => hash('sha256', Str::random(48)),
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

            $this->seedScopingHierarchy($owner, $reviewer);
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

    /** Resolve an existing identity on the pre-auth connection (users RLS hides non-members), or create it. */
    private function resolveOrCreateUser(string $email, string $name, string $password): User
    {
        $existing = User::on('pgsql_auth')->where('email', $email)->first();
        if ($existing !== null) {
            $existing->setConnection((string) config('database.default'));

            return $existing;
        }

        return User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
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
                    'answers' => $this->sampleAnswers($version),
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
            ['form' => $br, 'back' => 22, 'hour' => 9, 'status' => $s, 'source' => $o, 'locale' => 'en', 'saved' => true, 'fill_seconds' => 1800],
            ['form' => $br, 'back' => 5, 'hour' => 16, 'status' => $u, 'source' => $g, 'locale' => 'fil', 'saved' => false, 'fill_seconds' => null],
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
     * A stable UUID from a human-readable fixture key, so a row is greppable and a re-seed converges.
     *
     * Hand-rolled rather than `Ramsey\Uuid::uuid5()`: that package is only a transitive dependency here,
     * and `Str::uuid()`/`orderedUuid()` are both random, which is exactly what an upsert key must not be.
     */
    private static function fixtureUuid(string $key): string
    {
        $hash = hash('sha256', $key);

        return sprintf(
            '%s-%s-5%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec(substr($hash, 16, 2)) & 0x3F) | 0x80).substr($hash, 18, 2),
            substr($hash, 20, 12),
        );
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
