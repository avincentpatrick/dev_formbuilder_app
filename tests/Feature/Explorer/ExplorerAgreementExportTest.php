<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Enums\SubmissionStatus;
use App\Enums\UsageMetric;
use App\Models\ExplorerChart;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M250 (`agreement-report`, `D234` answer 2) — a saved agreement list as CSV or Excel.
|
| ASSERT ON ROWS, NEVER ON STATUS, as the analytics export's suite does: the stream closure runs after the request's
| database context is torn down, so a missing `applyLocal()` is a header-only file at HTTP 200. Helpers carry an
| `agreementExport` prefix — Pest's helpers are global, and this file must run on its own (`scripts/mutate.php`).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create(['name' => 'Olivia Owner']);
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    assignPlanTier(PlanTier::Business);

    $this->form = agreementExportForm($this->tenant, $this->owner);
    foreach (['agree', 'agree', 'agree', 'disagree', 'noview'] as $view) {
        agreementExportResponse($this->form, ['q_view' => $view, 'q_pick' => 'a']);
    }
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function agreementExportForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Consultation');
    $version = $form->draftVersion;

    addFormField($version, $owner, 'q_view', FieldType::SingleSelect, 0, ['label' => 'Do you agree?', 'config' => ['options' => [
        ['value' => 'agree', 'label' => 'Agree'], ['value' => 'disagree', 'label' => 'Disagree'], ['value' => 'noview', 'label' => 'No view or opinion'],
    ]]]);
    addFormField($version, $owner, 'q_pick', FieldType::SingleSelect, 1, ['label' => '=HYPERLINK("x")', 'config' => ['options' => [
        ['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B'],
    ]]]);

    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

/** @param  array<string, mixed>  $answers */
function agreementExportResponse(Form $form, array $answers): void
{
    $submission = Submission::factory()->forVersion(FormVersion::findOrFail($form->current_published_version_id))->create([
        'status' => SubmissionStatus::Submitted,
        'respondent_user_id' => null,
        'submitted_at' => CarbonImmutable::now()->subDays(2),
    ]);

    SubmissionAnswer::factory()->forSubmission($submission)->create(['answers' => $answers]);
}

/**
 * A saved agreement list over the form, the last month, 75% and three views.
 *
 * @param  array<string, mixed>  $overrides
 */
function agreementExportChart(User $user, Form $form, array $overrides = [], bool $shared = false): ExplorerChart
{
    return ExplorerChart::factory()->create([
        'user_id' => $user->id,
        'name' => 'Meeting short list',
        'is_shared' => $shared,
        'definition' => [
            'v' => 1,
            'source' => 'agreement',
            'form_id' => (string) $form->id,
            'chart' => 'agreement',
            'dimensions' => [],
            'measures' => [],
            'filters' => [],
            'from' => CarbonImmutable::now()->subDays(30)->toDateString(),
            'to' => CarbonImmutable::now()->toDateString(),
            'timezone' => 'UTC',
            'sort' => 'value_desc',
            'limit' => 10,
            'agreement' => ['items' => ['answer:q_view', 'answer:q_pick'], 'leave_out' => ['noview'], 'agreeing' => [], 'threshold' => 75, 'min_views' => 3],
            ...$overrides,
        ],
    ]);
}

function agreementExportUrl(ExplorerChart $chart, array $params = []): string
{
    return 'http://acme.meridian.test/explore/charts/'.$chart->id.'/export'.($params === [] ? '' : '?'.http_build_query($params));
}

/** Parse openspout CSV, stripping its UTF-8 BOM. @return list<list<string>> */
function agreementExportRows(string $csv): array
{
    $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);

    return array_values(array_filter(array_map(
        static fn (string $line): array => str_getcsv($line, escape: ''),
        explode("\n", trim($csv)),
    )));
}

it('streams the list line by line, in the order a meeting reads it — never a header alone', function (): void {
    $response = $this->actingAs($this->owner)->get(agreementExportUrl(agreementExportChart($this->owner, $this->form)));

    $response->assertOk();
    $rows = agreementExportRows($response->streamedContent());

    expect($rows[0])->toBe([
        'Order', 'Status (at least 75% agreeing of at least 3 views)', 'Section', 'Question', 'Column', 'Row', 'Key',
        'Responses', 'Gave a view', 'Left out', 'Counted answer', 'Agreeing', 'Share agreeing (%)', 'Answers',
    ])
        // Header-only — or zero bytes — is exactly what a missing applyLocal() produces, at HTTP 200.
        ->and(count($rows))->toBe(3)
        ->and($rows[1])->toBe(['1', 'On the short list', '', "'=HYPERLINK(\"x\")", '', '', 'q_pick', '5', '5', '0', 'A', '5', '100', 'A 5'])
        ->and($rows[2])->toBe(['2', 'On the short list', '', 'Do you agree?', '', '', 'q_view', '5', '4', '1', 'Agree', '3', '75', 'Agree 3; Disagree 1; No view or opinion 1 (left out)']);
});

it('meters the download before the stream, not on completion', function (): void {
    $this->actingAs($this->owner)->get(agreementExportUrl(agreementExportChart($this->owner, $this->form)))->assertOk();

    enterTenant($this->tenant->id, $this->owner->id);

    expect(DB::table('usage_counters')->where('metric', UsageMetric::ExportsCount->value)->sum('value'))->toBeGreaterThan(0);
});

it('names the file after the list and its range, and honours Excel', function (): void {
    $chart = agreementExportChart($this->owner, $this->form);
    $from = CarbonImmutable::now()->subDays(30)->toDateString();
    $to = CarbonImmutable::now()->toDateString();

    $this->actingAs($this->owner)->get(agreementExportUrl($chart))
        ->assertHeader('content-disposition', "attachment; filename=meeting-short-list-{$from}-to-{$to}.csv");

    $this->actingAs($this->owner)->get(agreementExportUrl($chart, ['format' => 'xlsx']))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('lets a colleague download a shared list with their own reach, and never a Viewer or another kind of chart', function (): void {
    $admin = User::factory()->create();
    makeActiveMember($admin, 'admin');
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');
    $shared = agreementExportChart($this->owner, $this->form, shared: true);
    $bars = ExplorerChart::factory()->shared()->create(['user_id' => $this->owner->id]);

    $rows = agreementExportRows($this->actingAs($admin)->get(agreementExportUrl($shared))->assertOk()->streamedContent());

    expect(count($rows))->toBe(3);

    // A Viewer reads the shared list on screen and cannot take it away: `submissions.export` is the inbox's rule.
    $this->actingAs($viewer)->get('http://acme.meridian.test/explore/charts/'.$shared->id.'/result')->assertOk();
    $this->actingAs($viewer)->get(agreementExportUrl($shared))->assertForbidden();
    $this->actingAs($this->owner)->get(agreementExportUrl($bars))->assertForbidden();
});

it('refuses, with a toast and before any file, a list that can no longer be counted', function (): void {
    $chart = agreementExportChart($this->owner, $this->form, ['agreement' => ['items' => ['answer:gone'], 'leave_out' => [], 'agreeing' => [], 'threshold' => 75, 'min_views' => 3]]);

    $this->actingAs($this->owner)
        ->from('http://acme.meridian.test/explore/charts/'.$chart->id)
        ->get(agreementExportUrl($chart))
        ->assertRedirect('http://acme.meridian.test/explore/charts/'.$chart->id)
        ->assertSessionHas('toast');
});
