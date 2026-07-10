<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Concerns;

use App\Http\Middleware\EstablishGuestTenantContext;
use App\Support\Guest\GuestShareToken;
use Illuminate\Http\Request;

/**
 * Reads the verified {@see GuestShareToken} that {@see EstablishGuestTenantContext}
 * stashed on the request. The middleware always runs first on the guest routes, so the presence of the
 * attribute is an invariant (asserted, not re-validated).
 */
trait ReadsGuestShareToken
{
    protected function shareToken(Request $request): GuestShareToken
    {
        $token = $request->attributes->get('guestShareToken');

        assert($token instanceof GuestShareToken);

        return $token;
    }
}
