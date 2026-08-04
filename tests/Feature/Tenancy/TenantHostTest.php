<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\CustomDomainService;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| H22a — TenantUrl's two arms. Choosing between them is a security decision, not a style one:
| ADR-0009 §D2 scopes the Business-tier `custom_domain` feature to "public forms, not the admin app", so
| only the guest-runtime route group is served on a custom host. An authenticated link built on that host
| would 302 its recipient to the central app.
|
|   to()        → the APP host, always {label}.{deployment host}. PDF downloads (H17), invitations (H3).
|   toPublic()  → the PUBLIC host, preferring a live custom domain. Resume links (H9b) — the only
|                 outbound URL in the application that a RESPONDENT receives.
*/

beforeEach(function (): void {
    config(['app.url' => 'https://meridian.test']);
});

it('builds both arms on the app host when there is no custom domain', function (): void {
    $tenant = inboxTenant('acme');

    expect(TenantUrl::to($tenant, 'attachments/x'))->toBe('https://acme.meridian.test/attachments/x')
        ->and(TenantUrl::toPublic($tenant, 'f/resume/tok'))->toBe('https://acme.meridian.test/f/resume/tok');
});

it('keeps the app arm on the subdomain when a live custom domain exists', function (): void {
    // The correction of H17's guess about H22's shape. The old implementation used any dotted value
    // verbatim, which would now send an authenticated link to a host that does not serve the app.
    $tenant = inboxTenant('acme');
    customDomain($tenant, 'forms.acme-example.com');

    expect(TenantUrl::to($tenant, 'attachments/x'))->toBe('https://acme.meridian.test/attachments/x');
});

it('prefers a live custom domain on the public arm', function (): void {
    $tenant = inboxTenant('acme');
    customDomain($tenant, 'forms.acme-example.com');

    expect(TenantUrl::toPublic($tenant, 'f/resume/tok'))->toBe('https://forms.acme-example.com/f/resume/tok');
});

it('falls back to the app host on the public arm when the custom domain is not live', function (bool $verified, bool $activated): void {
    // Enforced by the model's global scope rather than by a branch here — the row is not visible at all.
    $tenant = inboxTenant('acme');
    customDomain($tenant, 'forms.acme-example.com', $verified, $activated);

    expect(TenantUrl::toPublic($tenant, 'f/resume/tok'))->toBe('https://acme.meridian.test/f/resume/tok');
})->with([
    'pending' => [false, false],
    'verified but not activated' => [true, false],
]);

it('answers deterministically when a tenant has two live custom domains', function (): void {
    // `->value('domain')` is first() with NO ORDER BY, so before H22a a second row made the host depend
    // on physical row order — the same shape as the double-subscription tie that could flip a suite from
    // green to red because a file was added elsewhere in the tree. Oldest-activated wins, and it must
    // stay that way: a resume link already sitting in a respondent's inbox has to keep resolving to the
    // same origin, which choosing the newest would silently break.
    $tenant = inboxTenant('acme');
    $first = customDomain($tenant, 'forms.acme-example.com');
    $first->forceFill(['activated_at' => now()->subDay()])->save();
    customDomain($tenant, 'apply.acme-example.com');

    foreach (range(1, 5) as $ignored) {
        expect(TenantUrl::toPublic($tenant->fresh(), 'f/resume/tok'))
            ->toBe('https://forms.acme-example.com/f/resume/tok');
    }
});

it('lets an explicit primary override the oldest-activated fallback', function (): void {
    // H22b. The fallback above is deterministic but it is not the TENANT'S choice, and once two hosts are
    // live only the tenant knows which one its audience should see. `is_primary` wins; the activation
    // order stays underneath it, so a tenant that never expresses a preference is unaffected.
    $tenant = inboxTenant('acme');
    $first = customDomain($tenant, 'forms.acme-example.com');
    $first->forceFill(['activated_at' => now()->subDay()])->save();
    $second = customDomain($tenant, 'apply.acme-example.com');

    expect(TenantUrl::toPublic($tenant->fresh(), 'f/resume/tok'))
        ->toBe('https://forms.acme-example.com/f/resume/tok');

    app(CustomDomainService::class)->makePrimary($second);

    expect(TenantUrl::toPublic($tenant->fresh(), 'f/resume/tok'))
        ->toBe('https://apply.acme-example.com/f/resume/tok');
});

it('returns to the oldest-activated fallback when the primary leaves service', function (): void {
    // `domains_primary_requires_live_chk` says a row may be primary only while it is activated, so
    // deactivate() has to clear the flag — and this asserts the CONSEQUENCE rather than the column: the
    // public host must go somewhere sensible rather than nowhere.
    $tenant = inboxTenant('acme');
    $first = customDomain($tenant, 'forms.acme-example.com');
    $first->forceFill(['activated_at' => now()->subDay()])->save();
    $second = customDomain($tenant, 'apply.acme-example.com');

    $service = app(CustomDomainService::class);
    $service->makePrimary($second);
    $service->deactivate($second->refresh());

    expect(TenantUrl::toPublic($tenant->fresh(), 'f/resume/tok'))
        ->toBe('https://forms.acme-example.com/f/resume/tok');
});

it('takes the scheme and port from app.url on both arms', function (): void {
    // The second half of the defect the older builders carried: a hard-coded https:// is wrong locally,
    // where the stack serves http://acme.localhost:8080, and dropping the port breaks the link entirely.
    config(['app.url' => 'http://localhost:8080']);
    $tenant = inboxTenant('acme');
    customDomain($tenant, 'forms.acme-example.com');

    expect(TenantUrl::to($tenant, 'attachments/x'))->toBe('http://acme.localhost:8080/attachments/x')
        // The custom host is used verbatim — it is a complete host, so no port is glued on.
        ->and(TenantUrl::toPublic($tenant, 'f/resume/tok'))->toBe('http://forms.acme-example.com/f/resume/tok');
});

it('defaults the scheme CLOSED when app.url is unusable', function (): void {
    config(['app.url' => '']);
    $tenant = inboxTenant('acme');

    expect(TenantUrl::to($tenant, 'attachments/x'))->toStartWith('https://acme.');
});

it('falls back to the slug when the tenant has no domain row at all', function (): void {
    $tenant = Tenant::create(['name' => 'Delta', 'slug' => 'delta', 'default_locale' => 'en']);

    expect(TenantUrl::to($tenant, 'attachments/x'))->toBe('https://delta.meridian.test/attachments/x');
});
