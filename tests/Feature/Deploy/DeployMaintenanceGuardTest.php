<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The deploy-window guard, exercised (M99, R-4ff3e848).
|--------------------------------------------------------------------------
| `public/maintenance-guard.php` runs BEFORE `vendor/autoload.php`, so nothing in the ordinary harness
| can call it the way production does. It is written as a dependency-free include for exactly that
| reason, and being includable under a fabricated superglobal is what makes it gateable at all — a guard
| that could only be exercised by opening a real maintenance window would have shipped unproved.
|
| ⛔ THE FILE IS COPIED TO A TEMPORARY ROOT RATHER THAN REQUIRED IN PLACE. It resolves the down file
| relative to its own directory, so requiring the shipped path would read the REAL storage directory of
| the checkout the suite is running against — and would answer 503 for the rest of the run the moment
| anyone left a down file behind. The copy keeps the relative resolution honest while pointing it at a
| fixture.
|
| ⛔ AND IT RUNS IN A SUBPROCESS, WHICH IS NOT A CONVENIENCE EITHER. The guard calls exit() on every path
| that answers, so an in-process require would end the suite at the first case. The subprocess also
| proves the thing an in-process require never could: that the file loads with NO autoloader present.
| The suite has already loaded one, so in-process it would pass whatever the guard depended on.
|
| ⚠️ WHAT IS NOT GATED HERE, said rather than implied: the `.htaccess` arm (there is no Apache in CI and
| no vhost in the repository to assert against), and the fatal-error half itself, which needs a request
| in flight while `vendor/` is being renamed. What IS gated is the contract every one of those requests
| gets — the half that was broken for the WHOLE window rather than only in the fatal case.
*/

use App\Support\Api\ApiErrorResponse;
use Illuminate\Support\Facades\File;

/** The payload `artisan down` actually writes: note `except` is ALWAYS present, and usually empty. */
const GUARD_DOWN = ['except' => [], 'retry' => 15, 'refresh' => null, 'secret' => null, 'status' => 503, 'template' => 'x'];

/**
 * Run the guard against a fabricated request and report what it did.
 *
 * @param  array<string, mixed>|null  $payload  the down-file payload, or null to write no down file
 * @param  array<string, string>  $server
 * @return array{fell_through: bool, body: string}
 */
function runMaintenanceGuard(?array $payload, array $server): array
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.str_replace('.', '-', uniqid('meridian-guard-', true));
    $public = $root.DIRECTORY_SEPARATOR.'public';
    $framework = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework';
    File::ensureDirectoryExists($public);
    File::ensureDirectoryExists($framework);

    if ($payload !== null) {
        file_put_contents($framework.DIRECTORY_SEPARATOR.'down', json_encode($payload));
    }

    File::copy(base_path('public/maintenance-guard.php'), $public.DIRECTORY_SEPARATOR.'maintenance-guard.php');

    // The sentinel is what tells a fall-through from an answer: the guard exits before reaching it on
    // every path that responds, and returns normally on every path that does not.
    $runner = implode(PHP_EOL, [
        '<?php',
        '$_SERVER = array_merge($_SERVER, '.var_export($server, true).');',
        "require __DIR__.'/maintenance-guard.php';",
        "echo '__FELL_THROUGH__';",
        '',
    ]);

    file_put_contents($script = $public.DIRECTORY_SEPARATOR.'run.php', $runner);

    $out = (string) shell_exec('php -d error_reporting=E_ALL '.escapeshellarg($script).' 2>&1');

    File::deleteDirectory($root);

    return [
        'fell_through' => str_contains($out, '__FELL_THROUGH__'),
        'body' => trim(str_replace('__FELL_THROUGH__', '', $out)),
    ];
}

it('answers an /api/v1 request with the documented maintenance envelope', function (): void {
    // ⛔ THE CONTRACT WAS BROKEN FOR THE WHOLE WINDOW, NOT ONLY IN THE FATAL CASE THE ROW DESCRIBES.
    //    Even when the fall-through booted cleanly, bootstrap/app.php's Throwable arm answered an
    //    /api/v1 request with a 503 `server_error` — never the documented `maintenance_mode`.
    $result = runMaintenanceGuard(GUARD_DOWN, [
        'REQUEST_URI' => '/api/v1/forms',
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $body = (array) json_decode($result['body'], true);

    expect($result['fell_through'])->toBeFalse()
        ->and($body['error']['code'] ?? null)->toBe('maintenance_mode')
        ->and($body['error']['message'] ?? null)->toBeString();
});

it('answers a non-API JSON request with the flat message envelope', function (): void {
    // The split `MaintenanceResponse` already draws: the documented API envelope on /api/v1, a flat
    // {message} everywhere else. Two shapes, so a client parsing one is never handed the other.
    $result = runMaintenanceGuard(GUARD_DOWN, [
        'REQUEST_URI' => '/forms/abc/autosave',
        'HTTP_ACCEPT' => 'application/json',
    ]);

    expect($result['fell_through'])->toBeFalse()
        ->and(array_keys((array) json_decode($result['body'], true)))->toBe(['message']);
});

it('sends an Inertia visit a hard-visit instruction rather than a body it would show in a dialog', function (): void {
    // R-62aff714, folded in. An Inertia visit sends `Accept: text/html` with `X-Requested-With`, so it
    // is NOT treated as JSON — and a 503 body handed back to Inertia lands inside its error dialog
    // instead of replacing the page. The arm therefore sits AHEAD of the JSON test.
    $result = runMaintenanceGuard(GUARD_DOWN, [
        'REQUEST_URI' => '/dashboard',
        'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
        'HTTP_X_INERTIA' => 'true',
        'HTTP_HOST' => 'acme.meridian.test',
    ]);

    expect($result['fell_through'])->toBeFalse()
        ->and($result['body'])->toBe('');
});

it('lets a browser navigation fall through to the stub that already handles it', function (): void {
    // Deliberately NOT re-implemented here: the pre-rendered page is the half that works, and a second
    // copy of it would be a second thing to keep correct.
    $result = runMaintenanceGuard(GUARD_DOWN, [
        'REQUEST_URI' => '/dashboard',
        'HTTP_ACCEPT' => 'text/html',
    ]);

    expect($result['fell_through'])->toBeTrue();
});

it('lets everything through when no window is open', function (): void {
    // The ordinary path, and the one that matters most: this file runs on every single request the
    // application serves, so a guard that answered when the site was up would be a total outage.
    $result = runMaintenanceGuard(null, [
        'REQUEST_URI' => '/api/v1/forms',
        'HTTP_ACCEPT' => 'application/json',
    ]);

    expect($result['fell_through'])->toBeTrue();
});

it('honours an except path, and the empty except every deploy writes exempts nothing', function (): void {
    // ⚠️ THIS DOES NOT PIN THE ROW'S `empty()` VERSUS `isset()` TRAP, AND SAYING SO IS THE POINT. That
    //    swap was driven through scripts/mutate.php against this file and SURVIVED: the guard only
    //    iterates the list, so an empty `except` runs zero iterations either way. The trap is real for a
    //    shape that reads the key's PRESENCE as "skip the guard", and the guard is not written that way.
    //    What these two halves do pin is the exemption's behaviour — that it works at all, and that the
    //    ordinary deploy payload does not accidentally exempt everything — and a mutation catches both.
    $exempt = runMaintenanceGuard(['except' => ['api/v1/health'], 'retry' => 15], [
        'REQUEST_URI' => '/api/v1/health',
        'HTTP_ACCEPT' => 'application/json',
    ]);

    expect($exempt['fell_through'])->toBeTrue();

    $notExempt = runMaintenanceGuard(['except' => ['api/v1/health'], 'retry' => 15], [
        'REQUEST_URI' => '/api/v1/forms',
        'HTTP_ACCEPT' => 'application/json',
    ]);

    expect($notExempt['fell_through'])->toBeFalse();
});

it('shapes its API envelope exactly as the app-side one does', function (): void {
    // The duplication this file exists to hold equal. `app/Support/Http/MaintenanceResponse` cannot be
    // reached from above the autoloader, so the envelope is written twice — and a duplicate a test holds
    // equal is a cost, while an undocumented one is the defect. Its own docblock warned a year ago that
    // a thrown HttpException becomes `server_error` here, which is exactly what was happening.
    $result = runMaintenanceGuard(GUARD_DOWN, [
        'REQUEST_URI' => '/api/v1/forms',
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $guardBody = (array) json_decode($result['body'], true);
    $message = (string) ($guardBody['error']['message'] ?? '');

    $appBody = (array) json_decode(
        (string) ApiErrorResponse::make(503, 'maintenance_mode', $message)->getContent(),
        true
    );

    expect($guardBody)->toBe($appBody);
});
