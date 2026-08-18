<?php

declare(strict_types=1);

use App\Models\TenantUser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The operator surface (Phase 4, P2b — ADR-0018 §D1).
|--------------------------------------------------------------------------
| `tenants:extract` is the ONLY way to produce an extract — there is deliberately no route, because a
| route needs a permission key (an authorization widening) and would put a whole-workspace read behind
| whatever the weakest session on it turns out to be. That makes this command's argument handling the
| entire input surface of the feature, which is why the resolver is tested rather than assumed.
*/

beforeEach(function (): void {
    (new RolePermissionSeeder)->run();
    $this->dir = sys_get_temp_dir().'/p2b-cmd-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

it('resolves a tenant by slug', function (): void {
    $tenant = inboxTenant('acme');

    $this->artisan('tenants:extract', ['tenant' => 'acme', '--path' => $this->dir])
        ->assertSuccessful();

    expect(File::exists($this->dir.'/manifest.json'))->toBeTrue();

    $manifest = json_decode((string) File::get($this->dir.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['tenant']['id'])->toBe($tenant->id);
});

it('resolves a tenant by id', function (): void {
    $tenant = inboxTenant('acme');

    $this->artisan('tenants:extract', ['tenant' => $tenant->id, '--path' => $this->dir])
        ->assertSuccessful();
});

it('resolves a tenant by hostname', function (): void {
    // Domain::unscopedQuery(), never $tenant->domains(): the Domain model carries a tenant-scoping global
    // scope and this command runs BEFORE any context exists, so a scoped lookup matches nothing and the
    // command reports a live hostname as unknown.
    $tenant = inboxTenant('acme');
    customDomain($tenant, 'forms.acme.test');

    $this->artisan('tenants:extract', ['tenant' => 'forms.acme.test', '--path' => $this->dir])
        ->assertSuccessful();
});

it('reports an unknown tenant as a failure rather than a uuid syntax error', function (): void {
    // ⚠️ NOT DEFENSIVE TIDINESS. `tenants.id` is a uuid column, so handing find() a slug raises
    // PostgreSQL 22P02 `invalid input syntax for type uuid` — a stack trace instead of a sentence, for the
    // input an operator is most likely to mistype. Str::isUuid() in the resolver is what makes this a
    // clean FAILURE, and deleting it turns this test red with a QueryException.
    inboxTenant('acme');

    $this->artisan('tenants:extract', ['tenant' => 'not-a-tenant', '--path' => $this->dir])
        ->expectsOutputToContain('No tenant matches')
        ->assertFailed();

    expect(File::exists($this->dir))->toBeFalse();
});

it('warns on the console when the extract has references it could not resolve', function (): void {
    // Surfaced, not buried in the manifest: the operator handing the artefact over is the person who has
    // to decide whether a dangling reference is an outstanding invitation (fine) or something that needs
    // explaining. An extract's most dangerous property is looking complete.
    $tenant = inboxTenant('acme');
    enterTenant($tenant->id);
    $owner = apiMember('owner');
    makeForm($owner, 'Survey');

    // The owner authored the form and is an ACTIVE member, so nothing dangles yet. Suspending the
    // membership is what takes them out of the `users` policy while leaving every row they wrote behind.
    TenantUser::query()->where('user_id', $owner->id)->update(['status' => 'suspended']);

    $this->artisan('tenants:extract', ['tenant' => 'acme', '--path' => $this->dir])
        ->expectsOutputToContain('are NOT in this extract')
        ->assertSuccessful();
});
