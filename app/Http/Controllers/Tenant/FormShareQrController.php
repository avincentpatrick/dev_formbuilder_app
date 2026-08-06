<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\GuestFormController;
use App\Models\Form;
use App\Models\Tenant;
use App\Support\Tenancy\TenantUrl;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Response;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * The share surface's QR code (Increment I1) — an SVG of the form's public link, for a flyer, a poster or a
 * clipboard at a field site. PRD Feature #3 names QR distribution as one of the three things the public link
 * exists for.
 *
 * ── WHY THIS IS SERVER-RENDERED ────────────────────────────────────────────────────────────────────
 * `bacon/bacon-qr-code` was already in `composer.lock` as a `laravel/fortify` dependency, and Fortify already
 * server-renders the 2FA QR from it — so this costs no new dependency in either ecosystem. The alternative
 * considered was a client-side npm library, which would have put a new package in production `dependencies`
 * and therefore under the `npm audit --omit=dev` CI gate, to redraw something that changes only when the
 * author saves. It is promoted to a direct `composer.json` require here rather than left transitive, because
 * depending on another package's dependency is how a `composer update` becomes an outage.
 *
 * Served to an `<img src>` rather than inlined through `v-html`. The 2FA panel's `v-html` is the only one in
 * the entire `resources/` tree and is worth not making two of; `<img>` also cannot execute script even if the
 * SVG ever came to contain any, which — with `nosniff` and a fixed `Content-Type` — is the third line of a
 * defence the generator itself already provides.
 *
 * ── THE 404 ────────────────────────────────────────────────────────────────────────────────────────
 * A form with no slug has no link, and a QR code of nothing is not a smaller QR code. The route answers 404
 * so the `<img>` simply fails to load; the modal never requests it in that state, because it renders the
 * "not shareable yet" notice instead. Note this is the AUTHORING side, so unlike
 * {@see GuestFormController} it does NOT hide the difference between states —
 * the author is owed it, and `can:update,form` has already established they are entitled to know.
 *
 * The QR encodes {@see TenantUrl::toPublic()}, never the app host: a custom domain serves the guest runtime
 * and only the guest runtime (ADR-0012 §D1), and a QR code printed onto paper is the single least
 * correctable place to put the wrong hostname.
 */
final class FormShareQrController extends Controller
{
    /** Viewport edge in px. The SVG scales, so this only fixes the module-to-margin ratio. */
    private const SIZE = 320;

    public function __invoke(Form $form): Response
    {
        abort_if($form->public_slug === null, 404);

        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);

        $url = TenantUrl::toPublic($tenant, 'f/'.$form->public_slug);

        $writer = new Writer(new ImageRenderer(new RendererStyle(self::SIZE), new SvgImageBackEnd));

        // Medium correction (~15%): a share link is short enough that the extra modules cost nothing legible,
        // and these are printed and photographed under conditions the screen-only default does not survive.
        $svg = $writer->writeString($url, ecLevel: ErrorCorrectionLevel::M());

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'X-Content-Type-Options' => 'nosniff',
            // Never cached: the slug is mutable, and a stale QR is a code that leads somewhere wrong while
            // looking exactly as correct as a right one. `private` because the link may not be public yet.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
