<?php

declare(strict_types=1);

use App\Enums\AccentToken;
use App\Enums\FontSizeScale;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment G11 — richer personalization (accent / text size / dyslexia font).
|--------------------------------------------------------------------------
| Sibling of AppearancePreferenceTest (C2, theme mode). Covers the three axes G11 added: that they
| persist, that they flow back through the shared `ui.theme` prop and the <html> attributes, that
| Blueprint round-trips through a NULL column, that a partial PATCH cannot clobber a sibling axis, and
| that the belongs-to-user RLS policy still isolates rows now the table has more columns.
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

/** @return array{0: Tenant, 1: User} */
function personalizationActor(string $slug = 'acme'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
    $tenant->domains()->create(['domain' => $slug]);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');

    return [$tenant, $user];
}

it('defaults a member with no preference row to blueprint / standard / no dyslexia font', function (): void {
    [, $user] = personalizationActor();

    $this->actingAs($user)->withoutVite()
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ui.theme.accent', 'blueprint')
            ->where('ui.theme.fontSize', 'standard')
            ->where('ui.theme.dyslexiaFont', false));
});

it('persists the teal accent and emits data-accent on <html>', function (): void {
    [$tenant, $user] = personalizationActor();

    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['accent_token' => 'teal'])
        ->assertRedirect();

    enterTenant($tenant->id, $user->id);
    expect(UserUiPreference::where('user_id', $user->id)->value('accent_token'))->toBe(AccentToken::Teal);

    $html = $this->actingAs($user)->withoutVite()
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('ui.theme.accent', 'teal'))
        ->getContent();

    expect($html)->toContain('data-accent="teal"');
});

it('stores the blueprint default as NULL but reads it back as blueprint', function (): void {
    // data-dictionary §19: NULL = the product default, so "never expressed an opinion" and
    // "explicitly chose the default" are the same state in the database. The wire and the UI always
    // carry a real, non-null value — the mapping happens at the request boundary.
    [$tenant, $user] = personalizationActor();

    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['accent_token' => 'teal'])
        ->assertRedirect();
    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['accent_token' => 'blueprint'])
        ->assertRedirect();

    enterTenant($tenant->id, $user->id);
    // Asserted through the query builder, not the model, so the enum cast cannot mask a stored string.
    expect(DB::table('user_ui_preferences')->where('user_id', $user->id)->value('accent_token'))
        ->toBeNull();

    $this->actingAs($user)->withoutVite()
        ->get('http://acme.meridian.test/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('ui.theme.accent', 'blueprint'));
});

it('persists a text-size scale and emits data-font-size only when it is not standard', function (): void {
    [$tenant, $user] = personalizationActor();

    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['font_size_scale' => 'extra_large'])
        ->assertRedirect();

    enterTenant($tenant->id, $user->id);
    expect(UserUiPreference::where('user_id', $user->id)->value('font_size_scale'))
        ->toBe(FontSizeScale::ExtraLarge);

    $html = $this->actingAs($user)->withoutVite()
        ->get('http://acme.meridian.test/dashboard')->getContent();
    expect($html)->toContain('data-font-size="extra_large"');

    // Back to standard = the attribute disappears entirely (not data-font-size="standard").
    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['font_size_scale' => 'standard'])
        ->assertRedirect();

    $html = $this->actingAs($user)->withoutVite()
        ->get('http://acme.meridian.test/dashboard')->getContent();
    expect($html)->not->toContain('data-font-size');
});

it('coerces the dyslexia flag to a real boolean', function (): void {
    // Inertia/HTML form encodings send "1"/"0" rather than JSON booleans; the request uses boolean()
    // so the column never receives a string.
    [$tenant, $user] = personalizationActor();

    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['use_dyslexia_friendly_font' => '1'])
        ->assertRedirect();

    enterTenant($tenant->id, $user->id);
    expect(UserUiPreference::where('user_id', $user->id)->value('use_dyslexia_friendly_font'))->toBeTrue();

    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['use_dyslexia_friendly_font' => '0'])
        ->assertRedirect();

    enterTenant($tenant->id, $user->id);
    expect(UserUiPreference::where('user_id', $user->id)->value('use_dyslexia_friendly_font'))->toBeFalse();
});

it('never clobbers the other axes on a partial PATCH', function (): void {
    // THE load-bearing test. Every rule on UpdateAppearanceRequest is `sometimes`, so an absent key is
    // never validated and never reaches updateOrCreate. This is what lets the top-nav ThemeQuickToggle
    // keep PATCHing {theme_mode} alone without wiping a user's accent, text size and font choice — and
    // it is the assertion that would catch a future refactor breaking that guarantee.
    [$tenant, $user] = personalizationActor();

    $this->actingAs($user)->patch('http://acme.meridian.test/settings/appearance', [
        'theme_mode' => 'dark',
        'accent_token' => 'teal',
        'font_size_scale' => 'extra_large',
        'use_dyslexia_friendly_font' => true,
    ])->assertRedirect();

    // Exactly the payload ThemeQuickToggle sends.
    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['theme_mode' => 'light'])
        ->assertRedirect();

    enterTenant($tenant->id, $user->id);
    $preference = UserUiPreference::where('user_id', $user->id)->firstOrFail();

    expect($preference->theme_mode->value)->toBe('light')
        ->and($preference->accent_token)->toBe(AccentToken::Teal)
        ->and($preference->font_size_scale)->toBe(FontSizeScale::ExtraLarge)
        ->and($preference->use_dyslexia_friendly_font)->toBeTrue();
});

it('rejects values outside the curated whitelists', function (): void {
    // There is no DB CHECK on accent_token (data-dictionary §19 rules it out), so the App\Enums
    // whitelist is the ONLY thing enforcing the curated set. Prove it actually bites.
    [, $user] = personalizationActor();

    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['accent_token' => 'crimson'])
        ->assertSessionHasErrors('accent_token');

    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['font_size_scale' => 'gigantic'])
        ->assertSessionHasErrors('font_size_scale');
});

it('keeps rows isolated under the belongs-to-user RLS policy', function (): void {
    // The G11 migration adds columns to an RLS-protected table. PostgreSQL policies are ROW-level, so
    // this should be unaffected — but "should be" is exactly the assumption worth pinning.
    [$tenant, $alice] = personalizationActor();

    $this->actingAs($alice)
        ->patch('http://acme.meridian.test/settings/appearance', ['accent_token' => 'teal'])
        ->assertRedirect();

    $bob = User::factory()->create();
    enterTenant($tenant->id, $bob->id);
    makeActiveMember($bob, 'viewer');

    // Under Bob's context Alice's row simply does not exist.
    expect(UserUiPreference::where('user_id', $alice->id)->exists())->toBeFalse();

    // And Bob writing his own preference creates a second row rather than touching Alice's.
    $this->actingAs($bob)
        ->patch('http://acme.meridian.test/settings/appearance', ['font_size_scale' => 'large'])
        ->assertRedirect();

    enterTenant($tenant->id, $bob->id);
    expect(UserUiPreference::where('user_id', $bob->id)->value('font_size_scale'))
        ->toBe(FontSizeScale::Large);

    enterTenant($tenant->id, $alice->id);
    $aliceRow = UserUiPreference::where('user_id', $alice->id)->firstOrFail();
    expect($aliceRow->accent_token)->toBe(AccentToken::Teal)
        ->and($aliceRow->font_size_scale)->toBe(FontSizeScale::Standard);
});

it('degrades to the product defaults with no RLS context instead of throwing', function (): void {
    // Guards the rescue(..., report: false) in User::uiTheme(). Without it, any request rendered
    // before the tenant database context is established would 500.
    [, $user] = personalizationActor();

    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['accent_token' => 'teal'])
        ->assertRedirect();

    TenantContext::flush();

    expect($user->uiTheme())->toBe(User::defaultUiTheme());
});
