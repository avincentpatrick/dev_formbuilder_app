<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Concerns;

use App\Http\Middleware\EstablishGuestDraftContext;
use App\Support\Guest\GuestShareToken;
use Illuminate\Http\Request;

/**
 * Reads the verified resume {@see GuestShareToken} that {@see EstablishGuestDraftContext} stashed on the
 * request. The middleware always runs first on the resume routes, so the presence of the attribute — and
 * its non-null `submissionId` — is an invariant (asserted, not re-validated).
 */
trait ReadsGuestResumeToken
{
    protected function resumeToken(Request $request): GuestShareToken
    {
        $token = $request->attributes->get('guestResumeToken');

        assert($token instanceof GuestShareToken && $token->submissionId !== null);

        return $token;
    }
}
