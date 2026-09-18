<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The requests the framework's maintenance stub lets through (M99, R-4ff3e848).
|--------------------------------------------------------------------------
| `artisan down --render` writes a pre-rendered page into `storage/framework/down`, and
| `public/index.php` serves it from `storage/framework/maintenance.php` BEFORE Composer's autoloader is
| loaded. That stub returns early for any request expecting JSON, or carrying the bypass cookie — so
| those requests fall through and boot the whole framework while `deploy.ps1` is resetting the checkout
| hard, renaming `vendor/` and `public/build`, and running `migrate --force`. The window is seconds, and
| what falls into it is ordinary tester traffic: the builder's autosave and the guest-form runtime.
|
| ⛔ IT CANNOT BE FIXED IN THE STUB. `artisan down` copies that file out of the framework's own package
| on every run, so an edit there is overwritten by the next deploy and lost entirely on the next
| `composer update`. The guard has to live in an app-owned file that runs BEFORE the autoloader.
|
| ⛔ AND IT MAY NOT USE ANYTHING FROM `vendor/`. Not Laravel, not Symfony, not a Composer class — that
| is the whole point, since half the failure mode is `vendor/` being renamed out from under the request.
| Plain PHP, superglobals, `json_decode`. This file is required from `public/index.php` above the
| autoloader and must stay loadable with nothing else present.
|
| ⚠️ THE ENVELOPE IS A SECOND COPY, KNOWINGLY, AND THE APP SAID SO A YEAR IN ADVANCE.
| `app/Support/Http/MaintenanceResponse` claims to be the single place a 503 is shaped and warns in its
| own docblock that a thrown `HttpException` becomes a `server_error` instead — which is exactly this
| bug. That class cannot be reached from here, so the shape is duplicated and pinned from both sides by
| `tests/Feature/Deploy/DeployMaintenanceGuardTest.php`. A duplicate that a test holds equal is a cost;
| an undocumented one is the defect.
|
| ⚠️ WHAT THIS DELIBERATELY DOES NOT DO: serve the pre-rendered page. A browser navigation is already
| handled correctly by the stub above it, and re-implementing that here would duplicate the half that
| works. This answers only the requests the stub hands onwards.
*/

return (static function (): void {
    $downFile = __DIR__.'/../storage/framework/down';

    if (! is_file($downFile)) {
        return;
    }

    $payload = json_decode((string) file_get_contents($downFile), true);

    if (! is_array($payload)) {
        return;
    }

    // ⛔ `empty()`, NEVER `isset()`. `DownCommand` ALWAYS writes an `except` key — an empty array when no
    //    `--except` was passed — so `isset($payload['except'])` is true on every single deploy and an
    //    isset-guarded loop would silently do nothing at all. This is the one trap the row named, and it
    //    is the kind that leaves a guard looking correct and behaving like it is absent.
    if (! empty($payload['except']) && is_array($payload['except'])) {
        $path = '/'.trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');

        foreach ($payload['except'] as $pattern) {
            $pattern = '/'.trim((string) $pattern, '/');

            if ($pattern === $path || ($pattern !== '/' && str_starts_with($path, $pattern.'/'))) {
                return;
            }

            if (str_contains($pattern, '*') && preg_match('#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'$#', $path) === 1) {
                return;
            }
        }
    }

    $retry = isset($payload['retry']) ? (int) $payload['retry'] : 0;
    $message = 'The application is briefly unavailable while an update is applied. Please try again in a moment.';

    $header = static function (string $name): string {
        return (string) ($_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))] ?? '');
    };

    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $accept = $header('Accept');

    // ⛔ THE INERTIA ARM COMES FIRST, AHEAD OF THE JSON TEST, AND IT IS `R-62aff714` FOLDED IN. An Inertia
    //    visit sends `Accept: text/html` with `X-Requested-With`, so it is NOT treated as JSON — yet it is
    //    not a browser navigation either, and a 503 body handed back to Inertia lands inside its error
    //    dialog rather than replacing the page. `X-Inertia-Location` with a 409 is the protocol's own
    //    instruction to do a hard visit, which is what re-fetches the maintenance page properly.
    //    `MaintenanceResponse` makes the same call in the same order for the same reason.
    if ($header('X-Inertia') !== '') {
        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
        http_response_code(409);
        header('X-Inertia-Location: '.$scheme.'://'.(string) ($_SERVER['HTTP_HOST'] ?? 'localhost').(string) ($_SERVER['REQUEST_URI'] ?? '/'));

        if ($retry > 0) {
            header('Retry-After: '.$retry);
        }

        exit;
    }

    $wantsJson = str_contains($accept, '/json')
        || str_contains($accept, '+json')
        || $header('X-Requested-With') === 'XMLHttpRequest';

    if (! $wantsJson) {
        return;
    }

    http_response_code(503);
    header('Content-Type: application/json');

    if ($retry > 0) {
        header('Retry-After: '.$retry);
    }

    // ⛔ THE API SURFACE GETS THE DOCUMENTED ENVELOPE AND EVERYTHING ELSE GETS THE FLAT ONE, which is the
    //    split `MaintenanceResponse` already draws. Before this, a fall-through that booted cleanly still
    //    answered `/api/v1` with a 503 `server_error` from the framework's Throwable arm — so the
    //    documented `maintenance_mode` contract was broken for the WHOLE window, not only in the fatal
    //    case the row describes.
    echo str_starts_with($path, '/api/v1/') || $path === '/api/v1'
        ? (string) json_encode(['error' => ['code' => 'maintenance_mode', 'message' => $message]])
        : (string) json_encode(['message' => $message]);

    exit;
})();
