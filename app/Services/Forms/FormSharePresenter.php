<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\Tenant;
use App\Support\Forms\FormSlug;
use App\Support\Tenancy\TenantUrl;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * The share surface's read model (Increment I1, PRD Feature #3) — everything the Share modal needs to
 * describe the form's public link without guessing at any of it.
 *
 * ── EXTRACTED FROM `BuilderPresenter` IN J2b, AND THE REASON IS A DEFECT WE ALREADY PAID FOR ONCE ──────
 * This was `private BuilderPresenter::share()`, reachable only from `GET /forms/{form}/builder`. J2b puts
 * the Share modal on the form hub as well, and the smaller diff — a second copy assembled in the hub's
 * presenter — is exactly the shape J1e caught one increment ago: `AuditLogPresenter` and `AuditExporter`
 * each spelled the same five filter clauses out separately, the page built the export's URL from its own
 * params, and adding a filter to one would have made the page show three rows while the CSV carried four
 * thousand. Two encodings of "what is this form's public link" is the same bug wearing different clothes,
 * and the one that would ship is the worse one: a hub showing a stale or app-host URL for a custom-domain
 * tenant, on the surface built for sharing.
 *
 * Extraction first, then the second caller. `BuilderRoutesTest` and `ShareModal.test.ts` pass UNEDITED,
 * which is the proof it moved nothing — the same standard J1b held `Form::scopeVisibleTo()` to.
 *
 * ── THE URL IS COMPOSED HERE, NEVER IN THE BROWSER ────────────────────────────────────────────────────
 * `TenantUrl` has two arms and picking between them is a security decision (its class docblock): `to()`
 * is the APP arm and never returns a custom domain; `toPublic()` prefers one. A custom host serves the
 * guest runtime and nothing else (ADR-0012 §D1, from ADR-0009 §D2), so a respondent-facing link is
 * `toPublic()` by definition. There is no JS-side URL helper and no Ziggy in this app precisely so that
 * this cannot be re-decided per page: building the link from `window.location` in the modal would emit
 * the app host and silently hand every custom-domain tenant the wrong URL to print on a flyer.
 * `DomainPresenter` states the same rule for its own row: the public host is READ from `TenantUrl`,
 * never re-derived.
 *
 * `public_url` is null whenever the slug is — the modal renders a "no link yet" state rather than a
 * plausible-looking URL that 404s. Same for `is_published`: all three of GuestFormController's gates
 * answer 404, so a live-looking link on an unpublished form would be a link to a dead end with no
 * explanation attached.
 *
 * `suggested_slug` is computed server-side through the same {@see FormSlug} the XLSForm importer uses, so
 * the editor opens on a value that is already free rather than one the author discovers is taken only
 * after a 422.
 */
final class FormSharePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Form $form): array
    {
        $tenant = $this->tenantFor($form);

        $slug = $form->public_slug;

        return [
            'public_slug' => $slug,
            'allow_guest_submissions' => $form->allow_guest_submissions,
            // Spam protection (I8b) — saved by the same button as the two above, which is why it lives in
            // this block rather than in one of its own.
            'bot_challenge' => $form->bot_challenge->value,
            'guest_rate_limit_per_minute' => $form->guest_rate_limit_per_minute,
            'suggested_slug' => $slug ?? FormSlug::suggest($form),
            'is_published' => $form->current_published_version_id !== null,
            'public_host' => TenantUrl::publicHost($tenant),
            'public_url' => $slug === null ? null : TenantUrl::toPublic($tenant, 'f/'.$slug),
        ];
    }

    /**
     * The tenant whose hosts serve this form — taken from the FORM, not from ambient state.
     *
     * The container binding is used when it is already the right tenant, which is the normal HTTP path and
     * spares a query on every builder render. Otherwise the form's own `tenant_id` answers it. That fallback
     * is not defensive padding: `app(TenantContract::class)` resolves to NULL wherever stancl's tenancy was
     * never initialized — a console command, a queued job, and any test that establishes the RLS context with
     * `enterTenant()` (which sets the GUC but binds no Tenant). A pre-existing schedule test called
     * `present()` that way and turned this into a TypeError the moment the share block landed.
     *
     * Reading it off the form is also simply more correct: the form row carries the authoritative
     * `tenant_id`, so the answer cannot disagree with the record being presented.
     */
    private function tenantFor(Form $form): Tenant
    {
        $bound = app()->bound(TenantContract::class) ? app(TenantContract::class) : null;

        if ($bound instanceof Tenant && $bound->getKey() === $form->tenant_id) {
            return $bound;
        }

        return Tenant::query()->whereKey($form->tenant_id)->firstOrFail();
    }
}
