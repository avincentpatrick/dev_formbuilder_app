<?php

declare(strict_types=1);

use App\Http\Controllers\Platform\FeedbackApiController;
use App\Http\Middleware\AuthenticatePlatformToken;
use App\Http\Middleware\RequirePlatformHost;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The platform support API (M175, `R-78f43f26`, `D139`, `D141`; ADR-0023)
|--------------------------------------------------------------------------
| What the dev session's `scripts/feedback-pull.php` reads and writes: the testers' feedback across every
| workspace, its screenshots, and a status change with a reply.
|
| ── ON ANY PLATFORM HOST, BEHIND ONE CREDENTIAL ─────────────────────────────────────────────────────────────
| No `->domain()`: on the testing server the central host is the agency's website and only the box reaches it,
| so the API answers on the workspace host too (`D141`). `RequirePlatformHost` still refuses a custom domain.
| No `web` group — no session, no CSRF, no cookie can authenticate here — and no tenancy middleware: these
| reads span workspaces and go through `SuperAdminService`. `AuthenticatePlatformToken` is the whole
| authorization, and re-checks the token's owner on every call.
|
| The order is deliberate: the host check before anything, the throttle before the token lookup (keyed on a
| hash of the bearer, so a flood of guesses spends its own budget), then the token.
|
| Route names are `platform.v1.*` — outside `tests/Pest.php`'s `api.v1.*` sweeps, which describe the tenant API,
| and outside Scramble's `api/v1` document (`openapi.json`). This surface is documented in ADR-0023 and
| `docs/api-specification.md`.
*/

Route::prefix('api/platform/v1')
    ->middleware([RequirePlatformHost::class, 'throttle:platform-api', AuthenticatePlatformToken::class, SubstituteBindings::class])
    ->name('platform.v1.')
    ->group(function (): void {
        Route::get('/feedback', [FeedbackApiController::class, 'index'])->name('feedback.index');
        Route::get('/feedback/{feedback}/screenshot', [FeedbackApiController::class, 'screenshot'])
            ->whereUuid('feedback')->name('feedback.screenshot');
        Route::patch('/feedback/{feedback}', [FeedbackApiController::class, 'update'])
            ->whereUuid('feedback')->name('feedback.update');
    });
