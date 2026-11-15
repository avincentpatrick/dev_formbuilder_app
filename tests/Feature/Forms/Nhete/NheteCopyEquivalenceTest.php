<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\SchemaSnapshotSerializer;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Nhete\NheteBuilderDriver;
use Tests\Support\Nhete\NhetePlan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M240 (`builder-copy-section`, `D224`) — a block built by copying is the block built by hand.
|--------------------------------------------------------------------------
| Domain B's indicator page (10 blocks, both shapes: with a cut-off question and without) is built twice through the
| builder's routes: question by question, as `M237` did, and with every block after the first of its shape COPIED from
| the latest one (`NheteBuilderDriver::$copyBlocks`), then renamed and reworded as a person would. The copy-built page
| must serialise exactly as the hand-built one. Only where each row sits on the draft's one sequence line, and the id of
| an uploaded image, may differ; the order itself may not.
|
| Every rule, choice, formula and piped placeholder of a copied block comes from the COPY (the driver renames, it does not
| re-enter them), so a link the copy failed to re-point arrives here still naming the source block.
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

/**
 * The draft's snapshot with positions as RANKS and uploaded images as one placeholder — everything else as stored.
 *
 * @return array<string, mixed>
 */
function nheteComparable(Form $form): array
{
    $snapshot = app(SchemaSnapshotSerializer::class)->snapshot(FormVersion::query()->findOrFail($form->draft_version_id));

    foreach (['sections', 'fields'] as $kind) {
        $bySequence = $snapshot[$kind];
        usort($bySequence, static fn (array $a, array $b): int => [$a['sequence'], $a['key']] <=> [$b['sequence'], $b['key']]);
        $rank = array_flip(array_column($bySequence, 'key'));

        $snapshot[$kind] = array_map(static function (array $row) use ($rank): array {
            $row['sequence'] = $rank[$row['key']];
            if (isset($row['config']['content']) && is_array($row['config']['content'])) {
                $row['config']['content'] = array_map(
                    static fn (array $block): array => isset($block['attachment_id']) ? [...$block, 'attachment_id' => 'image'] : $block,
                    $row['config']['content'],
                );
            }

            return $row;
        }, $snapshot[$kind]);
    }

    return $snapshot;
}

it('builds domain B by copying blocks into exactly the page built question by question', function (): void {
    $this->withoutVite();
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    $plan = new NhetePlan;
    $section = collect($plan->sections())->firstWhere('key', 'mx_b');
    $byHand = app(FormService::class)->create($tenant, $admin, 'Domain B by hand');
    $byCopy = app(FormService::class)->create($tenant, $admin, 'Domain B by copy');

    // One after the other: each driver sets the draft header for its own form.
    $hand = new NheteBuilderDriver($this, $admin, $byHand);
    $hand->buildSection($section);

    enterTenant($tenant->id, $admin->id);
    $copy = new NheteBuilderDriver($this, $admin, $byCopy);
    $copy->copyBlocks = true;
    $copy->buildSection($section);

    enterTenant($tenant->id, $admin->id);
    $blocks = count(array_unique(array_filter(array_column($section['elements'], 'indicator'))));

    // Two blocks by hand (the first of each shape), every other one copied.
    expect($blocks)->toBe(10)
        ->and($copy->blocksCopied)->toBe(8)
        ->and(nheteComparable($byCopy))->toBe(nheteComparable($byHand))
        ->and($copy->requests)->toBeLessThan($hand->requests);
});
