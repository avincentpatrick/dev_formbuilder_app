<?php

declare(strict_types=1);
use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\PlanTier;
use App\Enums\RequiredMode;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\TenantUserStatus;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Plan;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Entitlements\EntitlementService;
use App\Services\Expressions\ExpressionEvaluator;
use App\Services\Expressions\ExpressionLexer;
use App\Services\Expressions\ExpressionParser;
use App\Services\Expressions\FunctionRegistry;
use App\Services\Expressions\StructuredRuleLowering;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Scoping\ScopeNodeService;
use App\Services\Validation\SemanticValidator;
use App\Services\Validation\StructuredRuleEvaluator;
use App\Support\Tenancy\DnsTxtResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\FakeDnsTxtResolver;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full framework against real PostgreSQL (never sqlite
| — RLS can't be validated on sqlite; see docs/testing-strategy.md §2).
| RefreshDatabase is applied per-file where a test actually touches the DB.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Shared RBAC / membership test helpers (Increment B2a/B2b)
|--------------------------------------------------------------------------
| Defined here (not in a single test file) so any test — including single-file
| runs — can use them, and so there is exactly one definition (Pest loads every
| test file into one process; a duplicate top-level function would fatal).
*/

/** Set BOTH the RLS session context and Spatie's permissions team to the tenant (as the middleware does). */
function enterTenant(string $tenantId, ?string $userId = null): void
{
    TenantContext::applyLocal($tenantId, $userId);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
}

/** The id of a global (platform-seeded) role by name, read on the privileged connection. */
function catalogRole(string $name): string
{
    return (string) DB::connection('pgsql_privileged')->table('roles')->where('name', $name)->value('id');
}

/**
 * A NEW user, made an active member of the current-context tenant with the given role, and left as the
 * acting user in the RLS context (requires enterTenant already called).
 *
 * Moved here from tests/Feature/Api/ApiV1Test.php in H22a. It was a top-level function in that one test
 * file, so it only resolved when that file happened to be loaded into the process — a single-file run of
 * any other API test died with "Call to undefined function apiMember()". That is precisely the failure
 * this section's header describes.
 */
function apiMember(string $roleName): User
{
    $user = User::factory()->create();
    $tenantId = TenantContext::currentTenantId();
    enterTenant((string) $tenantId, $user->id);
    makeActiveMember($user, $roleName);

    return $user;
}

/** Create an active membership + assign its tenant-scoped role (requires enterTenant already called). */
function makeActiveMember(User $user, string $roleName): void
{
    TenantUser::create([
        'user_id' => $user->id,
        'status' => TenantUserStatus::Active,
        'joined_at' => now(),
        'invited_role_id' => catalogRole($roleName),
    ]);
    $user->syncRoles([$roleName]);
}

/*
|--------------------------------------------------------------------------
| Shared entitlement test helpers (H5b). Seed the catalog + assign a plan to the CURRENT tenant so a test's
| hard-block quotas are governed by a known tier. Require enterTenant already called (so BelongsToTenant
| auto-fills the subscription's tenant_id and the strict RLS write passes). Without a subscription a tenant
| resolves to `free` only if the catalog is seeded; with no plans at all it resolves to null ⇒ unlimited, so
| most of the suite (which seeds no plans) is unaffected by enforcement.
|--------------------------------------------------------------------------
*/

/**
 * Seed all 5 tiers (idempotent, default connection) and give the current tenant an active subscription on
 * `$tier` — REPLACING any it already has.
 *
 * ── Why the delete is load-bearing (found in H24b2) ─────────────────────────────────────────────────────
 * This helper used to APPEND, so calling it twice on one tenant — a `beforeEach` setting a baseline tier
 * and then a test overriding it, which is how several suites are written — left TWO active subscriptions.
 * {@see EntitlementService::resolvePlan()} picks with `latest('created_at')`, and two rows written inside
 * the same second are a TIE: PostgreSQL breaks it by physical row order, which shifts with how much else
 * the run has written. So which tier "won" depended on how many tests had run before, and a suite could go
 * from green to red purely because a file was added somewhere else in the tree.
 *
 * It surfaced as `ConnectorOAuthFlowTest`'s "refuses to start a flow on a plan without native connectors":
 * with the Starter baseline winning over the Free override the feature gate never fired, so the controller
 * redirected to the provider — a 302 with no toast, which passes `assertRedirect()` and fails only on the
 * assertion after it. A test that fails OPEN proves nothing, and this one had been proving nothing on
 * whichever orderings happened to hit it.
 *
 * Production is unaffected: a tenant has one subscription, and real ones are not created in the same second.
 */
function assignPlanTier(PlanTier $tier): Subscription
{
    app(PlanSeeder::class)->run();
    $plan = Plan::query()->where('code', $tier->value)->firstOrFail();
    // RLS scopes this to the current tenant, so it can only ever clear the one under test.
    Subscription::query()->delete();
    $subscription = Subscription::factory()->forPlan($plan)->create();

    // Drop any plan the scoped EntitlementService memoized before this assignment — in production the
    // assign and the guarded action are separate requests (a fresh scoped service); in one test process the
    // memo persists, so forget() here mirrors that fresh-request reality.
    app(EntitlementService::class)->forget();

    return $subscription;
}

/** Give the current tenant an active subscription on an unlimited plan (Enterprise = all-null quotas). */
function assignUnlimitedPlan(): Subscription
{
    return assignPlanTier(PlanTier::Enterprise);
}

/*
|--------------------------------------------------------------------------
| Shared form-versioning test helpers (Increment D). Require enterTenant already called (so
| BelongsToTenant auto-fills tenant_id and the RLS write policies pass).
|--------------------------------------------------------------------------
*/

/** A bare durable form record (no version/collaborator — for structural tests that build versions by hand). */
function makeForm(User $user, string $title = 'Survey'): Form
{
    return Form::create([
        'title' => $title,
        'default_locale' => 'en',
        'owner_user_id' => $user->id,
        'created_by' => $user->id,
    ]);
}

/** A draft form_versions row for a form. */
function makeDraftVersion(Form $form, int $number = 1): FormVersion
{
    return FormVersion::create([
        'form_id' => $form->id,
        'version_number' => $number,
        'status' => FormVersionStatus::Draft,
        'title' => $form->title,
        'schema_snapshot' => [],
    ]);
}

/**
 * Add a field to a (draft) version.
 *
 * @param  array<string, mixed>  $extra
 */
function addFormField(FormVersion $version, User $user, string $key, FieldType $type = FieldType::ShortText, int $sequence = 0, array $extra = []): FormField
{
    return FormField::create(array_merge([
        'form_version_id' => $version->id,
        'key' => $key,
        'field_type' => $type,
        'label' => ucfirst(str_replace('_', ' ', $key)),
        'is_required' => RequiredMode::Optional,
        'sequence' => $sequence,
        'created_by' => $user->id,
    ], $extra));
}

/*
|--------------------------------------------------------------------------
| Shared expression-engine builders (Increment F2). Unit tests do NOT boot the container (only Feature
| does — see the ->in('Feature') binding above), so the engine is hand-constructed here rather than
| resolved from app(). Defined in Pest.php so a single-file Unit run resolves them too.
|--------------------------------------------------------------------------
*/

function makeExpressionLexer(): ExpressionLexer
{
    return new ExpressionLexer;
}

function makeExpressionParser(): ExpressionParser
{
    return new ExpressionParser(new ExpressionLexer, new FunctionRegistry);
}

function makeExpressionEvaluator(): ExpressionEvaluator
{
    return new ExpressionEvaluator(new ExpressionParser(new ExpressionLexer, new FunctionRegistry));
}

/*
|--------------------------------------------------------------------------
| Shared semantic-validation builders (Increment F3). The validator + rule engine are hand-constructed
| (Unit does not boot the container) and driven from UNSAVED models — casts apply without a database, so
| the pure evaluate() path needs no Postgres. Attribute setters use magic set (enum/array casts round-trip).
|--------------------------------------------------------------------------
*/

function makeStructuredRuleEvaluator(): StructuredRuleEvaluator
{
    return new StructuredRuleEvaluator(makeExpressionEvaluator(), new StructuredRuleLowering);
}

function makeSemanticValidator(): SemanticValidator
{
    $engine = makeExpressionEvaluator();

    return new SemanticValidator($engine, new StructuredRuleEvaluator($engine, new StructuredRuleLowering));
}

/**
 * An in-memory (unsaved) form_field_validation row.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeValidationRow(array $attributes): FormFieldValidation
{
    $row = new FormFieldValidation;
    foreach ($attributes as $key => $value) {
        $row->{$key} = $value;
    }

    return $row;
}

/**
 * An in-memory (unsaved) form_field row (defaults: optional short-text).
 *
 * @param  array<string, mixed>  $attributes
 */
function makeSchemaField(array $attributes): FormField
{
    $field = new FormField;
    $field->field_type = FieldType::ShortText;
    $field->is_required = RequiredMode::Optional;
    foreach ($attributes as $key => $value) {
        $field->{$key} = $value;
    }

    return $field;
}

/**
 * An in-memory (unsaved) form_section row.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeSchemaSection(array $attributes): FormSection
{
    $section = new FormSection;
    foreach ($attributes as $key => $value) {
        $section->{$key} = $value;
    }

    return $section;
}

/*
|--------------------------------------------------------------------------
| Shared submissions-inbox test helpers (Increment F7). Require enterTenant already called.
|--------------------------------------------------------------------------
*/

/**
 * A tenant reachable at {slug}.meridian.test — its `domains` row holds the SUBDOMAIN LABEL ({$slug}),
 * which is what tenant identification looks up on its subdomain arm (any host ending in a
 * `tenancy.central_domains` entry).
 *
 * H22a swapped the identification middleware on the guest runtime, NOT this shape: the label row is
 * still the identity of every subdomain tenant, which is why the ~60 call sites across the suite are
 * unchanged. Rewriting them to FQDNs would be actively wrong — `acme.meridian.test` takes the SUBDOMAIN
 * arm and is looked up as `acme` — and it would be silently right on a box where CENTRAL_DOMAIN is
 * something else, which is the worst shape a fixture can have.
 */
function inboxTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);

    return $tenant;
}

/**
 * A tenant brand logo attachment (H23a2), owned by the TENANT rather than by a form field.
 *
 * Lives here and not in a test file on the H22a lesson: `apiMember()` once sat as a top-level function
 * inside `ApiV1Test.php`, so a single-file run of any OTHER test in that suite died with "Call to
 * undefined function". `tests/Pest.php` is what that header exists for.
 */
function brandingLogoAttachment(string $tenantId): Attachment
{
    return Attachment::factory()->brandingLogo($tenantId)->create();
}

/**
 * Add the SECOND kind of `domains` row H22a introduces — a custom domain, taken by the full-host arm.
 *
 * Opt-in and never part of inboxTenant(), deliberately: a tenant with two rows makes every "which host
 * is this tenant's" question answerable two ways, so only a test that actually exercises the custom
 * host should create one.
 *
 * `$host` MUST NOT end with a central domain. Identification classifies the host BEFORE any database
 * read, so `forms.meridian.test` would be routed to the subdomain arm and looked up as the label
 * `forms`, never reaching this row — and App\Rules\ClaimableDomain refuses such a hostname for exactly
 * that reason. Use a genuinely third-party name such as `forms.acme-example.com`.
 *
 * States, matching App\Models\Domain: both null = pending; verified only = control proven but no
 * certificate installed, so it still routes nowhere; both set = live.
 */
/**
 * Bind a recording, in-memory DNS resolver for the custom-domain tests (H22a).
 *
 * `$records` maps a challenge NAME to the TXT strings published there; an unlisted name resolves to
 * NOTHING, which the verification service must treat as "not verified yet" and never as an error. Set
 * `->failing = true` on the returned object for the SERVFAIL/timeout case, which is a different thing and
 * must not consume a claim's TTL.
 *
 * @param  array<string, list<string>>  $records
 */
function fakeDns(array $records = []): FakeDnsTxtResolver
{
    $fake = new FakeDnsTxtResolver($records);
    app()->instance(DnsTxtResolver::class, $fake);

    return $fake;
}

function customDomain(Tenant $tenant, string $host, bool $verified = true, bool $activated = true): Domain
{
    /** @var Domain $domain */
    $domain = $tenant->domains()->create([
        'domain' => $host,
        'verification_token' => bin2hex(random_bytes(32)),
        'token_issued_at' => now(),
        'verified_at' => $verified ? now() : null,
        'activated_at' => $activated ? now() : null,
    ]);

    return $domain;
}

/**
 * A published form with a representative field mix (required text + single/multi choice + yes/no), so the
 * inbox detail + export exercise option-label resolution and array joining. The creator becomes an editor
 * collaborator (FormService::create). Returns the refreshed form (current_published_version_id set).
 */
function publishedInboxForm(Tenant $tenant, User $owner, string $title = 'Intake'): Form
{
    $form = app(FormService::class)->create($tenant, $owner, $title);
    $version = $form->draftVersion;
    addFormField($version, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    addFormField($version, $owner, 'color', FieldType::SingleSelect, 1, ['config' => ['options' => [
        ['value' => 'r', 'label' => 'Red'], ['value' => 'b', 'label' => 'Blue'], ['value' => 'g', 'label' => 'Green'],
    ]]]);
    addFormField($version, $owner, 'hobbies', FieldType::MultiSelect, 2, ['config' => ['options' => [
        ['value' => 'read', 'label' => 'Reading'], ['value' => 'run', 'label' => 'Running'],
    ]]]);
    addFormField($version, $owner, 'subscribe', FieldType::YesNo, 3);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

/**
 * A published single-required-field form with an optional schedule applied (Increment H12a). `$schedule` is a
 * partial `forms` column map (opens_at / closes_at / timezone / max_responses / schedule_state) written
 * straight onto the form row. Returns the current published FormVersion (build a SubmissionPayload from it);
 * read the form via Form::find($version->form_id) when you need its schedule columns. Requires enterTenant.
 *
 * @param  array<string, mixed>  $schedule
 */
function scheduledForm(Tenant $tenant, User $user, array $schedule = [], string $title = 'Scheduled'): FormVersion
{
    $form = app(FormService::class)->create($tenant, $user, $title);
    addFormField($form->draftVersion, $user, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    app(PublishService::class)->publish($form->refresh(), $user);
    $form = $form->refresh();

    if ($schedule !== []) {
        $form->forceFill($schedule)->save();
    }

    return FormVersion::findOrFail($form->current_published_version_id);
}

/**
 * Persist a submission + its answer document against a form's current published version.
 *
 * @param  array<string, mixed>  $answers
 */
function seedInboxSubmission(Form $form, ?User $respondent, SubmissionStatus $status, array $answers, SubmissionSource $source = SubmissionSource::Manual): Submission
{
    $version = FormVersion::findOrFail($form->current_published_version_id);
    $submission = Submission::factory()->forVersion($version)->create([
        'status' => $status,
        'source' => $source,
        'respondent_user_id' => $source === SubmissionSource::Guest ? null : $respondent?->id,
        'submitted_at' => now(),
    ]);
    SubmissionAnswer::factory()->forSubmission($submission)->create(['answers' => $answers]);

    return $submission;
}

/**
 * Grant a user editor/reviewer capacity on one form directly (`.own`-scoped access).
 *
 * Keeps its pre-G10a name and arity deliberately: its call sites across the suite are the regression
 * gate proving the `resource_grants` rewiring is behaviour-identical for the direct-form case, and moving
 * them would weaken that proof.
 */
function makeCollaborator(Form $form, User $user, ResourceCapacity $capacity): void
{
    $grant = new ResourceGrant(['user_id' => $user->id, 'capacity' => $capacity]);
    $grant->scopeable()->associate($form);
    $grant->save();

    app(ResourceGrantResolver::class)->forget($user->id);
}

/** Create a node in the tenant's scoping hierarchy, via the only writer of path/depth (G10a). */
function makeScopeNode(?ScopeNode $parent = null, string $name = 'Region', array $attributes = []): ScopeNode
{
    return app(ScopeNodeService::class)->create($parent, $name, $attributes);
}

/**
 * Grant a user capacity on a hierarchy NODE rather than a form. `$descendants` decides whether it covers
 * only forms assigned to this node or the whole subtree beneath it.
 */
function grantOnNode(
    ScopeNode $node,
    User $user,
    ResourceCapacity $capacity,
    bool $descendants = false,
): ResourceGrant {
    $grant = new ResourceGrant([
        'user_id' => $user->id,
        'capacity' => $capacity,
        'includes_descendants' => $descendants,
    ]);
    $grant->scopeable()->associate($node);
    $grant->save();

    app(ResourceGrantResolver::class)->forget($user->id);

    return $grant;
}

/**
 * Create a form and assign it to a scope node (G10b). Reads `tenant`/`user` off the current test, the
 * same way `unownedForm()` does.
 *
 * The assignment is a raw UPDATE because `forms.scope_node_id` has no service writer until the G10b2
 * picker lands — keeping that fact visible here rather than inventing a test-only writer that would drift
 * from the real one.
 */
function formIn(ScopeNode $node, string $title = 'Survey'): Form
{
    $form = app(FormService::class)->create(test()->tenant, test()->user, $title);
    DB::table('forms')->where('id', $form->id)->update(['scope_node_id' => $node->id]);

    return $form->refresh();
}

/**
 * Drive ONE real job through the `database` queue driver, in-process (ADR-0007 `:112`).
 *
 * This is the only way to exercise serialize → enqueue → reserve → unserialize → context re-entry,
 * which `sync` — every other test in this suite — structurally cannot: under `sync` the job never
 * touches the `jobs` table and never leaves the caller's memory, so §D2's context re-establishment,
 * §D4's listener and §D5's payload rule are all unexercised.
 *
 * IN-PROCESS IS DELIBERATE, not a shortcut. RefreshDatabase wraps every test in an uncommitted
 * transaction, so a separate `php artisan queue:work` process could not see the enqueued row at all.
 * Running the worker inside the test shares the same PDO connection, so it can. (This is also why the
 * suite needs no committing-test precedent — see PROGRESS.md's note on that gap.)
 *
 * Two consequences the caller must respect:
 *   1. The worker SWALLOWS exceptions (Worker::process catches and marks the job failed), so assert
 *      on observable state — the `jobs`/`failed_jobs` tables, a probe's static, a row's column —
 *      never by expecting a throw. Pair with Exceptions::fake()->assertNothingReported() if you want
 *      an in-job assertion failure to surface.
 *   2. After it returns, the tenant statics have been cleared by the §D4 listener. Re-enterTenant()
 *      before any tenant-scoped read, or the read fails closed and returns nothing.
 *
 * Do NOT assert the GUC is null afterwards: applyLocal's is_local scope is the OUTERMOST transaction,
 * which RefreshDatabase never commits.
 *
 * @return int the artisan exit code
 */
function workOneJob(string $queue = 'submissions'): int
{
    return Artisan::call('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--queue' => $queue,
        '--sleep' => 0,
        // Deliberately NO --tries: the worker default would mask each job's own retryUntil()/$tries,
        // and §D7's whole point is that those live on the class. --once returns after one job
        // regardless, so there is no hang to guard against.
    ], new NullOutput);
}

/**
 * A countable submission stamped at a chosen instant, for H24a's bucketed aggregates.
 *
 * {@see seedInboxSubmission()} stamps `submitted_at => now()` for every row, which is adversarially useful
 * (a draft it creates lands inside any range, so a query that forgets the countable predicate fails loudly)
 * but useless for a multi-bucket series: every row would share one bucket. This helper is the time-aware
 * twin, and it deliberately takes the draft columns too, because ADR-0011 §D5's two metrics are derived from
 * `created_at`/`last_saved_at` and cannot be exercised without setting them.
 *
 * `forceFill` on the timestamps: `created_at` is not mass-assignable and Eloquent would otherwise overwrite
 * it with the insert time, which is exactly the column §D5 reads as "first save".
 */
function seedCountableAt(
    Form $form,
    CarbonImmutable|Carbon $submittedAt,
    SubmissionStatus $status = SubmissionStatus::Submitted,
    SubmissionSource $source = SubmissionSource::Guest,
    CarbonImmutable|Carbon|null $createdAt = null,
    CarbonImmutable|Carbon|null $lastSavedAt = null,
    ?string $locale = null,
): Submission {
    $version = FormVersion::findOrFail($form->current_published_version_id);

    $submission = Submission::factory()->forVersion($version)->create([
        'status' => $status,
        'source' => $source,
        'respondent_user_id' => null,
        'submitted_at' => $submittedAt,
        'last_saved_at' => $lastSavedAt,
        'locale' => $locale,
    ]);

    $submission->forceFill([
        'created_at' => $createdAt ?? $submittedAt,
        'submitted_at' => $status === SubmissionStatus::Draft ? null : $submittedAt,
    ])->saveQuietly();

    return $submission->refresh();
}
