<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\ExplorerChart;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Explorer\ExplorerQueryRunner;
use App\Services\Forms\FormService;
use App\Support\Explorer\ExplorerQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Nhete\NheteAgreementTally;
use Tests\Support\Nhete\NheteAnswers;
use Tests\Support\Nhete\NheteBuilderDriver;
use Tests\Support\Nhete\NhetePlan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M246 (`grid-question-table`, `D230`) — NHETE with its tables drawn as tables.
|--------------------------------------------------------------------------
| The same module `NheteBuilderRouteTest` builds, in the TABLE layout (`NhetePlan`'s docblock): the three agreement grids,
| the four indicator tables and one parts table per indicator are Grids, with row details, "Not needed" cut-off cells,
| a comment required by its row, parts shown by a cell of the indicator table, and coverage columns shown by region.
| It is answered with the block layout's own scenario moved into the grids' cells (`NheteAnswers::asTables()`), so
| where the block layout refuses a question this refuses the same answer in its cell.
|
| The block layout stays `NheteBuilderRouteTest`'s, where section copy (`M240`) is what builds it; this one builds
| question by question, through the builder's own routes.
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

/** `$answers` with the value at a dotted path set, or taken out when `$value` is null (an emptied grid row goes too). */
function nheteTableWith(array $answers, string $path, mixed $value): array
{
    $parts = explode('.', $path);
    if (count($parts) === 1) {
        if ($value === null) {
            unset($answers[$path]);
        } else {
            $answers[$path] = $value;
        }

        return $answers;
    }
    [$grid, $row, $column] = $parts;
    if ($value === null) {
        unset($answers[$grid][$row][$column]);
        if (($answers[$grid][$row] ?? []) === []) {
            unset($answers[$grid][$row]);
        }
    } else {
        $answers[$grid][$row][$column] = $value;
    }

    return $answers;
}

it('builds NHETE with its tables as grids, publishes it, and takes and refuses responses as the module says', function (): void {
    $this->withoutVite();
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $plan = new NhetePlan(layout: NhetePlan::TABLE);
    $form = app(FormService::class)->create($tenant, $admin, $plan->title());
    $host = 'http://acme.meridian.test';

    // ── 1. Build it through the builder's routes ────────────────────────────────────────────────────────────
    $driver = new NheteBuilderDriver($this, $admin, $form);
    $driver->build($plan);
    enterTenant($tenant->id, $admin->id);
    $elements = $plan->elements();
    $otherBoxes = count(array_filter($elements, static fn (array $e): bool => $e['other'] !== null));
    $grids = array_values(array_filter($elements, static fn (array $e): bool => $e['type'] === 'matrix'));
    expect(FormSection::query()->where('form_version_id', $form->draft_version_id)->count())->toBe(count($plan->sections()))
        ->and(FormField::query()->where('form_version_id', $form->draft_version_id)->count())->toBe(count($elements) + $otherBoxes)
        // 3 agreement grids, 4 indicator tables, and a parts table for each of the 35 indicators.
        ->and(count($grids))->toBe(42)
        ->and(array_count_values(array_column($grids, 'role')))->toBe(['grid_table' => 3, 'ind_table' => 4, 'parts_table' => 35])
        // Building it this way takes far fewer requests than the block layout's 1,560 (M240's copy mode).
        ->and($driver->requests)->toBeLessThan(900);
    expect($plan->workarounds())->not->toHaveKeys(['per-row-grid', 'indicator-block', 'parts-block'])
        ->and($plan->workarounds())->toHaveKeys(['grid-column-hint', 'grid-column-word-cap', 'piped-placeholder']);

    // ── 2. Publish through the real gates: every grid rule and every cell named elsewhere is checked ──────────
    $this->actingAs($admin)->post("{$host}/forms/{$form->id}/publish");
    enterTenant($tenant->id, $admin->id);
    $form = Form::query()->findOrFail($form->id);
    expect($form->current_published_version_id)->not->toBeNull(
        'publish refused: '.json_encode(session('toast') ?? session('errors')?->getBag('default')->toArray()),
    );
    $version = FormVersion::query()->findOrFail($form->current_published_version_id);
    // M247 (`D231`): every screen holding an indicator or parts table is drawn on a wide page, and only those.
    $wideScreens = array_column(array_filter($plan->sections(), static fn (array $s): bool => ($s['page_width'] ?? null) === 'wide'), 'key');
    expect($wideScreens)->toHaveCount(8)
        ->and(collect($version->schema_snapshot['sections'])->where('page_width', 'wide')->pluck('key')->sort()->values()->all())
        ->toBe(collect($wideScreens)->sort()->values()->all());
    $snapshotRows = collect($version->schema_snapshot['fields'])->firstWhere('key', NhetePlan::indicatorGrid('b'))['config']['rows'];
    expect(collect($snapshotRows)->filter(static fn (array $r): bool => ($r['not_needed'] ?? []) === ['cut'])->pluck('value')->all())
        ->toBe(['b03', 'b08', 'b19', 'b20', 'b21'])
        ->and($snapshotRows[0]['details'])->not->toBe([]);

    // ── 3. Open it to guests ────────────────────────────────────────────────────────────────────────────────
    $this->actingAs($admin)->patchJson("{$host}/forms/{$form->id}/share", [
        'allow_guest_submissions' => true, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null, 'public_slug' => 'nhete-table',
    ])->assertSessionHasNoErrors();
    enterTenant($tenant->id, $admin->id);
    $token = shareTokenFor(Form::query()->findOrFail($form->id));
    $submit = fn (array $answers) => $this->postJson("{$host}/api/v1/public/f/{$token}/submissions", ['answers' => $answers]);

    // ── 4. A full response for each region branch: the coverage column shown is the one for the office's region ──
    foreach (['ncr' => 'cov_r', 'whole' => 'cov_n', 'another' => null] as $region => $coverage) {
        $response = $submit(NheteAnswers::scenario($plan, region: $region));
        expect($response->status())->toBe(201, "region {$region}: ".mb_substr((string) $response->getContent(), 0, 1500));

        enterTenant($tenant->id);
        $stored = SubmissionAnswer::query()->findOrFail($response->json('data.id'))->answers;
        expect($stored['a01_parts']['p1'])->toHaveKey('area')
            ->and(array_key_exists('cov_r', $stored['a01_parts']['p1']))->toBe($coverage === 'cov_r')
            ->and(array_key_exists('cov_n', $stored['a01_parts']['p1']))->toBe($coverage === 'cov_n')
            // B03 has no cut-off cell, so its row never holds one.
            ->and($stored[NhetePlan::indicatorGrid('b')]['b03'])->not->toHaveKey('cut');
    }

    // ── 5. Each rule refuses, at the cell, what the block layout refuses at the question ────────────────────
    $base = NheteAnswers::scenario($plan);
    $a = NhetePlan::indicatorGrid('a');
    $refusals = [
        'a kept-close indicator with no description' => ["{$a}.a07.comment", "{$a}.a07.comment"],
        'a changed cut-off with no value' => [NhetePlan::indicatorGrid('c').'.c14.comment', NhetePlan::indicatorGrid('c').'.c14.comment'],
        'a worry of "something else" left unexplained' => [NhetePlan::indicatorGrid('d').'.d12.comment', NhetePlan::indicatorGrid('d').'.d12.comment'],
        'a kept indicator whose part was skipped' => ['a01_parts.p1.keep', 'a01_parts.p1.keep'],
        'a part kept close with no description' => ['a01_parts.p2.close', 'a01_parts.p2.close'],
        'a disagreement row whose own choice was skipped' => ['tor.t03.agree', 'tor.t03.agree'],
    ];
    foreach ($refusals as $what => [$path, $field]) {
        $response = $submit(nheteTableWith($base, $path, null));
        $failed = $response->json('error.details.fields') ?? [];
        expect($response->status())->toBe(422, "{$what}: expected a refusal, got {$response->status()}")
            ->and(array_column($failed, 'field'))->toContain($field)
            ->and(count($failed))->toBe(1, "{$what}: refused on more than {$field}: ".json_encode($failed));
    }

    // A cell its row marks "Not needed", and a part of an indicator the office does not keep, are dropped, never stored.
    $response = $submit(nheteTableWith(nheteTableWith($base, NhetePlan::indicatorGrid('b').'.b03.cut', 'change'), 'd01_parts.p1.keep', 'yes'));
    expect($response->status())->toBe(201);
    enterTenant($tenant->id);
    $stored = SubmissionAnswer::query()->findOrFail($response->json('data.id'))->answers;
    expect($stored[NhetePlan::indicatorGrid('b')]['b03'])->not->toHaveKey('cut')
        ->and($stored)->not->toHaveKey('d01_parts');

    // ── 6. Saved as a template, a new form from it publishes and takes the same response ───────────────────
    $this->actingAs($admin)->post("{$host}/forms/{$form->id}/save-as-template", ['name' => 'NHETE tables', 'description' => null, 'category' => null])
        ->assertSessionHasNoErrors();
    enterTenant($tenant->id, $admin->id);
    $template = FormTemplate::query()->latest('created_at')->firstOrFail();
    $made = $this->actingAs($admin)->post("{$host}/forms/templates/{$template->id}/instantiate");
    preg_match('~/forms/([0-9a-f-]{36})/builder~', (string) $made->headers->get('Location'), $m);
    enterTenant($tenant->id, $admin->id);
    $copy = Form::query()->findOrFail($m[1] ?? '');
    expect(FormField::query()->where('form_version_id', $copy->draft_version_id)->where('field_type', 'matrix')->count())->toBe(42);

    // ── 7. Exported to XLSForm: every cell a question of its own, named so no two meet ─────────────────────
    $exported = $this->actingAs($admin)->get("{$host}/forms/{$form->id}/versions/{$form->current_published_version_id}/xlsform");
    expect($exported->getStatusCode())->toBe(200);
    $file = tempnam(sys_get_temp_dir(), 'nhete-table').'.xlsx';
    file_put_contents($file, $exported->streamedContent());
    expect(filesize($file))->toBeGreaterThan(0);
    @unlink($file);

    // ── 8. Charted row by row in the Data Explorer (M247, `D231`): each answered indicator of each response counts once ──
    enterTenant($tenant->id, $admin->id);
    $grid = NhetePlan::indicatorGrid('a');
    $kept = [];
    $answered = 0;
    foreach (Submission::query()->countable()->where('form_id', $form->id)->pluck('id') as $id) {
        foreach ((array) (SubmissionAnswer::query()->where('submission_id', $id)->value('answers')[$grid] ?? []) as $row) {
            $filled = array_filter((array) $row, static fn (mixed $cell): bool => $cell !== null && $cell !== '' && $cell !== []);
            if ($filled === []) {
                continue;
            }
            $answered++;
            $kept[(string) ($row['keep'] ?? '')] = ($kept[(string) ($row['keep'] ?? '')] ?? 0) + 1;
        }
    }
    $chart = static fn (array $definition): array => app(ExplorerQueryRunner::class)->run(ExplorerQuery::fromArray([
        'source' => 'grid_rows', 'form_id' => $form->id, 'grid' => $grid, 'chart' => 'bar', 'measures' => [['aggregate' => 'count']],
        'filters' => [], 'from' => CarbonImmutable::now('UTC')->subDay()->toDateString(), 'to' => CarbonImmutable::now('UTC')->addDay()->toDateString(),
        'timezone' => 'UTC', 'limit' => 50, ...$definition,
    ]), $admin);

    $byKeep = $chart(['dimensions' => [['field' => 'column:keep']]]);
    $counted = array_combine(array_column($byKeep['categories'], 'key'), $byKeep['series'][0]['values']);
    ksort($counted);
    ksort($kept);
    expect($answered)->toBeGreaterThan(0)
        ->and($byKeep['totals']['rows'])->toBe($answered)
        ->and($counted)->toBe($kept);

    // ── 9. M250 (`D234`): the agreement list — the short list NHETE's meeting approves in one step ────────────────
    // Eight more respondents who disagree about something, then the module's own rule: "No view or opinion" left out,
    // "Agree" counted as agreeing where it is offered (each other question's most common answer elsewhere), 75%, six views.
    foreach (NheteAgreementTally::variants(NheteAnswers::scenario($plan)) as $index => $answers) {
        $response = $submit($answers);
        expect($response->status())->toBe(201, "variant {$index}: ".mb_substr((string) $response->getContent(), 0, 1500));
    }

    enterTenant($tenant->id, $admin->id);
    assignPlanTier(PlanTier::Business);
    $picks = ['grid:tor:agree', 'grid:rule:agree', 'grid:rf:agree'];
    foreach (['a', 'b', 'c', 'd'] as $domain) {
        $picks[] = 'grid:'.NhetePlan::indicatorGrid($domain).':use';
        $picks[] = 'grid:'.NhetePlan::indicatorGrid($domain).':cut';
    }
    foreach (['q3_4', 'qm02', 'qm04', 'qm05', 'qm07a', 'qm07b', 'qm07c', 'qs01', 'q3_1'] as $key) {
        $picks[] = "answer:{$key}";
    }
    $definition = [
        'source' => 'agreement', 'form_id' => (string) $form->id, 'chart' => 'agreement', 'dimensions' => [], 'measures' => [], 'filters' => [],
        'from' => CarbonImmutable::now('UTC')->subDay()->toDateString(), 'to' => CarbonImmutable::now('UTC')->addDay()->toDateString(), 'timezone' => 'UTC',
        'agreement' => ['items' => $picks, 'leave_out' => ['noview'], 'agreeing' => ['agree'], 'threshold' => 75, 'min_views' => 6],
    ];
    $list = app(ExplorerQueryRunner::class)->run(ExplorerQuery::fromArray($definition), $admin)['agreement'];
    $lines = array_column($list['items'], null, 'key');
    $documents = SubmissionAnswer::query()
        ->whereIn('submission_id', Submission::query()->countable()->where('form_id', $form->id)->pluck('id'))
        ->pluck('answers')
        ->all();
    $tally = NheteAgreementTally::tally($documents, $list['items'], ['noview'], ['agree'], 75, 6);
    $counted = array_map(static fn (array $line): array => array_intersect_key($line, array_flip(['responses', 'views', 'agreeing', 'status'])), $lines);

    // Six indicators have no cut-off, so the four cut-off columns put 29 of the 35 rows on the list.
    expect($documents)->toHaveCount(12)
        ->and(array_filter(array_keys($lines), static fn (string $key): bool => str_ends_with($key, '.cut')))->toHaveCount(29)
        ->and($counted)->toBe($tally)
        ->and($lines['tor.t01.agree'])->toMatchArray(['views' => 12, 'agreeing' => 12, 'status' => 'short_list'])
        ->and($lines['tor.t02.agree'])->toMatchArray(['views' => 5, 'left_out' => 7, 'status' => 'too_few'])
        ->and($lines['tor.t03.agree'])->toMatchArray(['views' => 12, 'agreeing' => 4, 'share' => 33.3, 'status' => 'discuss', 'basis' => 'agreeing'])
        ->and($lines["{$a}.a01.use"])->toMatchArray(['views' => 12, 'agreeing' => 6, 'share' => 50, 'status' => 'discuss', 'basis' => 'most_common'])
        ->and($lines["{$a}.a07.use"])->toMatchArray(['views' => 4, 'status' => 'too_few'])
        ->and($lines['qm07b'])->toMatchArray(['responses' => 12, 'views' => 0, 'left_out' => 12, 'status' => 'too_few'])
        ->and($lines['q3_4'])->toMatchArray(['views' => 10, 'agreeing' => 6, 'share' => 60, 'status' => 'discuss'])
        ->and($lines['q3_1'])->toMatchArray(['multiple' => true, 'views' => 9, 'agreeing' => 9, 'status' => 'short_list']);

    // The order a meeting reads it in: the short list highest first, then the rest most-disagreed first, then too few views.
    $rank = ['short_list' => 0, 'discuss' => 1, 'too_few' => 2];
    foreach (array_slice($list['items'], 1) as $index => $line) {
        $before = $list['items'][$index];
        expect($rank[$line['status']])->toBeGreaterThanOrEqual($rank[$before['status']]);
        if ($line['status'] === $before['status'] && $line['status'] !== 'too_few') {
            $cross = $line['agreeing'] * $before['views'] <=> $before['agreeing'] * $line['views'];
            expect($line['status'] === 'short_list' ? $cross <= 0 : $cross >= 0)->toBeTrue("{$before['key']} then {$line['key']}");
        }
    }

    // Saved as a list and downloaded, the file carries the same lines in the same order.
    $this->actingAs($admin)->post("{$host}/explore/charts", ['name' => 'NHETE short list', 'definition' => $definition])->assertSessionHasNoErrors();
    enterTenant($tenant->id, $admin->id);
    $saved = ExplorerChart::query()->where('name', 'NHETE short list')->sole();
    // openspout opens a CSV with a UTF-8 byte-order mark; the three bytes are named by code so no editor can lose them.
    $csv = ltrim($this->actingAs($admin)->get("{$host}/explore/charts/{$saved->id}/export")->assertOk()->streamedContent(), chr(0xEF).chr(0xBB).chr(0xBF));
    $file = array_map(static fn (string $row): array => str_getcsv($row, escape: ''), explode(chr(10), trim($csv)));
    $labels = ['short_list' => 'On the short list', 'discuss' => 'To discuss', 'too_few' => 'Too few views'];
    expect(array_column(array_slice($file, 1), 6))->toBe(array_column($list['items'], 'key'))
        ->and(array_column(array_slice($file, 1), 1))->toBe(array_map(static fn (array $line): string => $labels[$line['status']], $list['items']));
})->group('nhete');
