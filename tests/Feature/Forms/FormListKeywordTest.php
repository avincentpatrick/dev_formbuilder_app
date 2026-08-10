<?php

declare(strict_types=1);

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormPresenter;
use App\Services\Forms\FormService;
use App\Services\Search\Arms\FormSearchArm;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| `?q` on the forms list (Increment J1e) — the page where all four recon hazards live.
|
|   1. `FormPresenter::list()`'s new parameter is OPTIONAL, so `FormListScopingTest` passes UNEDITED. That
|      file is J1b's stated proof that extracting `Form::scopeVisibleTo()` moved nothing, and the proof
|      evaporates the moment the test has to be touched to accommodate a signature change. Asserted here by
|      calling the one-argument form and getting the whole list.
|   2. `ts_rank` ordering is CONDITIONAL. Unconditional, it scores every row 0.0 on an unfiltered page and
|      silently discards `orderByDesc('updated_at')` — the list's entire information design, changed by a
|      feature nobody was using at the time.
|   3. `status != archived` ANDs with the keyword, never replaces it. The trap is an `orWhere`.
|   4. The `#empty` slot no longer lies. Its Vue half is `forms/index.test.ts`; the server half is the
|      `empty_reason` assertion below, which is what the page branches on.
|
| And a fifth thing the recon did not ask for but the feature needs: the page and `FormSearchArm` must
| return the SAME rows for the same word, or a global-search hit links into a list that refuses to show it.
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

    $forms = app(FormService::class);
    $this->clinic = $forms->create($this->tenant, $this->admin, 'Clinic Intake');
    $this->household = $forms->create($this->tenant, $this->admin, 'Household Survey');
    $this->archived = $forms->create($this->tenant, $this->admin, 'Clinic Intake (retired)');

    // ⚠️ The archived form MATCHES the keyword on purpose. A display filter that got spelled as an
    // `orWhere` would surface it, and a fixture whose archived row did not match could never see that.
    $this->archived->forceFill(['status' => FormStatus::Archived->value])->save();

    // Distinct `updated_at` values, so "did the rank clause displace the recency order?" is answerable.
    // Household is the most recently touched, Clinic the least.
    Form::withoutTimestamps(function (): void {
        $this->clinic->forceFill(['updated_at' => now()->subDays(3)])->save();
        $this->household->forceFill(['updated_at' => now()->subDay()])->save();
    });
});

/** @return list<string> the titles the presenter shows for $keyword */
function keywordTitles(User $user, ?string $keyword): array
{
    return array_map(
        static fn (array $row): string => (string) $row['title'],
        app(FormPresenter::class)->list($user, $keyword === null ? null : SearchTerms::parse($keyword)),
    );
}

it('returns the whole visible list when called with one argument', function (): void {
    // Hazard 1, stated where it can fail. `FormListScopingTest` depends on this signature.
    expect(app(FormPresenter::class)->list($this->admin))->toHaveCount(2);
});

it('narrows on the title', function (): void {
    expect(keywordTitles($this->admin, 'clinic'))->toBe(['Clinic Intake'])
        ->and(keywordTitles($this->admin, 'household'))->toBe(['Household Survey']);
});

it('prefix-matches a partial word, which is what makes the box usable while typing', function (): void {
    expect(keywordTitles($this->admin, 'clin'))->toBe(['Clinic Intake']);
});

it('keeps an ARCHIVED form hidden even when its title matches the keyword', function (): void {
    // Hazard 3. `FormSearchArm` keeps the same filter for the same reason: a search result must not offer
    // a row the list refuses to show. Spelling either as an `orWhere` reddens exactly this.
    expect(keywordTitles($this->admin, 'clinic'))
        ->toBe(['Clinic Intake'])
        ->not->toContain('Clinic Intake (retired)');
});

it('leaves the unfiltered order as recency, and only reorders by rank under a query', function (): void {
    // Hazard 2. Unfiltered, Household (updated yesterday) must precede Clinic (three days ago). An
    // unconditional `ts_rank` clause scores both 0.0 and hands the order to the planner — which is
    // indistinguishable from correct on a two-row fixture until the day it is not, so the assertion is on
    // the ORDER rather than on the set.
    expect(keywordTitles($this->admin, null))->toBe(['Household Survey', 'Clinic Intake']);

    // Under a query that matches both, rank leads and recency is only the tiebreaker.
    expect(keywordTitles($this->admin, 'intake'))->toBe(['Clinic Intake']);
});

it('returns exactly what the global-search arm returns for the same word', function (): void {
    // The parity that stops the list and the arm becoming a fourth encoding of the same rule.
    $terms = SearchTerms::parse('clinic');

    $listed = array_map(
        static fn (array $row): string => (string) $row['id'],
        app(FormPresenter::class)->list($this->admin, $terms),
    );
    $searched = array_map(
        static fn (array $row): string => (string) $row['id'],
        app(FormSearchArm::class)->search($this->admin, $terms, 50)->rows,
    );

    sort($listed);
    sort($searched);

    expect($listed)->toBe($searched)->and($listed)->toHaveCount(1);
});

it('still refuses a form the viewer holds no grant on, keyword or not', function (): void {
    // The keyword narrows the visible set; it never widens it. An editor with no grants sees nothing
    // whether or not they type the exact title.
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');

    expect(keywordTitles($editor, 'clinic'))->toBe([])
        ->and(keywordTitles($editor, null))->toBe([]);
});

it('serves the page with the keyword echoed back and the right empty_reason', function (): void {
    $this->actingAs($this->admin)->withoutVite()
        ->get('http://acme.meridian.test/forms?q=clinic')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('forms/Index', false)
            ->where('filters.applied.q', 'clinic')
            ->where('empty_reason', null)
            ->has('forms', 1));

    // Hazard 4's server half: an established tenant searching a word nothing matches must NOT be told it
    // has no forms. `no_matches` is what the page branches on to say so.
    $this->actingAs($this->admin)->withoutVite()
        ->get('http://acme.meridian.test/forms?q=zzzznothing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('empty_reason', 'no_matches')
            ->has('forms', 0));

    $this->actingAs($this->admin)->withoutVite()
        ->get('http://acme.meridian.test/forms')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.applied.q', null)
            ->where('empty_reason', null));
});

it('echoes the CLAMPED query, not the raw input', function (): void {
    // 200 characters is `SearchTerms::MAX_RAW_LENGTH`. A box re-rendering the untruncated paste would
    // disagree with the result set beneath it.
    $paste = str_repeat('x', 300);

    $this->actingAs($this->admin)->withoutVite()
        ->get('http://acme.meridian.test/forms?q='.$paste)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.applied.q', str_repeat('x', 200)));
});

it('clamps rather than refuses a hostile query on a GET', function (): void {
    // `to_tsquery('simple', 'foo &')` raises 42601 — a 500 on an Inertia GET triggered by typing an
    // ampersand. This is the page where that reaches a real tsquery, so it is the page that must prove it.
    foreach (['&', '&&&', 'foo &', "'; drop--", '(clinic', 'clinic:*:*', '   '] as $hostile) {
        $this->actingAs($this->admin)->withoutVite()
            ->get('http://acme.meridian.test/forms?q='.urlencode($hostile))
            ->assertOk();
    }
});
