<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Branding\TenantBrandingService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H23a4 — `GET /branding/logo`, the one branding surface without `auth`.
|--------------------------------------------------------------------------
| It exists because an email client is not a session: it fetches <img src> unauthenticated, from another
| IP, often through a proxy, days later. The authenticated sibling `GET /attachments/{id}` answers that
| with a 302 to login, i.e. a broken image in every branded email.
|
| Everything below is about what makes an UNAUTHENTICATED read of tenant storage safe, so each negative
| is asserted separately rather than folded into one "it 404s" case: they are four different holes.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake(config('filesystems.default'));
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

const LOGO_URL = 'http://acme.meridian.test/branding/logo';

/**
 * A tenant with a stored ramp, an entitled plan and a clean logo whose bytes exist on the fake disk.
 *
 * @return array{0: Tenant, 1: Attachment}
 */
function brandedTenantWithLogo(PlanTier $tier = PlanTier::Starter, ScanStatus $scan = ScanStatus::Clean): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);

    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'admin');
    assignPlanTier($tier);

    app(TenantBrandingService::class)->setBrandColor($tenant, '#C0392B');

    $logo = brandingLogoAttachment($tenant->id);
    $logo->forceFill(['virus_scan_status' => $scan])->save();
    Storage::disk($logo->disk)->put($logo->path, 'PNGBYTES');

    $tenant->forceFill(['logo_attachment_id' => $logo->id])->save();

    return [$tenant->refresh(), $logo];
}

it('serves the logo to a caller with no session at all', function (): void {
    brandedTenantWithLogo();

    // No actingAs, deliberately: this asserts the route is reachable by the audience it was built for.
    $response = $this->get(LOGO_URL);

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'max-age=3600, public');

    expect($response->streamedContent())->toBe('PNGBYTES');

    // Inline, not an attachment: a mail client renders <img>, it does not download.
    expect((string) $response->headers->get('Content-Disposition'))->toStartWith('inline');
});

it('404s for a tenant whose plan does not include branding', function (): void {
    // The gate is `TenantBrandingService::isActive()` — stored AND entitled — not `hasBrandRamp()`. A
    // downgraded tenant keeps the row and stops rendering branded everywhere, this route included; check
    // the wrong one and a Starter+ feature is served to Free tenants with nothing in the build to notice.
    brandedTenantWithLogo(tier: PlanTier::Free);

    $this->get(LOGO_URL)->assertNotFound();
});

it('404s while the logo is still unscanned', function (): void {
    // The authenticated sibling answers 409 here, because a staff member who just uploaded a file is owed
    // the difference between "not yet" and "not there". An anonymous <img> is not, and to it both are the
    // same broken image — so this route discloses less.
    brandedTenantWithLogo(scan: ScanStatus::Pending);

    $this->get(LOGO_URL)->assertNotFound();
});

it('404s once the logo is unpointed, even though the object survives', function (): void {
    [$tenant, $logo] = brandedTenantWithLogo();

    enterTenant($tenant->id);
    app(TenantBrandingService::class)->removeLogo($tenant);

    $this->get(LOGO_URL)->assertNotFound();

    // Re-enter the tenant BEFORE reading: the GUC is torn down after every HTTP request, and an
    // RLS-invisible row reads as ABSENT rather than erroring — which would make the assertion below pass
    // for entirely the wrong reason. (H23a3 shipped a test that was vacuous for exactly this.)
    enterTenant($tenant->id);

    // `removeLogo()` deliberately clears the POINTER only — the row and the object stay in the storage
    // ledger to be reclaimed deliberately. This route reads the pointer, which is exactly why a stable
    // `/branding/logo` was chosen over a content-addressed `/branding/logo/{attachment}`: the latter would
    // keep serving an asset the tenant had just taken down.
    expect(Attachment::query()->whereKey($logo->id)->exists())->toBeTrue()
        ->and(Storage::disk($logo->disk)->exists($logo->path))->toBeTrue();
});

it('404s for a tenant that has a brand colour but no logo', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'admin');
    assignPlanTier(PlanTier::Starter);
    app(TenantBrandingService::class)->setBrandColor($tenant, '#C0392B');

    $this->get(LOGO_URL)->assertNotFound();
});

it('is not reachable on the central host', function (): void {
    brandedTenantWithLogo();

    // The route lives in the SUBDOMAIN group precisely so that the URL in an email is the tenant's own app
    // host and nothing else resolves it. A central-host request never even reaches
    // PreventAccessFromCentralDomains: InitializeTenancyBySubdomain runs first and throws
    // NotASubdomainException, which `bootstrap/app.php` renders as a redirect to the central app (H22a).
    // So the assertion is "redirected away, no bytes", not 404 — asserting 404 here would be asserting the
    // wrong middleware and would break the day identification changed.
    $response = $this->get('http://localhost/branding/logo');

    $response->assertRedirect();
    expect($response->getContent())->not->toContain('PNGBYTES');
});
