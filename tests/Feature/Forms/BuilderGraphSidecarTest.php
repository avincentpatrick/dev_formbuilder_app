<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FormSection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H21d1 — GET /forms/{form}/graph, the Logic view's read-only sidecar.
|--------------------------------------------------------------------------
| The whole point of the endpoint is that it reads the DRAFT. Until now the branching notices existed only
| as a flash after a publish that had already succeeded — the author met them when there was nothing left to
| do about them. Every test here is therefore about *which* version answers, and about the states an author
| is legitimately in while typing: no draft yet, and a condition that does not parse.
|
| It is a READ. There is no 409 here and there never will be: the optimistic-concurrency contract belongs to
| the mutation routes, and H21d1 writes nothing. (H21d2's condition editor is where that contract has to be
| carried onto a new write path, per Doc #27 §8.)
|
| EVERY `${` literal is SINGLE-quoted (PHP 8.3 removed `${var}` interpolation).
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

function graphTenant(string $slug = 'acme', string $name = 'Acme'): Tenant
{
    $tenant = Tenant::create(['name' => $name, 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);

    return $tenant;
}

/** An admin of a fresh tenant, plus a form whose draft the callback authors. */
function graphFixture(Closure $author, string $slug = 'acme'): array
{
    $tenant = graphTenant($slug, ucfirst($slug));
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    $author($form->draftVersion, $admin);

    return [$tenant, $admin, $form->refresh()];
}

it('reports the draft\'s forward reference, which is exactly what publish would have flashed', function (): void {
    [, $admin, $form] = graphFixture(function ($draft, $user): void {
        $s1 = FormSection::create([
            'form_version_id' => $draft->id, 'key' => 'early', 'label' => 'Early', 'sequence' => 1,
            'relevant_expression' => '${answered_later} = \'yes\'',
        ]);
        $s2 = FormSection::create([
            'form_version_id' => $draft->id, 'key' => 'late', 'label' => 'Late', 'sequence' => 2,
        ]);
        addFormField($draft, $user, 'early_field', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
        addFormField($draft, $user, 'answered_later', FieldType::ShortText, 2, ['form_section_id' => $s2->id]);
    });

    $response = $this->actingAs($admin)->getJson("http://acme.meridian.test/forms/{$form->id}/graph");

    $response->assertOk();
    $notices = $response->json('notices');

    $forward = array_values(array_filter($notices, fn (array $n): bool => $n['kind'] === 'forward_reference'));
    expect($forward)->toHaveCount(1);
    // The HOST first: it is the node the canvas hangs the notice on, because it owns the late condition.
    expect($forward[0]['nodes'])->toBe(['early', 'answered_later']);
    expect($forward[0]['message'])->toContain('comes later in the form');
    // `fragment` is the flash's joining clause and must not reach the client — it reads as a sentence
    // fragment anywhere else, which is the entire reason GraphNotice carries two strings.
    expect(array_keys($forward[0]))->toBe(['kind', 'nodes', 'message']);
});

it('reads the DRAFT and not the published version, which is the reason it exists', function (): void {
    // The anti-vacuity test for the whole endpoint. Publish a CLEAN form, then dirty only the draft that
    // `publish()` step 9 cloned forward. An implementation that read `currentPublishedVersion` — or that
    // took whichever version it found first — returns nothing here and passes every other test in the file.
    [, $admin, $form] = graphFixture(function ($draft, $user): void {
        $s1 = FormSection::create(['form_version_id' => $draft->id, 'key' => 'basics', 'label' => 'Basics', 'sequence' => 1]);
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
    });

    app(PublishService::class)->publish($form, $admin);
    $form->refresh();

    expect($form->currentPublishedVersion)->not->toBeNull();
    expect($form->draftVersion->id)->not->toBe($form->currentPublishedVersion->id);

    // The published version stays clean; the fresh draft gains a cycle.
    $form->draftVersion->sections()->where('key', 'basics')->update(['relevant_expression' => '${gate} = \'yes\'']);

    $notices = $this->actingAs($admin)
        ->getJson("http://acme.meridian.test/forms/{$form->id}/graph")
        ->assertOk()
        ->json('notices');

    expect(array_values(array_filter($notices, fn (array $n): bool => $n['kind'] === 'cycle')))->not->toBeEmpty();
});

it('answers with an empty list rather than an error when the form has no draft', function (): void {
    // `Builder.vue`'s existing read-only state. An empty result reads correctly under the rail ("nothing to
    // report"); a 404 would put an error banner on a page whose whole content is legitimately absent.
    [, $admin, $form] = graphFixture(function ($draft, $user): void {
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1);
    });

    app(PublishService::class)->publish($form, $admin);
    $form->refresh();
    $form->forceFill(['draft_version_id' => null])->save();

    $this->actingAs($admin)
        ->getJson("http://acme.meridian.test/forms/{$form->id}/graph")
        ->assertOk()
        ->assertExactJson(['notices' => []]);
});

it('does not 500 on a half-typed condition, which is the ordinary state of a draft', function (): void {
    // The reachable half of the H21d1 defect: `emptyAtOpen()` reached `ExpressionParser::parse()` through
    // SemanticValidator and did not catch. On the publish path that is unreachable — the expression gate
    // refuses first — so it never fired until this endpoint started reading unvalidated drafts.
    [, $admin, $form] = graphFixture(function ($draft, $user): void {
        $s1 = FormSection::create([
            'form_version_id' => $draft->id, 'key' => 'gated', 'label' => 'Gated', 'sequence' => 1,
            'relevant_expression' => '${gate} = = \'yes\'',
        ]);
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
    });

    $this->actingAs($admin)
        ->getJson("http://acme.meridian.test/forms/{$form->id}/graph")
        ->assertOk()
        ->assertJsonStructure(['notices']);
});

it('says nothing about a form whose conditions are all straightforward', function (): void {
    [, $admin, $form] = graphFixture(function ($draft, $user): void {
        $s1 = FormSection::create(['form_version_id' => $draft->id, 'key' => 'basics', 'label' => 'Basics', 'sequence' => 1]);
        $s2 = FormSection::create([
            'form_version_id' => $draft->id, 'key' => 'details', 'label' => 'Details', 'sequence' => 2,
            'relevant_expression' => '${gate} = \'yes\'',
        ]);
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
        addFormField($draft, $user, 'detail', FieldType::ShortText, 2, ['form_section_id' => $s2->id]);
    });

    $this->actingAs($admin)
        ->getJson("http://acme.meridian.test/forms/{$form->id}/graph")
        ->assertOk()
        ->assertExactJson(['notices' => []]);
});

it('forbids a Viewer, the same gate the rest of the builder carries', function (): void {
    [$tenant, , $form] = graphFixture(function ($draft, $user): void {
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1);
    });

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer'); // no forms.* permissions

    $this->actingAs($viewer)
        ->getJson("http://acme.meridian.test/forms/{$form->id}/graph")
        ->assertForbidden();
});

it('does not leak another tenant\'s form', function (): void {
    [, , $form] = graphFixture(function ($draft, $user): void {
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1);
    }, 'acme');

    $other = graphTenant('beta', 'Beta');
    $intruder = User::factory()->create();
    enterTenant($other->id, $intruder->id);
    makeActiveMember($intruder, 'admin');

    // Acme's form id, asked for on Beta's host: route-model binding runs under Beta's RLS context and
    // finds nothing.
    $this->actingAs($intruder)
        ->getJson("http://beta.meridian.test/forms/{$form->id}/graph")
        ->assertNotFound();
});
