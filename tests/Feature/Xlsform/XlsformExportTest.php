<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Xlsform\XlsformExporter;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment G7a — XLSForm export (docs/xlsform-interop-spec.md): the pure build() row assembly across every
| field family + a streamed real-.xlsx round-trip + export authorization.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Locate the first survey row whose `name` cell equals $name. */
function surveyRow(array $sheets, string $name): ?array
{
    foreach ($sheets['survey']['rows'] as $row) {
        if (($row['name'] ?? null) === $name) {
            return $row;
        }
    }

    return null;
}

/**
 * Build a rich draft covering every field family (no publish needed — build() reads live rows through the
 * snapshot serializer). Returns [Form, FormVersion].
 */
function richDraftForm(User $owner): array
{
    $form = makeForm($owner, 'Field Survey');
    $version = makeDraftVersion($form);

    addFormField($version, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);

    $age = addFormField($version, $owner, 'age', FieldType::Integer, 1);
    // Expression-based constraint (the primary spec §2 path) …
    FormFieldValidation::create([
        'form_version_id' => $version->id, 'form_field_id' => $age->id,
        'expression' => '. >= 0', 'error_message' => 'Must be at least 0', 'sequence' => 0,
    ]);

    $height = addFormField($version, $owner, 'height', FieldType::Integer, 2);
    // … and a structured rule the frozen grammar can render.
    FormFieldValidation::create([
        'form_version_id' => $version->id, 'form_field_id' => $height->id,
        'rule_type' => 'min_value', 'rule_value' => '10', 'error_message' => 'Too small', 'sequence' => 0,
    ]);

    addFormField($version, $owner, 'bio', FieldType::LongText, 3, ['label_translations' => ['fr' => 'Biographie']]);
    addFormField($version, $owner, 'color', FieldType::SingleSelect, 4, ['config' => ['options' => [
        ['value' => 'r', 'label' => 'Red'], ['value' => 'b', 'label' => 'Blue'],
    ]]]);
    addFormField($version, $owner, 'subscribe', FieldType::YesNo, 5);
    addFormField($version, $owner, 'home', FieldType::Geopoint, 6, [
        'default_value' => json_encode(['type' => 'Point', 'coordinates' => [121.0, 14.6]]),
        'default_value_is_expression' => false,
    ]);
    addFormField($version, $owner, 'region', FieldType::CascadingSelect, 7, ['config' => [
        'levels' => [['key' => 'region', 'label' => 'Region'], ['key' => 'province', 'label' => 'Province']],
        'options' => [
            ['value' => 'ncr', 'label' => 'NCR', 'level' => 'region', 'parent' => null],
            ['value' => 'manila', 'label' => 'Manila', 'level' => 'province', 'parent' => 'ncr'],
        ],
    ]]);
    addFormField($version, $owner, 'score', FieldType::Calculated, 8, ['config' => ['calculated_formula' => '${age} + 1']]);
    addFormField($version, $owner, 'intro', FieldType::Note, 9);
    addFormField($version, $owner, 'brk', FieldType::PageBreak, 10);
    addFormField($version, $owner, 'satisfaction', FieldType::LikertMatrix, 11, ['config' => [
        'rows' => [['value' => 'speed', 'label' => 'Speed'], ['value' => 'quality', 'label' => 'Quality']],
        'columns' => [['value' => '1', 'label' => 'Low'], ['value' => '5', 'label' => 'High']],
    ]]);
    addFormField($version, $owner, 'grid', FieldType::Matrix, 12, ['config' => [
        'rows' => [['value' => 'q1', 'label' => 'Q1']],
        'columns' => [['value' => 'c1', 'label' => 'C1'], ['value' => 'c2', 'label' => 'C2']],
        'cells' => [['value' => 'y', 'label' => 'Yes'], ['value' => 'n', 'label' => 'No']],
    ]]);

    // A repeatable section with an inner field.
    $section = FormSection::create([
        'form_version_id' => $version->id, 'key' => 'members', 'label' => 'Members',
        'sequence' => 0, 'is_repeatable' => true, 'min_instances' => 1, 'max_instances' => 5,
    ]);
    addFormField($version, $owner, 'member_name', FieldType::ShortText, 13, [
        'form_section_id' => $section->id, 'section_sequence' => 0,
    ]);

    return [$form, $version->refresh()];
}

it('assembles the survey sheet across every field family', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    [$form, $version] = richDraftForm($owner);
    $sheets = app(XlsformExporter::class)->build($form, $version);

    expect(surveyRow($sheets, 'full_name'))->toMatchArray(['type' => 'text', 'required' => 'yes'])
        ->and(surveyRow($sheets, 'age')['constraint'])->toBe('. >= 0')
        ->and(surveyRow($sheets, 'age')['constraint_message'])->toBe('Must be at least 0')
        ->and(surveyRow($sheets, 'height')['constraint'])->toBe('. >= 10')
        ->and(surveyRow($sheets, 'bio'))->toMatchArray(['appearance' => 'multiline', 'label::fr' => 'Biographie'])
        ->and(surveyRow($sheets, 'color')['type'])->toBe('select_one color')
        ->and(surveyRow($sheets, 'subscribe')['type'])->toBe('select_one yes_no')
        ->and(surveyRow($sheets, 'home'))->toMatchArray(['type' => 'geopoint', 'default' => '14.6 121 0 0'])
        ->and(surveyRow($sheets, 'region'))->toMatchArray(['type' => 'select_one region', '#meridian' => 'meridian:cascading'])
        ->and(surveyRow($sheets, 'score'))->toMatchArray(['type' => 'calculate', 'calculation' => '${age} + 1'])
        ->and(surveyRow($sheets, 'intro')['type'])->toBe('note');
});

it('expands the lossy grid + structural types (§3)', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    [$form, $version] = richDraftForm($owner);
    $sheets = app(XlsformExporter::class)->build($form, $version);

    // page_break → zero-width group boundary carrying the round-trip marker.
    $brkRows = array_values(array_filter($sheets['survey']['rows'], fn (array $r): bool => ($r['name'] ?? null) === 'brk'));
    expect($brkRows[0])->toMatchArray(['type' => 'begin group', '#meridian' => 'meridian:page_break'])
        ->and($brkRows[1]['type'])->toBe('end group');

    // likert_matrix → one select_one per row on a shared scale list.
    expect(surveyRow($sheets, 'satisfaction_speed')['type'])->toBe('select_one satisfaction_scale')
        ->and(surveyRow($sheets, 'satisfaction_quality'))->not->toBeNull();

    // matrix → one select_one per (row × column) cell.
    expect(surveyRow($sheets, 'grid_q1_c1')['type'])->toBe('select_one grid_cells')
        ->and(surveyRow($sheets, 'grid_q1_c2'))->not->toBeNull();

    // repeatable section → begin/end repeat with a static repeat_count.
    expect(surveyRow($sheets, 'members'))->toMatchArray(['type' => 'begin repeat', 'repeat_count' => 5]);
});

it('assembles the choices + settings sheets', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    [$form, $version] = richDraftForm($owner);
    $sheets = app(XlsformExporter::class)->build($form, $version);

    $lists = array_column($sheets['choices']['rows'], null, 'name');
    expect($sheets['choices']['headers'])->toContain('level', 'parent'); // cascade columns present

    // colour options, the synthesized yes_no list, and the cascade rows with level/parent.
    $colorRows = array_filter($sheets['choices']['rows'], fn (array $r): bool => $r['list_name'] === 'color');
    expect(count($colorRows))->toBe(2)
        ->and(array_filter($sheets['choices']['rows'], fn (array $r): bool => $r['list_name'] === 'yes_no'))->toHaveCount(2);

    $ncr = collect($sheets['choices']['rows'])->first(fn (array $r): bool => $r['list_name'] === 'region' && $r['name'] === 'ncr');
    expect($ncr)->toMatchArray(['level' => 'region', 'parent' => '']);

    expect($sheets['settings']['rows'][0])->toMatchArray([
        'form_title' => 'Field Survey',
        'version' => '1',
        'default_language' => 'en',
    ]);
});

it('streams a valid XLSForm .xlsx that round-trips through the reader', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $form = publishedInboxForm($tenant, $owner);
    $version = FormVersion::findOrFail($form->current_published_version_id);

    $binary = $this->actingAs($owner)
        ->get("http://acme.meridian.test/forms/{$form->id}/versions/{$version->id}/xlsform")
        ->assertOk()
        ->streamedContent();

    $path = tempnam(sys_get_temp_dir(), 'xlsform').'.xlsx';
    file_put_contents($path, $binary);

    $sheetNames = [];
    $surveyHeader = [];
    $reader = new XlsxReader;
    $reader->open($path);
    foreach ($reader->getSheetIterator() as $sheet) {
        $sheetNames[] = $sheet->getName();
        if ($sheet->getName() === 'survey') {
            foreach ($sheet->getRowIterator() as $row) {
                $surveyHeader = $row->toArray();
                break;
            }
        }
    }
    $reader->close();
    @unlink($path);

    expect($sheetNames)->toContain('survey', 'choices', 'settings')
        ->and($surveyHeader)->toContain('type', 'name', 'label');
});

it('authorizes export exactly like form view (edit access)', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    $version = FormVersion::findOrFail($form->current_published_version_id);

    $this->actingAs($owner)
        ->get("http://acme.meridian.test/forms/{$form->id}/versions/{$version->id}/xlsform")
        ->assertOk();

    // A form_editor who is not a collaborator on this form cannot view/export it.
    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    $this->actingAs($editor)
        ->get("http://acme.meridian.test/forms/{$form->id}/versions/{$version->id}/xlsform")
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| M34 — the API twin of the export, which no test in the repository issued a request to.
|
| The web route above has had a real deny test since G7a ('authorizes export exactly like form view'). The
| /api/v1 twin — same bytes, same feature flag, a DIFFERENT controller and an extra ability gate — had
| nothing: not a 200, not a 403, not a request. Its only coverage was structural, GroupBPolicyGateTest,
| which walks the route table for the literal string `can:` and — in its own header's words at :33 — "cannot
| judge whether an allowlisted reason is TRUE". It issues no request and asserts no status, so a gate naming
| a permission nobody holds passes it.
|
| Middleware order measured with `route:list`, not assumed:
| CheckForAnyAbility:read:forms → Authorize:view,form → RequireFeature:xlsform_export. The entitlement
| answers LAST, and this suite assigns no plan at all (RequireFeature fails open on a null plan,
| RequireFeature.php:33), so both 403s below are token or policy refusals.
|
| BOTH DENIED CALLERS USE A FORM IN THEIR OWN TENANT, DELIBERATELY. bootstrap/app.php:217-218 runs
| SubstituteBindings BEFORE Authorize, so a cross-tenant caller 404s at route-model binding and would pass
| with `can:view,form` deleted. That is not a permission test, and this file does not pretend otherwise.
|--------------------------------------------------------------------------
*/

it('streams the XLSForm over the API to an owner token carrying read:forms', function (): void {
    // THE POSITIVE CONTROL for the two refusals below, and the first request any test has made to this URI.
    // Without it a controller that refused everybody would satisfy both of them.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    $version = FormVersion::findOrFail($form->current_published_version_id);
    $token = $owner->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->get("http://acme.meridian.test/api/v1/forms/{$form->id}/versions/{$version->id}/xlsform")
        ->assertOk();
});

it('refuses the API export to a write-only token, which never reaches the policy', function (): void {
    // The ability arm. Mirrors XlsformImportTest.php:277's read-only refusal in the opposite direction, and
    // asserts the envelope code too so a 403 arriving from some other layer cannot satisfy it.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    $version = FormVersion::findOrFail($form->current_published_version_id);
    $token = $owner->createToken('ci', [ApiAbilities::WRITE_FORMS])->plainTextToken;

    $this->withToken($token)
        ->get("http://acme.meridian.test/api/v1/forms/{$form->id}/versions/{$version->id}/xlsform")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_ability');
});

it('refuses the API export to a form_editor who is not a collaborator on that form', function (): void {
    // The SCOPE arm, and the one the row this closes actually asked for: a caller whose token scope is
    // correct and whose role holds the permission, refused because THIS form is not theirs. The token is
    // minted with read:forms so Sanctum passes it and only Authorize:view,form is left to answer.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    $version = FormVersion::findOrFail($form->current_published_version_id);

    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    $token = $editor->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->get("http://acme.meridian.test/api/v1/forms/{$form->id}/versions/{$version->id}/xlsform")
        ->assertForbidden();
});

it('refuses the WEB export to a viewer, the arm the existing collaborator case cannot reach', function (): void {
    // M34, and it is a second code path rather than a second spelling of the first. FormPolicy::canEdit is
    // `forms.edit.any || (forms.edit.own && holdsEditorGrant)` (:131-135). The form_editor case above fails
    // the SECOND clause — it holds forms.edit.own and lacks the grant. A viewer holds neither key
    // (RolePermissionSeeder:111-112 gives it submissions.view and the two dashboard reads), so it fails the
    // FIRST, and deleting either clause leaves one of the two cases green.
    //
    // No plan is assigned anywhere in this suite, deliberately: RequireFeature fails open on a null plan
    // (RequireFeature.php:33), so this is a 403 from the policy and not the 402-shaped redirect a Free
    // tenant gets — which FeatureGateWebTest.php:84-86 asserts separately and is a different question.
    //
    // POSITIVE CONTROL: the owner's 200 on this same URI at :239.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    $version = FormVersion::findOrFail($form->current_published_version_id);

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)
        ->get("http://acme.meridian.test/forms/{$form->id}/versions/{$version->id}/xlsform")
        ->assertForbidden();
});
