<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Search\Arms\FormSearchArm;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The query's regconfig must match the generated column's (Increment J1b).
|
| `KeywordFilter` writes `to_tsquery('simple', ?)` as a SQL literal in two places (the predicate and the
| ranking fragment) and the two migrations write `to_tsvector('simple', ...)` in the column definitions.
| Four literals, no shared constant, nothing structural keeping them equal.
|
| ⚠️ THIS IS A FUNCTIONAL GUARD, NOT A PLAN GUARD, AND THAT DISTINCTION IS THE WHOLE POINT. An earlier
| version of `KeywordFilter`'s docblock claimed a regconfig mismatch would "stop using the GIN index and
| degrade to a sequential scan, which no functional test can see", and sent the reader off to build a plan
| test. That was wrong: an index on a STORED tsvector COLUMN is matched on the operator and the column, so
| the right-hand side's regconfig cannot cost it. What a mismatch actually changes is WHICH ROWS COME BACK —
| a correctness failure that a functional case sees immediately. (See `SearchIndexUsageTest` for the
| genuinely plan-shaped question, which is a different one.)
|
| ── WHY A STOPWORD IS THE CASE THAT WORKS, AND STEMMING IS NOT ───────────────────────────────────────────
| The obvious mutation-detector — search a stemmable word like "running" — DOES NOT REDDEN, and the reason
| generalises: `SearchTerms::tsQuery()` appends `:*` to the last token, `'english'` stems by TRUNCATING
| ("running" → "run"), and `'run':*` still prefix-matches the lexeme `running` that `'simple'` stored. Every
| regular stem is a prefix of its own input, so prefix matching silently rescues the mutation.
|
| Stopwords do not have that escape hatch. `to_tsquery('english', 'the:*')` lowers to the EMPTY tsquery, and
| `anything @@ ''::tsquery` is false for every row — so the query returns nothing at all. That is why the
| fixture below is titled with the same "The A Team" example the forms migration uses to argue for
| `'simple'` in the first place: the doc's argument and this test's fixture are the same fact.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    app(FormService::class)->create($this->tenant, $this->owner, 'The A Team');
    app(FormService::class)->create($this->tenant, $this->owner, 'Running Total');
});

/** @return list<string> */
function regconfigSearchTitles(User $user, string $keyword): array
{
    return array_map(
        static fn (array $row): string => (string) $row['title'],
        app(FormSearchArm::class)->search($user, SearchTerms::parse($keyword), 50)->rows
    );
}

it('finds a form by a stopword, which an english regconfig structurally cannot do', function (): void {
    // ⭐ THE MUTATION DETECTOR. Change either `to_tsquery('simple'` literal in KeywordFilter to `'english'`
    // and this goes red, because the query lowers to the empty tsquery and matches no row on earth.
    expect(regconfigSearchTitles($this->owner, 'the'))->toContain('The A Team');
});

it('keeps every stopword in the query rather than dropping it', function (): void {
    // The sanitiser's half of the same contract, asserted at the boundary rather than through the database:
    // if `SearchTerms` ever started stripping stopwords itself, the column could stay `'simple'` and the
    // case above would still go red — for a completely different reason. Separating them keeps a failure
    // legible.
    expect(SearchTerms::parse('The A Team')->tsQuery())->toBe('the & a & team:*');
});

it('matches a literal unstemmed lexeme, which is what simple promises', function (): void {
    expect(regconfigSearchTitles($this->owner, 'running'))->toContain('Running Total');
});

it('does not pretend to stem, so the documented cost is real rather than aspirational', function (): void {
    // The migration states this cost out loud ("there is no stemming, so 'submissions' does not match
    // 'submission' except through the prefix arm"). Pinned so the doc cannot quietly become false: if
    // someone "improves" search by switching to `'english'`, this case reddens ALONGSIDE the stopword one,
    // and the pair says exactly what changed.
    expect(regconfigSearchTitles($this->owner, 'ran'))->not->toContain('Running Total');
});
