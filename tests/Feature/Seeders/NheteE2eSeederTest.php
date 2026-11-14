<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\FormSection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\NheteE2eSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Nhete\NhetePlan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M239 (`nhete-browser-walk`) — the seeder behind `tests/e2e/nhete-walk.spec.ts`.
|--------------------------------------------------------------------------
| The walk reads `/f/nhete` as ONE screen per page with save-and-resume on. A seeder that left the form on one page,
| without resume, or seeded it twice would turn the walk into a different test, still green, so those are pinned here.
|
| The owner is created by the case and the tenant entered as them, because the seeder's auth-connection lookup cannot
| see a user inside RefreshDatabase's uncommitted transaction (`E2eSeederIdempotencyTest` records the same split).
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('refuses without E2eSeeder\'s workspace rather than inventing one', function (): void {
    expect(fn () => (new NheteE2eSeeder)->run())->toThrow(RuntimeException::class, 'run E2eSeeder first');
})->group('nhete');

it('publishes the NHETE module once, one screen per page with save-and-resume, at /f/nhete', function (): void {
    $tenant = Tenant::create(['name' => 'Acme Research', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $owner = User::factory()->create(['email' => 'demo@meridian.test']);
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'admin');

    (new NheteE2eSeeder)->run();
    (new NheteE2eSeeder)->run();

    enterTenant($tenant->id, $owner->id);
    $forms = Form::withTrashed()->where('public_slug', NheteE2eSeeder::SLUG)->get();
    expect($forms)->toHaveCount(1);

    /** @var Form $form */
    $form = $forms->first();
    expect($form->current_published_version_id)->not->toBeNull()
        ->and($form->allow_guest_submissions)->toBeTrue()
        ->and($form->save_and_resume)->toBeTrue()
        ->and($form->single_page_mode)->toBeFalse()
        ->and($form->default_locale)->toBe('en')
        ->and(FormSection::query()->where('form_version_id', $form->current_published_version_id)->count())
        ->toBe(count((new NhetePlan)->sections()));

    // `withoutVite()`: the page's shell names built assets, and CI's Pest job builds none (run 43568612961 failed
    // here on a missing manifest while every local run, with assets built, passed).
    $this->withoutVite()->get('http://acme.meridian.test/f/nhete')->assertOk();
})->group('nhete');
