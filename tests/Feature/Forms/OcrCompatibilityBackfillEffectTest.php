<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\CapabilityFlags;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Migrations\OcrCompatibilityBackfill;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// THE RETROACTIVITY PROOF — the H8 backfill doing real work on real Postgres rows.
//
// The two precedent backfills (G10a CollaboratorBackfill, H5c LegacyOverrideBackfill) concluded their effect
// was unprovable in-suite, and for THEM that is right: their bodies are inherently cross-tenant (one stamps a
// row per tenant from the RLS-exempt `tenants` table, the other copies rows each carrying their own
// tenant_id), so nothing but a BYPASSRLS session can execute them meaningfully. H8's body differs
// structurally — it just scans `forms`, which carries the plain strict-RLS shape whose UPDATE policy is a
// bare `tenant_id = ctx`. So the SAME body, handed the app connection under the live tenant GUC enterTenant()
// sets, does genuine work on genuine rows, and that is what these cases exercise.
//
// The pre-H8 state is MANUFACTURED, not simulated: publish through the fixed code, then write `true` back
// onto forms.capability_flags. The old rule differed from the new one ONLY in the flag value it computed —
// the snapshot bytes are byte-identical — so this reproduces the exact production pre-state.
//
// Residual, stated honestly: this proves the SQL, the keyset walk, the monotone write, the primitive wiring
// and the postcondition against real rows for ONE tenant with a GUC. It does NOT prove the privileged
// connection's cross-tenant reach — the same residual both precedents carry. These cases pass the APP
// connection ON PURPOSE and that is not a licence for the migration to do so: assertPrivileged() and
// tests/Unit/OcrCompatibilityBackfillTest.php's migration-source assertion are what keep the migration honest.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->publisher = app(PublishService::class);
});

/** Publish a form whose draft the callback populates, and return the refreshed form. */
function backfillForm(string $title, Closure $populate): Form
{
    $form = app(FormService::class)->create(test()->tenant, test()->user, $title);
    $populate($form->draftVersion, test()->user);
    test()->publisher->publish($form->refresh(), test()->user);

    return $form->refresh();
}

/**
 * The cheapest grid configs StructuralValidationGate::assertMatrixConfigResolves accepts (non-empty, valued,
 * distinct rows + columns, plus cells for `matrix`). Defined locally rather than reusing
 * tests/Feature/Submissions/G4FieldTypesTest.php's helpers so this file runs standalone.
 *
 * @return array<string, mixed>
 */
function h8MatrixConfig(): array
{
    return [
        'rows' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']],
        'columns' => [['value' => 'q1', 'label' => 'Q1'], ['value' => 'q2', 'label' => 'Q2']],
        'cells' => [['value' => 'poor', 'label' => 'Poor'], ['value' => 'good', 'label' => 'Good']],
    ];
}

/** @return array<string, mixed> */
function h8LikertMatrixConfig(): array
{
    return [
        'rows' => [['value' => 'clean', 'label' => 'Cleanliness'], ['value' => 'staff', 'label' => 'Staff']],
        'columns' => [['value' => '1', 'label' => 'Poor'], ['value' => '5', 'label' => 'Excellent']],
    ];
}

/** A published grid form, rewound to the pre-H8 stored flags — the case most of these tests need. */
function backfillGridForm(string $title): Form
{
    $form = backfillForm($title, function ($draft, User $u): void {
        addFormField($draft, $u, 'name');
        addFormField($draft, $u, 'svc', FieldType::Matrix, 1, ['config' => h8MatrixConfig()]);
    });
    rewindToPreH8($form);

    return $form;
}

/**
 * Rewind a published form's flags to what the PRE-H8 rule would have stored. Extra keys are accepted so a
 * case can prove `jsonb_set` leaves a future increment's key alone.
 *
 * @param  array<string, mixed>  $extra
 */
function rewindToPreH8(Form $form, array $extra = []): void
{
    DB::table('forms')->where('id', $form->id)->update([
        'capability_flags' => json_encode(array_merge([
            'has_geofields' => false,
            'has_media' => false,
            'ocr_compatible' => true,
        ], $extra)),
    ]);
}

function storedFlags(Form $form): array
{
    return (array) json_decode((string) DB::table('forms')->where('id', $form->id)->value('capability_flags'), true);
}

it('clears a stale true on a grid form and a repeat form, and leaves a clean one alone', function (): void {
    $clean = backfillForm('Clinic Intake', function ($draft, User $user): void {
        addFormField($draft, $user, 'name');
        addFormField($draft, $user, 'age', FieldType::Integer, 1);
    });

    $grid = backfillForm('Satisfaction', function ($draft, User $user): void {
        addFormField($draft, $user, 'name');
        addFormField($draft, $user, 'sat', FieldType::LikertMatrix, 1, ['config' => h8LikertMatrixConfig()]);
    });

    $repeat = backfillForm('Household Roster', function ($draft, User $user): void {
        $section = FormSection::create([
            'form_version_id' => $draft->id,
            'key' => 'members',
            'label' => 'Members',
            'sequence' => 0,
            'is_repeatable' => true,
        ]);
        addFormField($draft, $user, 'prepared_by');
        addFormField($draft, $user, 'member_name', FieldType::ShortText, 1, ['form_section_id' => $section->id]);
    });

    // The fixed rule already got all three right at publish — that is the point of the code fix.
    expect(storedFlags($clean)['ocr_compatible'])->toBeTrue()
        ->and(storedFlags($grid)['ocr_compatible'])->toBeFalse()
        ->and(storedFlags($repeat)['ocr_compatible'])->toBeFalse();

    // Now put all three back into the pre-H8 world and let the backfill be the only thing that corrects them.
    rewindToPreH8($clean);
    rewindToPreH8($grid);
    rewindToPreH8($repeat);

    (new OcrCompatibilityBackfill)(DB::connection());

    expect(storedFlags($grid)['ocr_compatible'])->toBeFalse()
        ->and(storedFlags($repeat)['ocr_compatible'])->toBeFalse()
        // The positive control: without it the backfill could pass by flipping everything.
        ->and(storedFlags($clean)['ocr_compatible'])->toBeTrue();
});

it('agrees with CapabilityFlags on every published form it examined', function (): void {
    // The single most valuable assertion in the increment: the SQL selects and the PHP decides, so this is
    // what would catch the candidate query and the rule disagreeing about which forms matter.
    $forms = [
        backfillForm('Scalars', fn ($draft, User $u) => addFormField($draft, $u, 'name')),
        backfillForm('Grid', function ($draft, User $u): void {
            addFormField($draft, $u, 'name');
            addFormField($draft, $u, 'svc', FieldType::Matrix, 1, ['config' => h8MatrixConfig()]);
        }),
        backfillForm('Notes only', fn ($draft, User $u) => addFormField($draft, $u, 'intro', FieldType::Note)),
    ];

    foreach ($forms as $form) {
        rewindToPreH8($form);
    }

    (new OcrCompatibilityBackfill)(DB::connection());

    foreach ($forms as $form) {
        $version = $form->refresh()->currentPublishedVersion;

        expect(storedFlags($form)['ocr_compatible'])->toBe(
            CapabilityFlags::isOcrCompatible($version),
            "the backfill and the rule must agree on {$form->title}",
        );
    }
});

it('preserves every other flag key, including one a future increment added', function (): void {
    // jsonb_set on the single path, not a whole-object overwrite. `future_flag` is the canary.
    $grid = backfillGridForm('Grid');
    rewindToPreH8($grid, ['has_geofields' => true, 'future_flag' => 'keep me']);

    (new OcrCompatibilityBackfill)(DB::connection());

    // toEqual, not toBe: jsonb normalizes key order on write, so the stored order is Postgres's business.
    expect(storedFlags($grid))->toEqual([
        'has_geofields' => true,
        'has_media' => false,
        'ocr_compatible' => false,
        'future_flag' => 'keep me',
    ]);
});

it('ignores a form with no published version', function (): void {
    // INNER JOIN: nothing to derive a verdict from, so '{}' must stay '{}' rather than gaining a false key.
    $draftOnly = makeForm($this->user, 'Never published');

    (new OcrCompatibilityBackfill)(DB::connection());

    expect(DB::table('forms')->where('id', $draftOnly->id)->value('capability_flags'))->toBe('{}');
});

it('flips a soft-deleted form so a restore cannot resurrect a stale flag', function (): void {
    $grid = backfillGridForm('Deleted grid');
    $grid->delete();

    expect(DB::table('forms')->where('id', $grid->id)->value('deleted_at'))->not->toBeNull();

    (new OcrCompatibilityBackfill)(DB::connection());

    expect(storedFlags($grid)['ocr_compatible'])->toBeFalse();
});

it('is idempotent and does not touch updated_at', function (): void {
    $grid = backfillGridForm('Grid');
    $before = (string) DB::table('forms')->where('id', $grid->id)->value('updated_at');

    (new OcrCompatibilityBackfill)(DB::connection());
    (new OcrCompatibilityBackfill)(DB::connection());

    expect(storedFlags($grid)['ocr_compatible'])->toBeFalse()
        // A correction must not read as a tenant edit in any list ordered by updated_at.
        ->and((string) DB::table('forms')->where('id', $grid->id)->value('updated_at'))->toBe($before);
});

it('walks past the keyset page boundary', function (): void {
    // CHUNK is 500, so seeding 500+ published forms would be far too slow. Instead: prove the cursor
    // ADVANCES rather than re-reading page one forever, by walking the real statement with a page size of 1
    // over three would-flip forms. A cursor bug (>=, or forgetting to advance) loops or stalls here.
    $ids = [];
    foreach (['a', 'b', 'c'] as $suffix) {
        $ids[] = backfillGridForm("Grid {$suffix}")->id;
    }

    $seen = [];
    $cursor = '00000000-0000-0000-0000-000000000000';
    for ($page = 0; $page < 10; $page++) {
        $rows = DB::select(OcrCompatibilityBackfill::SELECT_SQL, [$cursor, 1]);
        if ($rows === []) {
            break;
        }
        $cursor = (string) $rows[0]->form_id;
        $seen[] = $cursor;
    }

    expect($seen)->toHaveCount(3)
        ->and($seen)->toEqualCanonicalizing($ids)
        // Strictly ascending: the walk cannot revisit a row, so it cannot loop.
        ->and($seen)->toBe(collect($seen)->sort()->values()->all());

    (new OcrCompatibilityBackfill)(DB::connection());

    foreach ($ids as $id) {
        expect((array) json_decode((string) DB::table('forms')->where('id', $id)->value('capability_flags'), true))
            ->toHaveKey('ocr_compatible', false);
    }
});
