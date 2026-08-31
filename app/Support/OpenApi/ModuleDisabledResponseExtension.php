<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

/**
 * Document the 403 a `module:`-gated route can actually answer (M54).
 *
 * WHY SCRAMBLE CANNOT INFER THIS ON ITS OWN, read from the installed v0.13.30 rather than assumed:
 * `ErrorResponsesExtension::attachAuthorizationException()` adds a 403 **only** when the route's
 * gathered middleware starts with `can:` or `Authorize::class.':'`. `module:` is invisible to it. So
 * `/gamification/leaderboard` — which carries both `can:` and `module:` — documented its 403, while
 * `/gamification/me`, which is deliberately ungated per ADR-0020 §D7 and carries only `module:`,
 * documented `200` alone. Both can answer 403; only one said so.
 *
 * ⚠️ AND THE ANNOTATION ROUTE DOES NOT WORK HERE. The exception is thrown by `RequireModule`, which
 * the controller never mentions, so there is no `@throws` in the action for Scramble to read. The gate
 * lives on the route, so the documentation has to be derived from the route.
 *
 * ⛔ THIS IS GENERAL, NOT A PATCH ON ONE ROUTE. Any future `module:`-gated API route documents its 403
 * without anyone remembering to — which is the defect class the row was an instance of. Today the
 * `api/v1` surface has exactly two such routes; `routes/tenant.php` has three more, but those are WEB
 * routes and are outside the surface Scramble exports, so they cannot appear here.
 *
 * ⚠️ THE BODY IS THE `/api/v1` ERROR ENVELOPE, NOT LARAVEL'S DEFAULT. `bootstrap/app.php` renders this
 * exception through `ApiErrorResponse`, so the real payload is `{ error: { code, message } }` with the
 * stable code `module_disabled` — deliberately distinct from `feature_not_available`, because a plan
 * limit is a purchase and this is a workspace setting somebody in the tenant can simply switch back on.
 * ⛔ Scramble's own error components (`AuthorizationException` and its three siblings) document a bare
 * `{ message }`, which this surface does NOT return; that mismatch is real, is wider than this route,
 * and is filed as its own row rather than quietly widened into here.
 */
final class ModuleDisabledResponseExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $isModuleGated = collect($routeInfo->route->gatherMiddleware())
            ->contains(fn ($middleware) => is_string($middleware) && Str::startsWith($middleware, 'module:'));

        if (! $isModuleGated) {
            return;
        }

        // ⛔ NEVER OVERWRITE AN EXISTING 403, AND THIS IS THE GUARD THE CLAIM NAMED AS MOST LIKELY TO
        //    MATTER. `/gamification/leaderboard` carries `can:` AND `module:`, so Scramble has already
        //    given it a 403 by the time this runs. Appending unconditionally would emit the status
        //    twice and change a route this row is not about — which is how a documentation fix becomes
        //    an unreviewed contract change.
        if ($this->documentsForbidden($operation)) {
            return;
        }

        $body = (new OpenApiTypes\ObjectType)
            ->addProperty(
                'error',
                (new OpenApiTypes\ObjectType)
                    ->addProperty('code', (new OpenApiTypes\StringType)->setDescription('Stable machine-readable code: `module_disabled`.'))
                    ->addProperty('message', (new OpenApiTypes\StringType)->setDescription('Error overview.'))
                    ->setRequired(['code', 'message'])
            )
            ->setRequired(['error']);

        $operation->addResponse(
            Response::make(403)
                ->setDescription('The workspace has switched this module off. Not a plan limit: nothing needs buying, and an administrator inside the workspace can re-enable it.')
                ->setContent('application/json', Schema::fromType($body))
        );
    }

    /**
     * A 403 may already be present either inline or behind a `$ref` to a shared component, and the two
     * are not interchangeable to read — a Reference carries no code until it is resolved.
     */
    private function documentsForbidden(Operation $operation): bool
    {
        foreach ($operation->responses ?? [] as $response) {
            $code = $response->code ?? null;

            if ($code === null && method_exists($response, 'resolve')) {
                $resolved = $response->resolve();
                $code = is_object($resolved) ? ($resolved->code ?? null) : null;
            }

            if ((int) $code === 403) {
                return true;
            }
        }

        return false;
    }
}
