<?php

use App\Http\Middleware\AppSecurityHeaders;
use App\Http\Middleware\RequirePlatformHost;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// RequirePlatformHost (H22a / ADR-0012): this route carries no domain constraint, so without it the
// platform's own marketing/landing page would render on any tenant's custom domain. Same reasoning as
// config/fortify.php's middleware list — a host the platform does not own must not serve platform pages.
Route::middleware([RequirePlatformHost::class, AppSecurityHeaders::class])->group(function (): void {
    Route::get('/', fn () => Inertia::render('Welcome', [
        'appName' => config('app.name'),
        'phase' => 'Phase 0 — Foundations (walking skeleton)',
    ]));
});
