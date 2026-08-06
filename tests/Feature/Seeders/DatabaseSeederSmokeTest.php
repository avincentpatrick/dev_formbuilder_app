<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| I6 — `DatabaseSeeder` is EXECUTED by a test, for the first time in this repository's life.
|
| This file exists because of what its absence cost. `DatabaseSeeder` carried `WithoutModelEvents`, which
| wraps the whole run — and every seeder it calls — in `Model::withoutEvents()`. That suppresses
| `HasUuidv7::creating` (which fills the uuid primary key; `users.id` has no database default) and
| `BelongsToTenant::creating` (which fills `tenant_id`, without which strict RLS rejects the row). So
| `php artisan migrate:fresh --seed` — the first command `docs/TESTING-GUIDE.md` asks a human to run — was
| broken, and had been for a long time.
|
| Nothing caught it because nothing ran it: `.github/workflows/ci.yml` seeds only `E2eSeeder`, and no test
| referenced `DatabaseSeeder` at all. The fix is one line; this test is the part that stops it recurring.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

afterEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('runs end to end, which is the whole point of this file', function (): void {
    (new DatabaseSeeder)->run();

    // Both demo workspaces exist and are addressable — `Tenant::getCustomColumns()` whitelists `slug`, so a
    // regression that dropped it from that list would read back null here rather than silently spilling
    // into stancl's `data` json.
    expect(Tenant::query()->pluck('slug')->sort()->values()->all())
        ->toBe([DemoSeeder::DEMO_SLUG, DemoSeeder::SECOND_SLUG]);

    foreach ([DemoSeeder::DEMO_SLUG, DemoSeeder::SECOND_SLUG] as $slug) {
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        expect($tenant->domains()->where('domain', $slug)->exists())->toBeTrue();
    }

    // Every seeded row carries a real uuid primary key AND a real `tenant_id`. These two are the direct
    // assertion against `WithoutModelEvents`: with model events suppressed, `HasUuidv7::creating` never
    // fills the key and `BelongsToTenant::creating` never fills the tenant, so the insert raises 23502 (or
    // is rejected by the strict RLS `WITH CHECK`) long before this line is reached.
    $demo = Tenant::query()->where('slug', DemoSeeder::DEMO_SLUG)->firstOrFail();

    DB::transaction(function () use ($demo): void {
        TenantContext::applyLocal((string) $demo->getKey());

        $memberships = TenantUser::query()->get(['user_id', 'tenant_id']);
        expect($memberships)->not->toBeEmpty();

        foreach ($memberships as $membership) {
            expect($membership->user_id)->toBeString()->not->toBeEmpty()
                ->and($membership->tenant_id)->toBe((string) $demo->getKey());
        }

        $forms = Form::query()->get(['id', 'tenant_id']);
        expect($forms)->not->toBeEmpty();

        foreach ($forms as $form) {
            expect($form->id)->toBeString()->not->toBeEmpty()
                ->and($form->tenant_id)->toBe((string) $demo->getKey());
        }
    });
});
