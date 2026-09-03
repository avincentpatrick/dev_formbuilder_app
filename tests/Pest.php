<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\IndexedDataType;
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
use App\Models\SsoAuthRequest;
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
use App\Support\Auth\GoogleIdentityProvider;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Sso\SsoSession;
use App\Support\Tenancy\DnsTxtResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Factories\SsoConnectionFactory;
use Database\Seeders\PlanSeeder;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Auth\FakeGoogleIdentityProvider;
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

/**
 * A COMMITTED identity, visible to the separate `pgsql_auth` session (Increment J2d).
 *
 * ⚠️ ANY TEST THAT RENDERS `/members` NEEDS THIS, and the failure without it looks like a product bug rather
 * than a fixture one. `TenantMembershipService::listMembers()` resolves identities on the `pgsql_auth`
 * connection and indexes the result with `$users[$m->user_id]`, its comment noting that "the FK then
 * guarantees the keyed map contains every id". That guarantee is real in production and false inside a test:
 * `pgsql_auth` is a separate SESSION and cannot see `RefreshDatabase`'s uncommitted rows, so a
 * `User::factory()` member has a `tenant_users` row and no visible identity — and the page 500s on an
 * undefined array key.
 *
 * ⚠️ PROMOTED HERE ON THE FOURTH COPY. `MembersIndexTest::committedMemberUser()`,
 * `MembersRosterFilterTest::committedIdentity()` and two J2d reachability suites each needed the identical
 * six lines; the three existing copies stay where they are because each file's docblock makes its local one
 * load-bearing, but nothing new should add a fifth. This file's own header records the rule (a fixture
 * defined inside one test file resolves only when THAT file is loaded, so a single-file run of any other
 * suite dies with "call to undefined function").
 *
 * ⚠️ RANDOM EMAIL, AND NEVER DELETED IN AN `afterEach`. These rows outlive the transaction and are cleaned
 * only by `migrate:fresh`; a fixed address collides on the second run and pollutes every later file, and a
 * DELETE deadlocks against the open locks.
 *
 * ⚠️ `$verified` GAINED A PARAMETER IN J3c2 RATHER THAN A FIFTH COPY OF THE HELPER. Google sign-in links
 * onto an existing account ONLY when that account has already proved it owns its address (ADR-0019 §D4), so
 * the refusal arm needs an UNVERIFIED committed identity — and until now every committed helper here
 * (`committedSuperAdmin`, `committedPlainUser`, this one) hard-set the column. Defaulting to `true` means no
 * existing caller moves. ⚠️ Pest helpers are GLOBAL and a duplicate name passes every per-file run while
 * fatalling only on the full suite, which is why this is a parameter and not a `committedUnverifiedIdentity`.
 *
 * @param  bool  $verified  false produces an account that exists and has NOT confirmed its address — the
 *                          §D4 refusal case, and the only shape in which linking would be a takeover.
 */
function committedTenantIdentity(string $name = 'Committed Member', bool $verified = true, ?string $email = null): User
{
    /** @var User $user */
    $user = User::on('pgsql_privileged')->forceCreate([
        'name' => $name,
        'email' => $email ?? Str::lower(Str::random(12)).'@identity.test',
        'password' => Hash::make('secret-password-123'),
        // J3a — `routes/tenant.php`'s authenticated group carries `verified`, so an identity handed to
        // `actingAs()` without this is bounced to `/email/verify` and every assertion about the page under
        // test reads as a product failure. `UserFactory` already defaults it; the hand-rolled committed
        // identities did not, because before J3a nothing consumed the column.
        'email_verified_at' => $verified ? now() : null,
    ]);
    $user->setConnection((string) config('database.default'));

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
 * The per-form statistics URL (Increment I10c).
 *
 * HERE rather than in a test file, and for the reason the docblock immediately below records about
 * `apiMember()`: FormAnalyticsPageTest and FormAnalyticsGateTest both need it, and a top-level function in
 * one of them dies with "Call to undefined function" on a single-file run of the OTHER. It cost one run to
 * rediscover that.
 *
 * ⚠️ Pest helpers are GLOBAL: `gateUrl`, `analyticsPageUrl`, `analyticsRange`, `analyticsUrl`,
 * `trendFixture` and `analyticsIndexDef` are already taken in this tree, and a duplicate name passes every
 * per-file run and fatals only on the full suite.
 */
function formAnalyticsUrl(string $formId, string $slug = 'acme'): string
{
    return 'http://'.$slug.'.meridian.test/forms/'.$formId.'/analytics';
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

/**
 * Bind a recording, in-memory Google for the sign-in tests (J3c2).
 *
 * Live Google credentials are an input only the product owner can supply, so this is what every case
 * downstream of the seam runs against — the `fakeDns()` shape, for the same reason. The returned object
 * RECORDS: `->authorizeCalls` proves which state crossed the hop and with which redirect URI, and
 * `->exchangeCalls` proves a replayed callback never reached Google a second time. Neither is visible
 * from the resulting session.
 *
 * Knobs for the refusals, which is what makes it worth having: `->unverifiedEmail()` (this flow's analogue
 * of an invalid signature), `->refusingExchange()`, and `->as($sub, $email)` to drive linkage and the
 * subject-mismatch takeover case.
 */
function fakeGoogle(): FakeGoogleIdentityProvider
{
    $fake = new FakeGoogleIdentityProvider;
    app()->instance(GoogleIdentityProvider::class, $fake);

    return $fake;
}

/**
 * Stub Have I Been Pwned's k-anonymity range endpoint.
 *
 * ⚠️ ANY TEST THAT VALIDATES A NEW PASSWORD REACHES THE PUBLIC INTERNET WITHOUT THIS, AND STAYS GREEN WHILE
 * IT DOES — WHICH IS WHY IT WENT UNNOTICED FOR TWO INCREMENTS. `Password::uncompromised()` is a shipped
 * default (`FortifyServiceProvider::boot()`) and `NotPwnedVerifier` performs the lookup through the `Http`
 * facade, so a merge-blocking gate silently depended on api.pwnedpasswords.com being reachable from the
 * runner. It never announced itself because the verifier CATCHES the failure, `report()`s it and returns an
 * empty collection — i.e. it FAILS OPEN to "uncompromised". The suite therefore passes with or without
 * egress, and the only difference is whether CI is quietly making an outbound HTTPS request per registration
 * test.
 *
 * I8a fixed this in `AuthenticationTest` alone, as a file-local function. J3a found two more files doing it
 * (`Feature/Settings/OpenTenantRegistrationTest`, `Feature/Tenancy/MembershipRoutesTest`) and promoted the
 * helper HERE — which is also the only place it can live, because Pest loads every test file into one
 * process and a second top-level `fakeHibp` would be a fatal.
 *
 * The stub answers with a real `SUFFIX:COUNT` line for each nominated password whose hash prefix matches the
 * request, and an EMPTY body otherwise, which the verifier reads as "no match", i.e. uncompromised. Suffixes
 * are DERIVED with `sha1()` rather than pasted as constants, so the fixture cannot drift from what the
 * validator actually asks for. Note the array form: only this one host is stubbed, so an unrelated stray
 * request stays visible rather than being absorbed by a catch-all.
 *
 * @param  list<string>  $breachedPasswords
 */
function fakeHibp(array $breachedPasswords = []): void
{
    Http::fake([
        'api.pwnedpasswords.com/range/*' => function (HttpClientRequest $request) use ($breachedPasswords) {
            $lines = [];

            foreach ($breachedPasswords as $candidate) {
                $hash = strtoupper(sha1($candidate));

                if (str_ends_with($request->url(), substr($hash, 0, 5))) {
                    $lines[] = substr($hash, 5).':9659365';
                }
            }

            return Http::response(implode("\r\n", $lines));
        },
    ]);
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

/*
|--------------------------------------------------------------------------
| Committed cross-connection fixtures (Increment I7a)
|--------------------------------------------------------------------------
| The elevated `pgsql_superadmin` connection cannot see rows written inside
| RefreshDatabase's uncommitted transaction, so any test of a cross-tenant
| console read must seed COMMITTED rows on `pgsql_privileged`.
|
| `SuperAdminBypassTest` (B2c) established that idiom and deliberately did NOT
| clean up, on the reasoning that a privileged DELETE would deadlock against a
| test's own open transaction. **That reasoning held only because it committed
| `users` and nothing else.** I7a committed TENANTS, and tenants are global
| state that other suites legitimately assert over: `DemoSeederIdempotencyTest`
| pins the exact slug list, and the three sweep-command tests iterate every
| tenant. Nine unrelated tests went red in CI on a locally-green tree — the
| textbook shape of a convenience fixture coupling itself to a merge-blocking
| gate, which the I6 notes warn about in as many words.
|
| So the rows ARE cleaned up, and the deadlock objection is answered by WHEN
| rather than by giving up: {@see purgeCommittedFeedbackFixtures()} is meant to
| be registered through `$this->beforeApplicationDestroyed(...)` from a test's
| `beforeEach`. RefreshDatabase registers its rollback the same way during
| `setUp` — i.e. EARLIER — and Laravel runs those callbacks in registration
| order, so this one executes after the test transaction is already gone and
| there are no locks left to block on.
*/

/** Delete every committed I7a fixture row, in FK-safe order, on the privileged connection. */
function purgeCommittedFeedbackFixtures(): void
{
    $connection = DB::connection('pgsql_privileged');

    // Markers, not ids: a test that dies mid-way still gets cleaned by the next one.
    $tenantIds = $connection->table('tenants')
        ->where('slug', 'like', 'console-%')
        ->orWhere('slug', 'like', 'rls-%')
        ->pluck('id')
        ->all();

    $userIds = $connection->table('users')
        ->where('email', 'like', '%@feedbackconsoletest.local')
        ->orWhere('email', 'like', '%@feedbackrlstest.local')
        ->pluck('id')
        ->all();

    if ($tenantIds !== []) {
        $connection->table('feedback_reports')->whereIn('tenant_id', $tenantIds)->delete();
    }

    if ($userIds !== []) {
        // Any report authored by a fixture user in a tenant that is NOT ours (there should be none —
        // belt and braces, since `users.id` is an FK target and the delete below would otherwise fail).
        $connection->table('feedback_reports')->whereIn('user_id', $userIds)->delete();
        $connection->table('feedback_reports')->whereIn('resolved_by', $userIds)->update(['resolved_by' => null]);
    }

    if ($tenantIds !== []) {
        $connection->table('tenants')->whereIn('id', $tenantIds)->delete();
    }

    if ($userIds !== []) {
        $connection->table('users')->whereIn('id', $userIds)->delete();
    }
}

/*
|--------------------------------------------------------------------------
| The super-admin console's own surface (Increment M35)
|--------------------------------------------------------------------------
| `AdminConsoleGateTest` asserts four gates over this set and
| `StepUpReauthenticationTest` asserts `step-up` over it, so it lives here
| rather than in either: Pest loads every test file into ONE process, and a
| helper declared in a test file resolves only when THAT file has been
| loaded — the failure this file's header already records paying for twice.
|
| Keyed on the URI prefix rather than the route-NAME prefix, deliberately. A
| name is a label an author picks, so a console route named anything else
| escapes a name-keyed filter; a route that answers under `admin/` IS a page
| of the console whatever it is called. Both callers assert a floor on the
| count, because a filter that silently stopped matching would leave every
| assertion over it passing on an empty set.
*/

/** @return list<RoutingRoute> every route the central-host console serves. */
function adminConsoleRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static fn (RoutingRoute $route): bool => $route->uri() === 'admin'
            || str_starts_with($route->uri(), 'admin/'),
    ));
}

/*
|--------------------------------------------------------------------------
| Policy gates, PARSED rather than merely counted (Increment M63)
|--------------------------------------------------------------------------
| `GroupBPolicyGateTest` has asked "does this route carry a `can:` gate?"
| since M13, and answered it by resolving the middleware alias and throwing
| the rest away: `explode(':', $middleware, 2)[0]`. Element [1] — the whole
| `<ability>,<Subject>` payload — was computed and discarded, so a gate
| naming the WRONG subject satisfied every assertion in the repository.
|
| ⛔ THE PAYLOAD IS WHERE THE AUTHORIZATION DECISION ACTUALLY LIVES.
| `routes/api.php` gates six analytics routes on `viewAny,SavedReportView`,
| whose policy reads the two dashboard keys. Swap the subject for
| `App\Models\Submission`, whose `viewAny` reads `submissions.view`, and the
| route answers to a different audience entirely — with no test in the
| repository able to tell, because no principal any test used could
| distinguish two gates that both refused it.
|
| These live here rather than in either consumer because Pest loads every
| test file into ONE process, so a helper declared in a test file resolves
| only when THAT file has been loaded — the failure this file's header
| already records paying for twice. `groupBRoutes()` MOVED here from
| `GroupBPolicyGateTest.php` for exactly that reason: M63 gave it a second
| caller.
|
| ⚠️ THE ALIAS INDIRECTION IS NOT DEFENSIVE, AND REMOVING IT IS THE FIRST
| THING THAT WENT WRONG WRITING THE ORIGINAL. `route:list` PRINTS the
| resolved class (`Illuminate\Auth\Middleware\Authorize:view,form`) while
| `gatherMiddleware()` RETURNS the declared alias (`can:view,form`) — a
| check written against what the command printed found ZERO gated routes and
| reported all fifty as ungated.
|
| Returns a LIST, not a single gate: two routes in `routes/tenant.php` carry
| two `can:` middlewares at once, and a helper that answered with one of them
| would silently pick a winner.
*/

/**
 * Every policy gate declared on a route, parsed into its ability and its subjects.
 *
 * Handles all five shapes that occur in this repository:
 *   `can:viewAny,App\Models\Form`        class subject, no bound instance
 *   `can:view,form`                      a bound route parameter
 *   `can:tenant.settings.manage`         a bare Gate/Spatie ability, NO subject at all
 *   `can:create,App\Models\Submission,form`   class subject plus a second bound argument
 *   two `can:` entries on one route      two list elements
 *
 * @return list<array{ability: string, subjects: list<string>, declared: string}>
 */
function policyGates(RoutingRoute $route): array
{
    $aliases = app('router')->getMiddleware();
    $gates = [];

    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue; // a closure or an instance — never a `can:` gate
        }

        [$name, $payload] = array_pad(explode(':', $middleware, 2), 2, null);

        if (($aliases[$name] ?? $name) !== Authorize::class) {
            continue;
        }

        // A gate with no payload at all is malformed rather than absent, and is reported as such:
        // `$ability` comes back empty and the structural case names the route.
        $parts = array_map(trim(...), explode(',', (string) $payload));
        $ability = (string) array_shift($parts);

        $gates[] = ['ability' => $ability, 'subjects' => array_values($parts), 'declared' => $middleware];
    }

    return $gates;
}

/**
 * Group B — the token-consumed resource surface.
 *
 * Keyed on the route-NAME prefix, which is what delimits the group: Group A is `api.v1.tokens.*`
 * (session-authenticated, mints rather than reads) and Group C is `api.v1.public.*` (the unauthenticated
 * guest runtime, whose authorization is a signed share token and which has no `User` to ask a policy
 * about). Every caller asserts a floor on the count, because a filter that silently stopped matching
 * would leave every assertion over it passing on an empty set.
 *
 * @return list<RoutingRoute>
 */
function groupBRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static function (RoutingRoute $route): bool {
            $name = (string) $route->getName();

            return str_starts_with($name, 'api.v1.')
                && ! str_starts_with($name, 'api.v1.public.')
                && ! str_starts_with($name, 'api.v1.tokens.');
        },
    ));
}

/**
 * An active member holding EXACTLY the named permissions and nothing else.
 *
 * ⛔ THE SEEDED FIVE-ROLE MATRIX CANNOT PRODUCE THIS, AND THAT IS THE WHOLE REASON THE HELPER EXISTS.
 * Measured against `RolePermissionSeeder::MATRIX`: all five roles that hold `submissions.view` — owner,
 * admin, form_editor, reviewer, viewer — also hold at least `dashboard.form.view`. So no seeded principal
 * can distinguish a gate reading `submissions.view` from one reading the dashboard pair, which is exactly
 * the discrimination M63 needed and exactly why the defect survived.
 *
 * ⚠️ A DIRECT PERMISSION, NEVER A SYNTHETIC ROLE, AND THE REASON IS A BUG THIS SUITE ALREADY PAID FOR.
 * `FormVisibilityScopeTest`'s first draft minted a global role on `pgsql_privileged` — OUTSIDE
 * `RefreshDatabase`'s transaction — so the row COMMITTED and leaked into every later test in the process;
 * `RbacRlsTest` counts the global catalog and went red with "6 is not 5" a hundred files later with
 * nothing pointing back. `makeFormsCreatorOnly()` there is this helper's direct precedent.
 *
 * Order is load-bearing at every step: `apiMember()` sets the RLS GUC and Spatie's team id and creates the
 * `tenant_users` row the tenant middleware requires (without it the refusal arrives from the wrong layer);
 * `syncRoles([])` runs BEFORE the grant, because `viewer` carries both dashboard keys and would destroy
 * the discrimination; the cache flush runs AFTER the grant and before any `can()`.
 *
 * ⚠️ Leaves the new user as the acting principal, exactly as `apiMember()` does. A caller that writes
 * further fixtures must re-enter as its own actor first.
 */
function memberHoldingOnly(string ...$permissions): User
{
    $user = apiMember('viewer');
    $user->syncRoles([]);

    if ($permissions !== []) {
        $user->givePermissionTo(...$permissions);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/*
|--------------------------------------------------------------------------
| Committed platform fixtures (Increment I7b)
|--------------------------------------------------------------------------
| Same harness constraint as the I7a block above — the elevated
| `pgsql_superadmin` connection cannot see RefreshDatabase's uncommitted
| transaction — applied to the PLATFORM slice: `audits` rows with
| `tenant_id IS NULL`, and the super-admins who authored them.
|
| `clearPlatformRows()` and `committedSuperAdmin()` were file-scope functions in
| `PlatformSettingsWriteTest.php` until I7b. They live here now because Pest
| loads every test file into ONE process, so a second declaration in a second
| file is a fatal redeclare — and relying on load order to reuse them across
| files is fragile in a way that fails at collection time, not at assert time.
*/

/** Every NULL-tenant row, gone. Safe on the privileged connection: the default connection's open test
 *  transaction never touches platform rows. */
function clearPlatformRows(): void
{
    DB::connection('pgsql_privileged')->table('settings')->whereNull('tenant_id')->delete();
    DB::connection('pgsql_privileged')->table('audits')->whereNull('tenant_id')->delete();
}

/**
 * A COMMITTED super-admin, visible from the separate `pgsql_superadmin` connection.
 *
 * `User::factory()->create()` writes inside RefreshDatabase's uncommitted transaction on the DEFAULT
 * connection, so an elevated read cannot see it — and `settings.updated_by` is a real FK to `users`, which
 * turns that invisibility into a 23503 rather than into a null.
 */
function committedSuperAdmin(string $email, string $name = 'Platform Operator'): User
{
    /** @var User $user */
    $user = User::on('pgsql_privileged')->forceCreate([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make(Str::random(40)),
        'email_verified_at' => now(),
        'is_super_admin' => true,
    ]);

    return $user;
}

/** A COMMITTED ordinary (non-super-admin) user, for proving the actor catalog excludes them. */
function committedPlainUser(string $email, string $name = 'Ordinary User'): User
{
    /** @var User $user */
    $user = User::on('pgsql_privileged')->forceCreate([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make(Str::random(40)),
        'email_verified_at' => now(),
        'is_super_admin' => false,
    ]);

    return $user;
}

/**
 * A COMMITTED tenant row, returned hydrated.
 *
 * ⚠️ `Tenant::on('pgsql_privileged')` DOES NOT WORK and fails silently — stancl's `CentralConnection`
 * trait makes `getConnectionName()` return the central connection unconditionally, discarding the
 * override, so the row lands inside the test transaction and the failure surfaces later and elsewhere as
 * an FK violation. The raw query builder is the only way to commit one.
 *
 * ⚠️ THE SLUG PREFIX IS ENFORCED, AND THE ENFORCEMENT IS THE POINT. `platform-audit-` is the marker
 * {@see purgeCommittedPlatformAuditFixtures()} cleans by, so a slug outside it mints a tenant that NOTHING
 * deletes: RefreshDatabase's rollback cannot reach a committed row, and `migrate:fresh` runs once per
 * process. I11a proved the cost — one fixture named `impersonated-slice` survived into every later file and
 * took twelve tests down in seven suites that had nothing to do with it. The two shapes it breaks are the
 * ones the I7a block above already names: suites that pin the exact `tenants.slug` list
 * (`DemoSeederIdempotencyTest`, `DatabaseSeederSmokeTest`) and the sweep-command suites, which drain a FIXED
 * number of queued children on the documented assumption that their own tenant is the only active one — a
 * second tenant means the drain consumes the STRANGER's job and the test's own tenant is never swept, which
 * reads as "the sweep did nothing" hundreds of files from the actual cause.
 *
 * Throwing here turns that into an immediate failure at the call site. It is the only guard available: the
 * purge cannot clean what it cannot recognise.
 */
function committedPlatformTenant(string $slug, string $name = 'Platform Fixture'): Tenant
{
    if (! str_starts_with($slug, 'platform-audit-')) {
        throw new InvalidArgumentException(
            "committedPlatformTenant() slug must begin with 'platform-audit-', got '{$slug}'. That prefix is "
            .'the marker purgeCommittedPlatformAuditFixtures() deletes by; a tenant outside it survives '
            .'RefreshDatabase for the rest of the process and reddens unrelated sweep and seeder suites.'
        );
    }

    $id = Uuid::uuid7()->toString();

    DB::connection('pgsql_privileged')->table('tenants')->insert([
        'id' => $id,
        'name' => $name,
        'slug' => $slug,
        'status' => 'active',
        'default_locale' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->findOrFail($id);

    return $tenant;
}

/**
 * A COMMITTED audit row. Pass `$tenantId = null` for a PLATFORM row (the slice `/admin/audit-log` reads),
 * or a tenant id for the row that must NEVER appear there.
 *
 * @param  array<string, mixed>|null  $new
 */
function committedAudit(
    ?string $tenantId,
    ?string $actorId,
    string $auditableType = 'settings',
    string $event = 'updated',
    ?array $new = null,
    ?string $auditableId = null,
    // I11a — the real operator behind an impersonated action. Last and optional, so every existing caller
    // keeps writing the ordinary shape (this column NULL) without being touched.
    ?string $actingAsUserId = null,
): string {
    $id = Uuid::uuid7()->toString();

    DB::connection('pgsql_privileged')->table('audits')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'user_id' => $actorId,
        'acting_as_user_id' => $actingAsUserId,
        'event' => $event,
        'auditable_type' => $auditableType,
        'auditable_id' => $auditableId ?? $actorId ?? Uuid::uuid7()->toString(),
        'old_values' => null,
        'new_values' => $new === null ? null : json_encode($new),
        'redacted_fields' => null,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'pest',
        'is_system_action' => false,
        'created_at' => now(),
    ]);

    return $id;
}

/**
 * Delete every committed I7b fixture, IN THIS ORDER. **The order is load-bearing.**
 *
 * ⚠️ `audits.tenant_id` is `nullOnDelete` (create_audits_table.php). Deleting a fixture TENANT before its
 * audit rows does not delete those rows — it sets their `tenant_id` to NULL, **PROMOTING a tenant-scoped
 * fixture into a permanent PLATFORM row** that the very viewer under test would then display, in this run
 * and every run afterwards. Audits first, then tenants, then users.
 *
 * Registered via `$this->beforeApplicationDestroyed(...)` from a `beforeEach`, the I7a device: Laravel runs
 * those callbacks in registration order, and RefreshDatabase registers its rollback earlier during setUp,
 * so this executes once the test transaction is gone and there is nothing left to deadlock against.
 */
function purgeCommittedPlatformAuditFixtures(): void
{
    $connection = DB::connection('pgsql_privileged');

    // Markers, not ids: a test that dies mid-way is still cleaned up by the next one. ⚠️ The tenant marker
    // is a CONTRACT, not a convention — {@see committedPlatformTenant()} throws on a slug outside it,
    // because a fixture this predicate does not match is a fixture nothing ever deletes.
    $tenantIds = $connection->table('tenants')->where('slug', 'like', 'platform-audit-%')->pluck('id')->all();
    $userIds = $connection->table('users')->where('email', 'like', '%@platformaudittest.local')->pluck('id')->all();

    if ($tenantIds !== []) {
        $connection->table('audits')->whereIn('tenant_id', $tenantIds)->delete();
    }

    if ($userIds !== []) {
        $connection->table('audits')->whereIn('user_id', $userIds)->delete();
    }

    // Whatever this suite left in the platform slice, including rows authored by a system actor.
    $connection->table('audits')->whereNull('tenant_id')->where('user_agent', 'pest')->delete();

    if ($tenantIds !== []) {
        $connection->table('tenants')->whereIn('id', $tenantIds)->delete();
    }

    if ($userIds !== []) {
        $connection->table('users')->whereIn('id', $userIds)->delete();
    }
}

/*
|--------------------------------------------------------------------------
| Step-up re-authentication (Increment I8a) — PRD Feature #14.
|--------------------------------------------------------------------------
| `App\Http\Middleware\RequireRecentPassword` (aliased `step-up`) guards the tenant-side
| high-blast-radius mutations and EVERY page of the super-admin console. A live session is no
| longer enough there: the session must also carry a password confirmation from the last
| `auth.step_up_timeout` seconds.
|
| ⚠️ EVERY CONSOLE TEST WRITTEN BEFORE I8a NEEDS THIS, AND THE FAILURE IS NOT SUBTLE — the request
| 302s to `/user/confirm-password` instead of rendering, so an `assertOk()` reports "expected 200,
| got 302" and an `assertRedirect(...)` reports the wrong target. 43 pre-existing tests across four
| console suites hit it the first time step-up landed. That is the cost of the gate rather than a
| defect in it, and it is paid here once instead of forty-three times.
|
| Lives in this file for the reason stated at the top of it: Pest loads every test file into ONE
| process, so the same helper declared in two suites is a fatal, not a duplicate.
*/

/**
 * Mark the current test session's password as confirmed `$secondsAgo` seconds ago.
 *
 * Writes exactly the key Fortify's own `ConfirmablePasswordController` writes on success, so a test
 * that calls this is indistinguishable — to the middleware — from one that walked the real
 * confirm-password flow. Pass a value larger than `auth.step_up_timeout` (900) to assert the
 * REFUSAL side; the default of 0 asserts the allowed side.
 */
function confirmPasswordNow(int $secondsAgo = 0): void
{
    session()->put('auth.password_confirmed_at', now()->unix() - $secondsAgo);
}

/*
|--------------------------------------------------------------------------
| Guest-runtime fixtures (Increment F5, moved here in I8b).
|--------------------------------------------------------------------------
| These four lived as top-level functions in tests/Feature/Guest/GuestRuntimeTest.php, which meant they
| resolved only when THAT file happened to be loaded into the process — so a single-file run of any other
| guest suite died with "Call to undefined function guestTenant()". That is precisely the failure H22a
| already paid for with apiMember(), and this file's header exists to prevent. Moved on the second
| occurrence rather than the third.
|
| GuestRuntimeTest's call sites are unchanged, which keeps it the regression gate for these fixtures.
*/

/** A tenant reachable at {slug}.meridian.test. */
function guestTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);

    return $tenant;
}

/** A published form (required full_name + optional age). Requires enterTenant already called. */
function publishedGuestForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    addFormField($form->draftVersion, $owner, 'age', FieldType::Integer, 1, [
        'is_queryable' => true,
        'indexed_data_type' => IndexedDataType::Number, // so the pipeline projects a typed index row for `age`
    ]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

/** The same, with guest access enabled at a public slug. */
function guestForm(Tenant $tenant, User $owner, string $slug = 'intake'): Form
{
    $form = publishedGuestForm($tenant, $owner);
    $form->update(['public_slug' => $slug, 'allow_guest_submissions' => true]);

    return $form->refresh();
}

/** Mint a share token for a form's current published version (optionally at a forged clock, for expiry tests). */
function shareTokenFor(Form $form, ?int $now = null): string
{
    return app(GuestShareTokenService::class)->mint(
        $form->tenant_id,
        $form->id,
        (string) $form->current_published_version_id,
        $now,
    )->token;
}

/*
|--------------------------------------------------------------------------
| SSO/SAML fixtures (P1a).
|--------------------------------------------------------------------------
| Here rather than in a test file for the reason this file's header already records: a top-level function
| declared inside a spec resolves only when THAT spec happens to be loaded into the process, so a
| single-file run of any other SSO suite dies with "Call to undefined function". Three suites use this one.
*/

/**
 * A minimal, valid identity-provider metadata document.
 *
 * `$certificate` defaults to `SsoConnectionFactory::certificate()`, which is a real self-signed key
 * memoized per process — generated rather than hard-coded, because a fixed base64 blob would expire and
 * turn every green test red on some future date with no code change to blame.
 */
function idpMetadataXml(?string $certificate = null, string $entityId = 'https://idp.example.com/saml2'): string
{
    $certificate ??= SsoConnectionFactory::certificate();

    return <<<XML
    <?xml version="1.0"?>
    <EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$entityId}">
      <IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
        <KeyDescriptor use="signing">
          <KeyInfo xmlns="http://www.w3.org/2000/09/xmldsig#">
            <X509Data><X509Certificate>{$certificate}</X509Certificate></X509Data>
          </KeyInfo>
        </KeyDescriptor>
        <NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</NameIDFormat>
        <SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.example.com/saml2/sso"/>
      </IDPSSODescriptor>
    </EntityDescriptor>
    XML;
}

/**
 * Drive a SAML login's LAST TWO legs: post the assertion, then follow the same-site hop the ACS hands back.
 *
 * ⚠️ TWO REQUESTS, BECAUSE SINCE P1e THE FLOW IS TWO REQUESTS. The ACS is a cross-site POST that
 * `SameSite=Lax` gives no cookie, so it can neither read the browser that started the flow nor create a
 * session anyone should trust; it marks the row and hands back. Absorbed here rather than written out at
 * seventeen call sites, so the SHAPE of the round trip lives in one place and moves in one place.
 *
 * ⚠️ THE HAND-OFF LOCATION IS ASSERTED, NOT MERELY FOLLOWED, which is why this is a helper and never a
 * `followRedirects()`. Every caller pins the ACS's `Location` for free, so a hop that started pointing
 * somewhere else would redden all of them rather than none of them.
 *
 * ⚠️ AND IT IS NOT USED EVERYWHERE ON PURPOSE. The cases whose SUBJECT is the two-hop shape — the happy
 * path, the browser binding, the 2FA hand-over — write both requests out longhand, because a flow whose only
 * description is a helper is a flow nobody reads.
 */
function completeSamlLogin(SsoAuthRequest $request, string $samlResponse, string $host = 'http://acme.meridian.test'): TestResponse
{
    // ⚠️ THE PRECONDITION IS ASSERTED RATHER THAN ASSUMED, AND THE FAILURE MODE IT GUARDS IS ASYMMETRIC.
    // This helper does not establish the browser binding — the real mint does, and every caller reaches it
    // through one. A future case that fabricated a row, or called `SsoAuthRequestService::mint()` directly
    // (which touches no session), and then asserted a REFUSAL would pass while measuring "no binding"
    // rather than the condition it names. That is the "green for a different reason than its name claims"
    // shape, and one expectation converts it into a failure nobody can misread.
    expect(SsoSession::hasPendingLogin(session()->driver(), $request->request_id))
        ->toBeTrue('completeSamlLogin() requires the binding the real mint writes — drive GET /sso/saml/login');

    $handOff = test()->post($host.'/sso/saml/acs', ['SAMLResponse' => $samlResponse])
        ->assertRedirect($host.'/sso/saml/login/complete/'.$request->request_id);

    return test()->get((string) $handOff->headers->get('Location'));
}

/*
|--------------------------------------------------------------------------
| Increment M12 — staging a write inside promote()'s PRE-LOCK window
|--------------------------------------------------------------------------
| `SubmissionDraftService::promote()` reads the answer document, runs Stage 3 and the DB media check over it,
| and only then takes the row lock. A write that commits inside that window is what the M12 guard refuses.
| Three spec files need to stage it — the service, the encode channel and the guest channel — so it lives
| here for the reason this file's header already states: one definition, usable from a single-file run.
*/

/**
 * Run `$write` exactly once, at the moment `promote()` reads the draft's answer document — i.e. inside the
 * window between that read and the row lock. Returns a predicate the caller asserts on for NON-VACUITY: a
 * case whose interleave never fired proves nothing, and every assertion in it would pass for the wrong
 * reason.
 *
 * ⚠️ THE ONE-SHOT FLAG IS SET BEFORE THE WRITE, NEVER AFTER IT. `$write` itself queries
 * `submission_answers`, so a listener guarded on its own result re-enters and writes forever — the
 * re-entrancy `EncodePromoteTest`'s R1 case paid for in M11, where the escaping 23505 poisoned
 * RefreshDatabase's outer transaction with 25P02 and surfaced as a tenancy failure three frames away.
 *
 * ⚠️ IT MATCHES THE SELECT, NOT THE TABLE NAME, because `$write` and `updateOrCreate` both name the table
 * too.
 *
 * ⚠️ `$skip` EXISTS BECAUSE THE HTTP CHANNELS SAVE BEFORE THEY PROMOTE, AND GETTING IT WRONG FAILS LOUDLY
 * RATHER THAN SILENTLY. `GuestSubmissionController` and `SubmissionController` both call `saveDraft()` first,
 * whose `SubmissionAnswer::updateOrCreate()` issues this same `select *` one statement before its own write —
 * so an interleave staged there is simply overwritten a millisecond later and `promote()` reads a consistent
 * document. Pass `skip: 1` on those channels to land in `promote()`'s window instead. A value that is too
 * small stages nothing and the refusal never comes; one that is too large never fires and the caller's
 * non-vacuity assertion reddens. There is no value that makes a case pass for the wrong reason.
 *
 * ⚠️ `$needle` MOVES THE STAGING POINT, AND ONE CASE NEEDS IT. Defaulting to the answer-document read puts
 * the write in the honest window — before the lock. Passing `for update` instead puts it AFTER the lock is
 * granted, which is what pins the guard's PLACEMENT: a check hoisted out of the transaction reads before
 * that point and cannot see it. ⚠️ That writer is deliberately one no production code path is — every real
 * writer of `submission_answers` for a draft goes through `updateDraft()`, which takes the `submissions`
 * lock first and would therefore block. The case staging it says so, and pins the code property rather than
 * a reachable race.
 *
 * @param  Closure():void  $write  the racing device's commit
 * @param  int  $skip  matching statements to let past before staging (0 for a direct service-level promote)
 * @param  string  $needle  the SQL fragment to stage on
 * @return Closure():bool whether the interleave actually fired
 */
function interleaveDuringPromote(Closure $write, int $skip = 0, string $needle = 'select * from "submission_answers"'): Closure
{
    $state = new stdClass;
    $state->fired = false;
    $state->seen = 0;

    DB::listen(function ($query) use ($state, $write, $skip, $needle): void {
        if ($state->fired || ! str_contains($query->sql, $needle)) {
            return;
        }

        if ($state->seen++ < $skip) {
            return;
        }

        $state->fired = true;
        $write();
    });

    return fn (): bool => $state->fired;
}

/**
 * Every live Fortify route, discovered by the NAMESPACE of its controller.
 *
 * Discovery rather than a list: a route added by a vendor upgrade appears here without the calling file
 * knowing its name, which is the property `AdminConsoleGateTest` was built around. The prefix is derived
 * from a ::class constant rather than written as a string literal, so it cannot be misspelt and cannot
 * drift.
 *
 * ⛔ MOVED HERE IN M68, FROM `FortifyRateLimitTest`, BECAUSE IT ACQUIRED A SECOND CONSUMER.
 * `FortifyTwoFactorCoverageTest` needs the identical discovery, and Pest loads a whole DIRECTORY into one
 * process — so calling a sibling file's file-scope helper works right up until somebody runs the one file
 * alone. Two copies would have been two chances to disagree about which routes are Fortify's, which is
 * the argument that put the coverage lists in their own classes in the first place.
 *
 * @return list<RoutingRoute>
 */
function fortifyRoutes(): array
{
    $prefix = substr(ConfirmablePasswordController::class, 0, -strlen('ConfirmablePasswordController'));

    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static function (RoutingRoute $route) use ($prefix): bool {
            $uses = $route->getAction('uses');

            return is_string($uses) && str_starts_with($uses, $prefix);
        },
    ));
}

/**
 * The verbs on a route that can change state. HEAD is an artefact of registering GET.
 *
 * @return list<string>
 */
function fortifyWriteVerbs(RoutingRoute $route): array
{
    return array_values(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']));
}
