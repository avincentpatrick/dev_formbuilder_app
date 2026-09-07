<?php

declare(strict_types=1);

use App\Enums\AccentToken;
use App\Enums\FontSizeScale;
use App\Enums\ThemeMode;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment C1 — server-side theme emission + Inertia shared props. Extended in G11 to the full
| four-axis personalization set (PRD Feature #9, design-system-reference.md §2.9).
|--------------------------------------------------------------------------
| HandleInertiaRequests::share() exposes auth.user + ui.theme; app.blade.php turns ui.theme into the
| <html data-theme-mode/data-accent/data-font-size/data-dyslexia-font> attributes the design-system
| theme layer reads. In EVERY axis the product default emits NO attribute — prefers-color-scheme
| decides the theme, and the base token values stand for the rest.
*/

it('shares auth.user (null) and a system theme for a guest', function (): void {
    $this->withoutVite()
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome', false)
            ->where('auth.user', null)
            ->where('ui.theme.mode', 'system')
            // NULL since H23a3 — "no opinion". A guest has no tenant brand to inherit either, so this
            // renders identically to the old 'blueprint'; what changed is what the value MEANS.
            ->where('ui.theme.accent', null));
});

it('emits no data-theme-mode/data-accent on <html> for a guest (system = absence)', function (): void {
    $html = $this->withoutVite()->get('/')->assertOk()->getContent();

    expect($html)->toContain('<html')
        ->and($html)->not->toContain('data-theme-mode')
        ->and($html)->not->toContain('data-accent');
});

it('degrades uiTheme() to the product defaults when no preference row is visible', function (): void {
    // No user_ui_preferences row (and no app.current_user_id context) — the belongs-to-user RLS
    // read returns nothing and must fall back to the defaults, never throw. Strict toBe() on the
    // whole array is deliberate: this assertion IS the documented shape of the shared ui.theme prop.
    $user = User::factory()->create();

    expect($user->uiTheme())->toBe([
        'mode' => 'system',
        // H23a3: the fallback expresses NO OPINION rather than "explicitly Blueprint". On a branded
        // tenant those differ — the first inherits the organisation's colour, the second refuses it —
        // and a user whose preference read just FAILED has certainly not refused anything.
        'accent' => null,
        'fontSize' => 'standard',
        'dyslexiaFont' => false,
    ]);
});

it('reads the stored appearance when the user context is set', function (): void {
    $user = User::factory()->create();

    // belongs-to-user RLS: the row is visible/insertable only under the matching user context.
    TenantContext::applyLocal(null, $user->id);
    UserUiPreference::create([
        'user_id' => $user->id,
        'theme_mode' => 'dark',
        'accent_token' => 'teal',
        'font_size_scale' => 'extra_large',
        'use_dyslexia_friendly_font' => true,
    ]);

    expect($user->uiTheme())->toBe([
        'mode' => 'dark',
        'accent' => 'teal',
        'fontSize' => 'extra_large',
        'dyslexiaFont' => true,
    ]);
});

it('carries a NULL accent_token through as null rather than collapsing it to blueprint', function (): void {
    // INVERTED IN H23a3, deliberately. §19 used to rule that NULL = the product default, so uiTheme()
    // resolved it to 'blueprint'. Tenant branding (ADR-0014) makes that collapse lossy: NULL now means
    // "no opinion", which app.blade.php reads as "the tenant's brand may apply here". Resolving it to
    // 'blueprint' at this boundary would suppress every tenant brand for every user, everywhere —
    // AccentToken::fromColumn() still performs that collapse and is deliberately NOT used by uiTheme().
    $user = User::factory()->create();

    TenantContext::applyLocal(null, $user->id);
    UserUiPreference::create(['user_id' => $user->id, 'theme_mode' => 'light']);

    expect($user->uiTheme()['accent'])->toBeNull();

    // The lossy helper still exists and still answers its own narrower question.
    expect(AccentToken::fromColumn(null))->toBe(AccentToken::Blueprint);
});

it('emits all four personalization attributes on <html> when set', function (): void {
    // Render the REAL root template — proves the blade emit branches (the guest test above proves
    // the "default = absence" branch).
    $this->withoutVite();

    $html = view('app', ['page' => [
        'component' => 'Dashboard',
        'props' => ['ui' => ['theme' => [
            'mode' => 'dark',
            'accent' => 'teal',
            'fontSize' => 'extra_large',
            'dyslexiaFont' => true,
        ]]],
        'url' => '/',
        'version' => '',
    ]])->render();

    expect($html)->toContain('data-theme-mode="dark"')
        ->and($html)->toContain('data-accent="teal"')
        ->and($html)->toContain('data-font-size="extra_large"')
        ->and($html)->toContain('data-dyslexia-font="true"');
});

it('emits no personalization attributes for the product defaults', function (): void {
    // Every axis defaults to the ABSENCE of its attribute, so a user who never opened Settings
    // renders byte-identically to a guest — and never fetches the opt-in web font.
    $this->withoutVite();

    $html = view('app', ['page' => [
        'component' => 'Dashboard',
        'props' => ['ui' => ['theme' => [
            'mode' => 'system',
            'accent' => 'blueprint',
            'fontSize' => 'standard',
            'dyslexiaFont' => false,
        ]]],
        'url' => '/',
        'version' => '',
    ]])->render();

    expect($html)->toContain('<html')
        ->and($html)->not->toContain('data-theme-mode')
        ->and($html)->not->toContain('data-accent')
        ->and($html)->not->toContain('data-font-size')
        ->and($html)->not->toContain('data-dyslexia-font');
});

it('refuses to emit an attribute value outside the whitelist', function (): void {
    // The blade matches against a whitelist rather than interpolating, so a corrupted or hand-edited
    // row cannot inject an arbitrary attribute value into the document.
    $this->withoutVite();

    $html = view('app', ['page' => [
        'component' => 'Dashboard',
        'props' => ['ui' => ['theme' => [
            'mode' => 'sepia',
            'accent' => 'crimson',
            'fontSize' => 'gigantic',
            'dyslexiaFont' => false,
        ]]],
        'url' => '/',
        'version' => '',
    ]])->render();

    // Assert against the <html> TAG, not the whole document: Inertia serialises the page props into
    // the body's data-page JSON, so the bogus values legitimately appear there. What must not happen
    // is that any of them becomes a root attribute the theme layer could match on.
    expect($html)->toMatch('/<html\b[^>]*>/');
    preg_match('/<html\b[^>]*>/', $html, $matches);
    $htmlTag = $matches[0];

    expect($htmlTag)->not->toContain('data-theme-mode')
        ->and($htmlTag)->not->toContain('data-accent')
        ->and($htmlTag)->not->toContain('data-font-size')
        ->and($htmlTag)->not->toContain('data-dyslexia-font')
        ->and($htmlTag)->not->toContain('sepia')
        ->and($htmlTag)->not->toContain('crimson')
        ->and($htmlTag)->not->toContain('gigantic');
});

/*
|--------------------------------------------------------------------------
| Increment M86 — the edge nothing compared: the enum default against the LIVE COLUMN DEFAULT.
|--------------------------------------------------------------------------
| docs/feature-backlog.md:7921 filed `User::defaultUiTheme()` as "a fourth copy no gate reaches", and
| the second half of that sentence is FALSE — the strict toBe() above already reddens if the method
| drifts. What was true, and what the row never said, is narrower: NO gate compared the method to the
| SCHEMA or to the document. tests/Feature/Migrations/DocumentedDefaultDriftTest.php compares the
| document to the database; this compares the code to the database; together the three agree by
| transitivity rather than through three separate parsers of one table — which is the third parser
| that row correctly refused to build.
|
| ⛔ THIS DOES NOT RESTATE THE LITERALS AND MUST NOT. It asserts that two independently-maintained
| sources agree, so it stays true when the product default legitimately changes, and goes red exactly
| when one side moves without the other. The literals in the degradation case above are a different
| thing and are correct: an assertion that derived its expectation from the code under test would
| prove nothing at all.
*/
it('keeps the enum product default and the live column default in agreement', function (): void {
    $defaults = collect(DB::select(
        "select column_name, column_default
         from information_schema.columns
         where table_schema = 'public' and table_name = 'user_ui_preferences'"
    ))->keyBy('column_name');

    // Postgres reports a varchar default as `'system'::character varying`; the cast is the storage
    // engine talking about itself and is not part of the value.
    $literal = static function (?string $raw): ?string {
        if ($raw === null) {
            return null;
        }

        return trim((string) preg_replace('/::[a-z ]+$/i', '', trim($raw)), "'");
    };

    expect($literal($defaults['theme_mode']->column_default ?? null))->toBe(ThemeMode::default()->value)
        ->and($literal($defaults['font_size_scale']->column_default ?? null))->toBe(FontSizeScale::default()->value)
        // The third axis has no enum and no possible one, so it is pinned against the method directly.
        // That is the honest shape for a boolean, and it is why the row's remedy closes two of three.
        ->and($literal($defaults['use_dyslexia_friendly_font']->column_default ?? null))
        ->toBe(User::defaultUiTheme()['dyslexiaFont'] ? 'true' : 'false');
});
