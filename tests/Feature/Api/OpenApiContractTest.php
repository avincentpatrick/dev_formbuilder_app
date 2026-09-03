<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 | Increment E — a cheap in-suite guard on the committed OpenAPI contract (openapi.json). The CI
 | `contract-tests` job does the full Redocly validation + drift-diff against a fresh Scramble export;
 | this asserts the essentials inside the normal test run so a corrupted/forgotten spec fails fast. No DB.
 */

it('ships a valid OpenAPI 3.1 contract covering the /api/v1 surface', function (): void {
    $path = base_path('openapi.json');
    expect(file_exists($path))->toBeTrue();

    /** @var array<string, mixed> $spec */
    $spec = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($spec['openapi'])->toBe('3.1.0')
        ->and($spec['info']['title'])->toBe('Form-Builder SaaS API')
        ->and($spec['components']['securitySchemes'])->toHaveKey('sanctumToken')
        ->and($spec['security'])->toBe([['sanctumToken' => []]]);

    expect(array_keys($spec['paths']))->toContain(
        '/forms',
        '/forms/{form}',
        '/forms/{form}/versions',
        '/forms/{form}/versions/{version}',
        '/forms/{form}/versions/{version}/publish',
        '/auth/tokens',
        '/auth/tokens/{id}',
        '/tenant',
        // Increment F5 — the public guest runtime surface.
        '/public/f/{shareToken}',
        '/public/f/{shareToken}/submissions',
        // Increment H9b — the save-and-resume surface (guest draft upsert + resume-read + encoder promote).
        '/public/f/{shareToken}/draft',
        '/public/drafts/{resumeToken}',
        '/submissions/{submission}/promote',
        // Increment G8b — the authenticated offline-sync surface.
        '/sync/manifest',
        '/sync/submissions',
        // Increment G9a/G9b — the reusable-content surfaces (templates + question library).
        '/form-templates',
        '/field-library',
        // Increment G10b — the scoping hierarchy + the grant write surface.
        '/scopes',
        '/scopes/{scopeNode}',
        '/scopes/{scopeNode}/move',
        '/scopes/{scopeNode}/impact',
        '/resource-grants',
        '/resource-grants/{resourceGrant}',
        // H4 — the read-only audit-log surface.
        '/audits',
        // H13a — the webhook engine management + delivery-log surface.
        '/webhooks',
        '/webhooks/{webhookEndpoint}',
        '/webhooks/{webhookEndpoint}/deliveries',
        // H13b — send-test, dual-secret rotation, and manual redeliver.
        '/webhooks/{webhookEndpoint}/test',
        '/webhooks/{webhookEndpoint}/rotate-secret',
        '/webhooks/{webhookEndpoint}/deliveries/{webhookDelivery}/redeliver',
        // H15a — native connectors: the OAuth grants (read + disconnect only; a grant can be created ONLY
        // by the interactive flow) and the delivery rules on them, with the shared delivery log.
        '/connections',
        '/connections/{connection}',
        '/connections/{connection}/subscriptions',
        '/connections/{connection}/subscriptions/{subscription}',
        '/connections/{connection}/subscriptions/{subscription}/deliveries',
        // H22a — custom domains: claim, list, on-demand verify, release. H22b adds /primary.
        '/domains',
        '/domains/{domain}',
        '/domains/{domain}/verify',
        '/domains/{domain}/primary',
        // K1d — gamification: a member's own standing (ungated) and the NAMED ladder
        // (`dashboard.org.view`). ADR-0020 §D7 mints no permission for the split; the two paths differ
        // only in that the second carries a `can:` gate, so both are listed to make the pair visible.
        '/gamification/me',
        '/gamification/leaderboard',
    );

    // K1d — THE GAMIFICATION SURFACE IS READ-ONLY, AND THE ABSENCE IS THE ARCHITECTURE RATHER THAN AN
    // UNFINISHED CRUD SET. ADR-0020 §D2 consumes signals that already exist and mints no subscribable
    // vocabulary; §D4 makes `point_awards` append-only under RLS, with no UPDATE or DELETE policy at
    // all. A write endpoint here would be a way to award points by hand — the one thing a ledger
    // nobody can rewrite exists to prevent — so assert it is not there, since a resource-route reflex
    // would add it without anyone noticing what it meant.
    expect($spec['paths']['/gamification/me'])->not->toHaveKey('post')
        ->and($spec['paths']['/gamification/leaderboard'])->not->toHaveKey('post')
        ->and($spec['paths']['/gamification/leaderboard'])->not->toHaveKey('delete');

    // The connector surface deliberately exposes no create/update for a grant: a credential may only arrive
    // through the OAuth flow, and an API that accepted one would be a path to writing a token we then act
    // with. Assert the ABSENCE, since a future resource-route reflex would silently add both.
    expect($spec['paths']['/connections'])->not->toHaveKey('post')
        ->and($spec['paths']['/connections/{connection}'])->not->toHaveKey('patch')
        ->and($spec['paths']['/connections/{connection}'])->not->toHaveKey('put');

    // H22a — the same shape of deliberate absence, twice over, both worth asserting because a resource-route
    // reflex would add them without anyone noticing what they mean:
    //
    //  · A DOMAIN IS IMMUTABLE. Editing the hostname would silently invalidate the verification that was
    //    granted for the old one, so there is no patch/put — you release and re-claim.
    //  · THERE IS NO ACTIVATE ENDPOINT ANYWHERE ON THE API. Putting a verified domain into service is
    //    `php artisan domains:activate`, run by whoever installed the TLS certificate. Per-domain TLS is
    //    structurally Track B and Track B is deferred, so a tenant able to activate its own domain could
    //    put its respondents on an origin with no certificate for it (ADR-0012).
    //
    // H22b's `/domains/{domain}/primary` is NOT that endpoint arriving under another name, and the reason
    // it is safe to add beside this assertion is mechanical rather than editorial: it refuses any domain
    // that is not ALREADY live, and `domains_primary_requires_live_chk` refuses it again underneath. It
    // chooses among hosts an operator activated; it cannot produce one.
    expect($spec['paths']['/domains/{domain}'])->not->toHaveKey('patch')
        ->and($spec['paths']['/domains/{domain}'])->not->toHaveKey('put')
        ->and(array_keys($spec['paths']))->not->toContain('/domains/{domain}/activate');

    // The guest endpoints are unauthenticated (@unauthenticated → security: []), overriding the global
    // sanctumToken requirement — a Sanctum bearer must never be advertised on the public share-token routes.
    expect($spec['paths']['/public/f/{shareToken}']['get']['security'])->toBe([])
        ->and($spec['paths']['/public/f/{shareToken}/submissions']['post']['security'])->toBe([])
        // H9b: the guest draft-save + resume-read are equally unauthenticated (token, not bearer).
        ->and($spec['paths']['/public/f/{shareToken}/draft']['post']['security'])->toBe([])
        ->and($spec['paths']['/public/drafts/{resumeToken}']['get']['security'])->toBe([]);
});

it('keeps the published event_types enum in step with DomainEventType', function (): void {
    // ⚠️ WRITTEN AFTER I9c SHIPPED THE SPEC STALE. The increment added `submission.updated` to the enum, the
    // CHECK constraint, the labels and the connector arms — and did not regenerate `openapi.json`, so four
    // `event_types` enums still advertised seven values while eight FormRequests accepted eight. A generated
    // client would have rejected a value the API takes. The `contract-tests` CI job compares a fresh export
    // byte-for-byte and would have caught it there; this catches it in the ordinary suite, which is where
    // the person who added the case is actually looking.
    /** @var array<string, mixed> $spec */
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    $expected = DomainEventType::values();
    sort($expected);

    // Locate every enum that carries the catalog and assert it carries ALL of it.
    $enums = [];
    $walk = static function (array $node) use (&$walk, &$enums): void {
        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if ($key === 'enum' && in_array('submission.created', $value, true)) {
                $enums[] = $value;
            }
            $walk($value);
        }
    };
    $walk($spec);

    expect($enums)->not->toBeEmpty('no event_types enum found in openapi.json');

    foreach ($enums as $enum) {
        $actual = $enum;
        sort($actual);
        expect($actual)->toBe($expected);
    }
});

it('documents the /api/v1 error envelope on every error response it publishes', function (): void {
    // ⛔ M56. THE DOCUMENT USED TO PROMISE A BODY THE SURFACE HAS NEVER RETURNED. Scramble's five default
    // exception extensions all describe Laravel's `{ message }`; `bootstrap/app.php` renders every
    // /api/v1 error through ApiErrorResponse's `{ error: { code, message, details? } }` (§2.3), with a
    // final Throwable arm so nothing escapes it. Four shared components (113 $refs across 68 operations)
    // and three inline `abort()` bodies were wrong in exactly the same way. A client generated from the
    // contract looked for a top-level field that never arrives.
    //
    // ⚠️ THIS IS A CENSUS, NOT A CHECK ON THE SEVEN KNOWN CASES — deliberately. The backlog row named
    // only `components.responses`, and scoping a gate to what the row named would have left the three
    // inline bodies wrong and unwatched, since no component references them. Anything published as an
    // error, by any mechanism, is asserted here.
    //
    // ⚠️ AND IT DESCENDS anyOf/oneOf/allOf. `403 POST /public/f/{shareToken}/draft` is a two-branch
    // union with no top-level `properties` at all; both branches are correct envelopes. A naive
    // "top-level properties must be exactly [error]" rule calls that a defect, which is how a gate
    // starts costing more than it catches.
    /** @var array<string, mixed> $spec */
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    $describesEnvelope = function (array $schema) use (&$describesEnvelope): bool {
        foreach (['anyOf', 'oneOf', 'allOf'] as $combinator) {
            if (isset($schema[$combinator]) && is_array($schema[$combinator])) {
                foreach ($schema[$combinator] as $branch) {
                    if (! is_array($branch) || ! $describesEnvelope($branch)) {
                        return false;
                    }
                }

                return $schema[$combinator] !== [];
            }
        }

        if (array_keys($schema['properties'] ?? []) !== ['error']) {
            return false;
        }

        $error = $schema['properties']['error'];
        $properties = array_keys($error['properties'] ?? []);
        $required = $error['required'] ?? [];

        // ⚠️ `code` and `message` are always present and always required; `details` is optional on most
        // statuses and REQUIRED on the 422, whose every cause sends a field map. So this asks that the
        // two guaranteed keys are required — not that the required set is exactly those two, which is
        // what the first draft of this gate asked, and what it went red on. The gate was wrong, not the
        // document, and it is recorded here because a gate corrected by loosening is the one worth
        // explaining.
        return in_array('code', $properties, true)
            && in_array('message', $properties, true)
            && in_array('code', $required, true)
            && in_array('message', $required, true);
    };

    // Every shared error component, plus every INLINE >= 400 body. A `$ref` is skipped because the
    // component it points at is checked on its own — checking it again per referrer would report the
    // same defect 113 times.
    $published = [];

    foreach ($spec['components']['responses'] ?? [] as $name => $response) {
        $published["components.responses.{$name}"] = $response;
    }

    foreach ($spec['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            if (! is_array($operation) || ! isset($operation['responses'])) {
                continue;
            }

            foreach ($operation['responses'] as $status => $response) {
                if (isset($response['$ref']) || (int) $status < 400) {
                    continue;
                }

                $published[strtoupper((string) $method)." {$path} → {$status}"] = $response;
            }
        }
    }

    // ⛔ A FLOOR, BECAUSE A WALK THAT MATCHES NOTHING PASSES. If a future refactor moves error responses
    // somewhere this traversal does not reach, the loop below runs zero times and reports success — the
    // failure mode CLAUDE.md records for every gate that scans a tree. 16 is what exists today (4
    // components + 12 inline); the assertion is `>=` so adding a route cannot turn it red.
    expect(count($published))->toBeGreaterThanOrEqual(16);

    foreach ($published as $where => $response) {
        $schema = $response['content']['application/json']['schema'] ?? null;

        expect($schema)->toBeArray("{$where}: publishes no application/json schema");
        expect($describesEnvelope($schema))->toBeTrue(
            "{$where}: does not document { error: { code, message } } — see api-specification.md §2.3"
        );
    }
});

it('documents the 409 the promote route can actually answer, and names its causes', function (): void {
    // ⛔ M67. `openapi.json` published `200 / 403 / 404` for this operation while THREE of its refusals
    // were normal outcomes, because Scramble infers from the CONTROLLER and every one of them is thrown
    // by `SubmissionDraftService::promote()` a frame down. M12 could add a fourth cause with the document
    // staying byte-identical — which is the property that made this invisible rather than merely wrong.
    //
    // ⚠️ THE STATUS AND THE CODES ARE ASSERTED SEPARATELY AND ON PURPOSE. A 409 documented as "a string"
    // tells an integrator the status exists and nothing they can branch on, and `error.code` is the only
    // thing on this surface that separates a re-readable draft conflict from a superseded version. Both
    // arms are mutated independently — see the release for which mutation caught which.
    /** @var array<string, mixed> $spec */
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    $operation = $spec['paths']['/submissions/{submission}/promote']['post'] ?? null;

    expect($operation)->toBeArray('the promote operation is missing from the contract entirely');

    // ⚠️ `array_map('strval', …)` IS LOAD-BEARING, NOT TIDINESS. `json_decode` turns a numeric object key
    // into an INT, so `["200","403","409"]` arrives as `[200, 403, 409]` and a strict `toContain('409')`
    // fails against a document that is perfectly correct. This gate went red that way before it went red
    // for the right reason.
    expect(array_map('strval', array_keys($operation['responses'] ?? [])))->toContain('409');

    // The whole 409 sub-document, flattened: the two exception families merge into one response with an
    // `anyOf`, so a search over `properties` alone reads only the first branch and would pass with the
    // second silently gone.
    $documented = json_encode($operation['responses']['409'], JSON_THROW_ON_ERROR);

    foreach (['draft_conflict', 'submission_version_superseded'] as $code) {
        expect($documented)->toContain($code);
    }
});

it('documents a 409 on every /api/v1 action that declares it can throw one', function (): void {
    // ⚠️ THE SWEEP RATHER THAN THE ONE ROUTE, because the row was an instance and not the class: the
    // annotation is the ONLY signal Scramble has for a service-thrown refusal, so the next action to
    // declare one documents its 409 without anyone remembering to — or fails here.
    //
    // ⛔ AND THE FLOOR IS THE ARM THAT MATTERS. Deleting the `@throws` tags empties this walk, and a loop
    // over nothing reports success — the exact shape CLAUDE.md records for every gate that scans a tree.
    // The floor is what turns that deletion red, and it is the mutation that proved this test is not
    // decorative. `>=` so adding a route cannot redden it.
    /** @var array<string, mixed> $spec */
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    // Both are rendered as a 409 by bootstrap/app.php's /api/v1 arm; neither is an HttpException, so
    // nothing else in the Scramble pipeline claims them.
    $conflictExceptions = [
        SubmissionConflictException::class,
        SubmissionException::class,
    ];

    $declaring = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        if (! is_string($name) || ! str_starts_with($name, 'api.v1.')) {
            continue;
        }

        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            continue;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            continue;
        }

        $doc = (string) (new ReflectionMethod($class, $method))->getDocComment();

        // Matched on the SHORT name, which is what both spellings end in: an imported `@throws Foo` and a
        // fully-qualified `@throws \App\Exceptions\Submissions\Foo` both contain it, so one arm covers
        // both and no backslash needs writing here.
        $throwsConflict = array_filter(
            $conflictExceptions,
            fn (string $exception): bool => str_contains($doc, '@throws '.class_basename($exception))
        );

        if ($throwsConflict === []) {
            continue;
        }

        // `api/v1/submissions/{submission}/promote` → `/submissions/{submission}/promote`: the document's
        // paths are relative to the server URL, which already carries the prefix.
        $declaring['/'.ltrim(Str::after($route->uri(), 'api/v1'), '/')]
            = strtolower((string) collect($route->methods())->first(fn (string $verb): bool => $verb !== 'HEAD'));
    }

    expect(count($declaring))->toBeGreaterThanOrEqual(
        1,
        'no /api/v1 action declares @throws for a submission refusal — the walk matched nothing, so every assertion below is vacuous'
    );

    foreach ($declaring as $path => $verb) {
        $statuses = array_map('strval', array_keys($spec['paths'][$path][$verb]['responses'] ?? []));

        // ⛔ NOT `toContain('409', $why)`. That expectation takes VARIADIC NEEDLES, so the explanation
        // would become a second thing the array must contain — the M30 trap, which kept a case green with
        // the wrong value in it. The message belongs on an expectation whose second argument IS a message.
        expect(in_array('409', $statuses, true))->toBeTrue(
            strtoupper($verb)." {$path} declares it can throw a submission refusal but documents no 409 — add the exception to the @throws block, then re-export openapi.json"
        );
    }
});
