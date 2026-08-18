<?php

declare(strict_types=1);

namespace App\Http\Middleware\Concerns;

use App\Http\Controllers\Public\GuestSubmissionController;
use App\Http\Middleware\EstablishGuestTenantContext;
use App\Http\Middleware\VerifyGuestBotChallenge;
use App\Models\Form;
use App\Support\Guest\GuestShareToken;
use Illuminate\Http\Request;

/**
 * Resolve the `forms` row a verified guest share token points at, ONCE per request — Increment I8b.
 *
 * Three consumers now want the same row on the guest submit path: {@see EnforceGuestFormRateLimit} (for
 * the per-form ceiling), {@see VerifyGuestBotChallenge} (for the mechanism), and
 * {@see GuestSubmissionController} (for everything else). Stashing on the
 * request keeps the query count at exactly one, which is where it was before I8b.
 *
 * ⚠️ NOT MOVED INTO {@see EstablishGuestTenantContext}. Its docblock is explicit that verification does no
 * database work — that is what lets it run before RLS is engaged and reject a forged token for free — and
 * it also serves routes that want no form at all. Loading here instead keeps that property.
 *
 * The read is RLS-scoped: the tenant GUC is already set by the time any consumer of this trait runs, so a
 * token minted for tenant A cannot reach tenant B's row even if the id were somehow wrong.
 */
trait ResolvesGuestForm
{
    protected function guestForm(Request $request): Form
    {
        $cached = $request->attributes->get('guestForm');

        if ($cached instanceof Form) {
            return $cached;
        }

        $token = $request->attributes->get('guestShareToken');
        assert($token instanceof GuestShareToken);

        $form = Form::query()->whereKey($token->formId)->firstOrFail();
        $request->attributes->set('guestForm', $form);

        return $form;
    }
}
