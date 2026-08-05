<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Tenant;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Branding\TenantBrandingService;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The tenant brand logo, served to ANYONE (H23a4) — the one branding surface that is not behind `auth`.
 *
 * ── WHY THIS ROUTE HAD TO EXIST ─────────────────────────────────────────────────────────────────
 * H23a2 stores the logo as an ordinary {@see Attachment}, and the only route that serves one is
 * {@see AttachmentController::show()} — `auth` + `can:view,attachment`. **An email client is neither.** It
 * fetches `<img src>` with no session, from another IP, often through a proxy (Gmail rewrites every image
 * through googleusercontent.com), and frequently DAYS after the message was sent. Against the authenticated
 * route that is a 302 to login, i.e. a broken image in every branded email.
 *
 * **Deliberately NOT a signed or expiring URL, and this is the decision worth not re-litigating.** A signed
 * link is the instinctive answer and it is wrong for mail: an email lives in an inbox indefinitely and gets
 * forwarded, so any expiry converts "branded email" into "email with a broken image" on a timer. The asset
 * is public by nature — it is the logo a tenant puts at the top of a form every respondent opens — and the
 * row is already `is_pii = false`.
 *
 * ── WHAT MAKES THAT SAFE ────────────────────────────────────────────────────────────────────────
 *   - **Only ever the tenant's CURRENT logo.** The path carries no attachment id, so this is not a public
 *     read primitive over `attachments`; it resolves `tenants.logo_attachment_id` and nothing else. That is
 *     also why a removed logo stops serving immediately — a content-addressed URL would keep serving an
 *     asset the tenant deliberately took down.
 *   - **Gated on {@see TenantBrandingService::isActive()}, never `Tenant::hasBrandRamp()`.** Stored is not
 *     active: a tenant that brands on Starter and downgrades to Free keeps the row and stops rendering
 *     branded, here as everywhere else.
 *   - **SVG cannot reach this route**, because it cannot reach storage: `config/attachments.php` accepts
 *     png/jpeg/webp only and {@see AttachmentStorageService::storeBrandingLogo()}
 *     content-sniffs rather than trusting the upload header (H23a2, stored-XSS, threat-model §6). The
 *     `nosniff` header and the explicit `Content-Type` are the second and third lines of that defence.
 *   - **Unscanned or condemned bytes are never served**, the `AttachmentController::show()` precedent.
 *
 * ── ONE 404 FOR EVERY NEGATIVE, ON PURPOSE ──────────────────────────────────────────────────────
 * No logo, not yet scanned, condemned, or not entitled all answer 404. The authenticated sibling
 * distinguishes them (409 for "not yet available") because a staff member who uploaded a file is owed the
 * difference. An anonymous caller is not, and to an `<img>` tag every one of those is the same broken
 * image anyway — so the honest answer is the one that discloses least.
 */
final class BrandingLogoController extends Controller
{
    public function __invoke(TenantBrandingService $branding): StreamedResponse
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);

        abort_unless($branding->isActive($tenant), 404);

        $logo = $tenant->logoAttachment()->first();

        abort_if($logo === null || ! $logo->virus_scan_status->servable(), 404);

        return Storage::disk($logo->disk)->response(
            $logo->path,
            $logo->original_filename,
            [
                'Content-Type' => $logo->mime_type ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                // The URL is deliberately STABLE (see above), so it is deliberately NOT `immutable`:
                // replacing the logo has to take effect, and an hour is the window a tenant waits for
                // that. Longer would be a better cache and a worse product; shorter would hammer the
                // app container from every image proxy on every open.
                'Cache-Control' => 'public, max-age=3600',
            ],
            'inline',
        );
    }
}
