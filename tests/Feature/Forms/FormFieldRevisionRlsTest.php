<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FormFieldRevision;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M188 (`R-3894369d`) — `form_field_revisions` at the DB level: a workspace's history, append-only
|--------------------------------------------------------------------------
| Strict tenant isolation, and the `audits` shape (`append_only`): SELECT and INSERT policies only, so FORCE RLS
| leaves an UPDATE or a DELETE nothing to touch — a history is never rewritten, not even by its own workspace. Every
| query drops the ORM's tenant scope, so the question goes to POSTGRES.
*/

beforeEach(fn () => TenantContext::flush());

/** A workspace with one question whose label was changed once; returns [tenant, revision id]. */
function revisionRlsWorkspace(string $slug): array
{
    $tenant = Tenant::create(['name' => 'RLS '.$slug, 'slug' => $slug, 'default_locale' => 'en']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    $form = app(FormService::class)->create($tenant, $user, 'Survey')->refresh();
    $field = app(FormBuilderService::class)->addField($form, $user, FieldType::ShortText, null)->refresh();
    $row = app(BuilderPresenter::class)->field($field);
    app(FormBuilderService::class)->updateField($form->refresh(), $field, $user, [...$row, 'config' => (array) $row['config'], 'label' => 'Age'], $row['version']);

    return [$tenant, (string) FormFieldRevision::query()->withoutGlobalScopes()->value('id')];
}

it('shows a workspace its own history only', function (): void {
    [$acme, $acmeRevision] = revisionRlsWorkspace('rev-acme');
    [$globex] = revisionRlsWorkspace('rev-globex');

    enterTenant($acme->id);
    expect(DB::table('form_field_revisions')->pluck('id')->all())->toBe([$acmeRevision]);

    enterTenant($globex->id);
    expect(DB::table('form_field_revisions')->where('id', $acmeRevision)->count())->toBe(0);
});

it('lets no one rewrite or remove a history, its own workspace included', function (): void {
    [$acme, $revision] = revisionRlsWorkspace('rev-acme');
    enterTenant($acme->id);

    $updated = DB::table('form_field_revisions')->where('id', $revision)->update(['field_key' => 'tampered']);
    $deleted = DB::table('form_field_revisions')->where('id', $revision)->delete();

    expect($updated)->toBe(0)
        ->and($deleted)->toBe(0)
        ->and(DB::table('form_field_revisions')->where('id', $revision)->value('field_key'))->not->toBe('tampered');
});

it('refuses a revision written into another workspace', function (): void {
    [$acme] = revisionRlsWorkspace('rev-acme');
    [$globex] = revisionRlsWorkspace('rev-globex');
    enterTenant($acme->id);

    $row = (array) DB::table('form_field_revisions')->first();
    $row['id'] = (string) Uuid::uuid7();
    $row['tenant_id'] = $globex->id;

    expect(fn () => DB::table('form_field_revisions')->insert($row))->toThrow(QueryException::class);
});
