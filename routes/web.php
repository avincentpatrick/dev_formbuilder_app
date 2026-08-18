<?php

use App\Http\Controllers\PlatformLandingController;
use App\Http\Middleware\AppSecurityHeaders;
use App\Http\Middleware\RequirePlatformHost;
use Illuminate\Support\Facades\Route;

// RequirePlatformHost (H22a / ADR-0012): this route carries no domain constraint, so without it the
// platform's own marketing/landing page would render on any tenant's custom domain. Same reasoning as
// config/fortify.php's middleware list — a host the platform does not own must not serve platform pages.
//
// ⚠️ DO NOT ADD `Route::domain(...)` HERE. Since I6 this one route answers `/` on BOTH the central host and
// every workspace subdomain, branching on the host inside the controller. A domain constraint would stop it
// matching custom hosts, which would let a tenant `/` answer them with a 302 where
// `CustomDomainRoutingTest` pins a 404 — and would diverge `localhost` from `127.0.0.1` locally, since both
// are central domains but only one is `tenancy.central_domain`. The controller docblock has the full
// argument.
Route::middleware([RequirePlatformHost::class, AppSecurityHeaders::class])->group(function (): void {
    Route::get('/', PlatformLandingController::class)->name('landing');
});
