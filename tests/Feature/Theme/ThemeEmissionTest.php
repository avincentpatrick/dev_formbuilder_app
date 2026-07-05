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
| Increment C1 — server-side theme emission + Inertia shared props.
|--------------------------------------------------------------------------
| HandleInertiaRequests::share() exposes auth.user + ui.theme; app.blade.php turns ui.theme into
| the <html data-theme-mode/data-accent> attributes the design-system theme layer reads. "system"
| (the default, and every guest) emits NO attribute — prefers-color-scheme decides.
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

it('degrades uiTheme() to system when no preference row is visible', function (): void {
    // No user_ui_preferences row (and no app.current_user_id context) — the belongs-to-user RLS
    // read returns nothing and must fall back to "system", never throw.
    $user = User::factory()->create();

    expect($user->uiTheme())->toBe(['mode' => 'system', 'accent' => 'blueprint']);
});

it('reads the stored theme when the user context is set', function (): void {
    $user = User::factory()->create();

    // belongs-to-user RLS: the row is visible/insertable only under the matching user context.
    TenantContext::applyLocal(null, $user->id);
    UserUiPreference::create(['user_id' => $user->id, 'theme_mode' => 'dark']);

    expect($user->uiTheme())->toBe(['mode' => 'dark', 'accent' => 'blueprint']);
});

it('emits data-theme-mode on <html> when the shared theme is explicit', function (): void {
    // Render the REAL root template with a dark theme prop — proves the blade emit branch
    // (the guest test above proves the "system = absence" branch).
    $this->withoutVite();

    $page = [
        'component' => 'Dashboard',
        'props' => ['ui' => ['theme' => ['mode' => 'dark', 'accent' => 'teal']]],
        'url' => '/',
        'version' => '',
    ];

    $html = view('app', ['page' => $page])->render();

    expect($html)->toContain('data-theme-mode="dark"')
        ->and($html)->toContain('data-accent="teal"');
});
