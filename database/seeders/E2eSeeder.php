<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FieldType;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Role;
use App\Models\ScopeNode;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Authorization\ResourceGrantService;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Scoping\ScopeNodeService;
use App\Support\Tenancy\TenantContext;
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

            $this->seedScopingHierarchy($owner, $reviewer);
        });

        $tenant->forceFill(['owner_user_id' => $owner->id])->save();

        TenantContext::flush();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
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
