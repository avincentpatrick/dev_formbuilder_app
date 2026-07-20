<?php

declare(strict_types=1);
use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\RequiredMode;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
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
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
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

/** A tenant reachable at {slug}.meridian.test (its subdomain is the {slug} domain). */
function inboxTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);

    return $tenant;
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
