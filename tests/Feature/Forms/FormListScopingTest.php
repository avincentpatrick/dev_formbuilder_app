<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Forms\FormPresenter;
use App\Services\Forms\FormService;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The forms list is scoped to what the viewer can author (Increment G10b).
|
| Pre-existing behaviour, not introduced by G10a: BOTH the web presenter and GET /api/v1/forms returned
| every non-archived form in the tenant to anyone passing the read gate, with only the per-row `can` flags
| differing — so a Form Editor could read the titles and version history of forms they hold no grant on.
| Submissions always had a list twin (`Submission::scopeVisibleTo`); forms did not.
|
| Both surfaces are asserted here deliberately. Fixing only the presenter would have moved the leak from
| the web page to the API rather than closing it.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $this->editor = User::factory()->create();
    makeActiveMember($this->editor, 'form_editor');

    // Two forms the editor holds no grant on, created by the admin.
    app(FormService::class)->create($this->tenant, $this->admin, 'Not Theirs A');
    app(FormService::class)->create($this->tenant, $this->admin, 'Not Theirs B');
});

/** @return list<string> the titles the presenter shows $user */
function listedTitles(User $user): array
{
    return array_map(
        static fn (array $row): string => (string) $row['title'],
        app(FormPresenter::class)->list($user),
    );
}

it('shows an editor only the forms they hold an editor grant on', function (): void {
    $own = app(FormService::class)->create($this->tenant, $this->editor, 'Theirs');

    // FormService::create grants the creator Editor, so this is the one they can reach.
    expect(listedTitles($this->editor))->toBe(['Theirs'])
        ->and($own->title)->toBe('Theirs');
});

it('shows an editor with no grants an empty list, never everything', function (): void {
    // The fail-closed property: grantedFormIdsQuery() returns an explicit empty set rather than an
    // unconstrained query, so "no grants" can never degrade into "all forms".
    expect(listedTitles($this->editor))->toBe([]);
});

it('still shows an admin every form, via forms.edit.any', function (): void {
    expect(listedTitles($this->admin))->toHaveCount(2);
});

it('does not surface a form on a bare reviewer grant', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Review Only');
    makeCollaborator($form, $this->editor, ResourceCapacity::Reviewer);

    // The authoring list asks for Editor capacity specifically: a reviewer grant conveys no authoring
    // rights, so surfacing the row with every `can` flag false would be noise, not access.
    expect(listedTitles($this->editor))->not->toContain('Review Only');
});

it('surfaces a form reached through a scope-node grant', function (): void {
    $this->user = $this->editor;   // formIn() reads tenant/user off the test
    $node = makeScopeNode(name: 'Region I');
    $form = formIn(makeScopeNode($node, 'Province A'), 'Down The Tree');

    // Created by the editor, so drop the creator grant — the node grant must be what carries it.
    $form->grants()->delete();
    app(ResourceGrantResolver::class)->forget();
    grantOnNode($node, $this->editor, ResourceCapacity::Editor, descendants: true);

    expect(listedTitles($this->editor))->toContain('Down The Tree');
});

// ── The API twin ─────────────────────────────────────────────────────────────────────────────────────

it('scopes GET /api/v1/forms to the token holder’s grants', function (): void {
    $token = $this->editor->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;
    app(FormService::class)->create($this->tenant, $this->editor, 'Theirs');

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/forms')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Theirs');
});

it('still returns every form to an admin token', function (): void {
    $token = $this->admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/forms')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── I10c: the per-form statistics row action ─────────────────────────────────────────────────────────

it('exposes can.analytics on each row, matching the route gate exactly', function (): void {
    // The row action and `GET /forms/{form}/analytics` must agree, or the button either 403s on click or is
    // hidden from someone who may use it. Both resolve to FormPolicy::view.
    //
    // ⚠️ THERE IS NO FALSE CASE TO ASSERT, AND THAT IS THE FINDING RATHER THAN A GAP. FormPresenter::list()
    // already restricts rows to `forms.edit.any` holders or to grantedFormIdsQuery(..., Editor), and
    // can('view', $form) is FormPolicy::view -> canEdit -> that same predicate — so on every row that
    // REACHES this list the flag is structurally true. What this pins is that the key exists and tracks the
    // policy; the REFUSALS are pinned where they are observable, against the route itself, in
    // FormAnalyticsGateTest.
    //
    // Named separately from `can.template` rather than reusing it: FormPolicy::view and ::update resolve to
    // the same predicate TODAY, and a row action riding on that coincidence would follow the wrong one the
    // day they diverge.
    app(FormService::class)->create($this->tenant, $this->admin, 'Admin Form');
    $mine = app(FormService::class)->create($this->tenant, $this->editor, 'Editor Form');
    app(ResourceGrantResolver::class)->forget();

    $adminRows = app(FormPresenter::class)->list($this->admin);
    expect($adminRows)->not->toBeEmpty();

    foreach ($adminRows as $row) {
        expect($row['can'])->toHaveKey('analytics')
            ->and($row['can']['analytics'])->toBeTrue();
    }

    // A grant-less editor does not see the other form AT ALL, so there is no row to gate; the row they do
    // see carries the action.
    app(ResourceGrantResolver::class)->forget();
    $editorRows = app(FormPresenter::class)->list($this->editor);

    expect(array_column($editorRows, 'title'))->toBe(['Editor Form'])
        ->and($editorRows[0]['can']['analytics'])->toBeTrue()
        ->and($editorRows[0]['id'])->toBe($mine->id);
});
