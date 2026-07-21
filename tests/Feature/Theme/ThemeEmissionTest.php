<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserUiPreference;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->where('ui.theme.accent', 'blueprint'));
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
        'accent' => 'blueprint',
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

it('resolves a NULL accent_token back to blueprint (§19: NULL = product default)', function (): void {
    $user = User::factory()->create();

    TenantContext::applyLocal(null, $user->id);
    UserUiPreference::create(['user_id' => $user->id, 'theme_mode' => 'light']);

    expect($user->uiTheme()['accent'])->toBe('blueprint');
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
