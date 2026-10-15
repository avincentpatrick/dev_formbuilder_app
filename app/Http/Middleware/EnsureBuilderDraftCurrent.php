<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Form;
use App\Models\FormVersion;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M172 (`R-81756e95`) — a builder write is refused, BEFORE any FormRequest validates it, when the page that sent it
 * was loaded against a draft the form has since moved past.
 *
 * A publish clones the draft into a new one with NEW ids and the SAME keys. A tab left open across it — a publish from
 * the hub, another tab, the API, or the browser's Back button — still holds the old ids, so its next save reached
 * `UpdateFieldRequest`'s key rule with an unchanged key, found the clone, and was refused "The key has already been
 * taken"; a changed key then met the service's draft guard and a 422 no Retry could ever clear. The testers met both.
 *
 * Every builder write carries the draft the page was loaded with in {@see self::HEADER}. A request without it is
 * refused 428 (the builder always sends it — an optional header would let a client that omits it keep the old
 * behaviour); one naming another draft is refused 409 `draft_superseded` with the draft to reload into, and the
 * builder reloads and replays its unsaved edits by key. The service's own draft guard answers the same 409 through
 * {@see self::superseded()} for the race this check cannot see (a publish committing after it ran).
 *
 * ⚠️ A ROUTE middleware, not a FormRequest hook: five builder writes take no FormRequest at all, and a check in a
 * controller body runs after the injected FormRequest has already validated.
 */
final class EnsureBuilderDraftCurrent
{
    public const HEADER = 'X-Builder-Draft';

    public const SUPERSEDED_MESSAGE = 'This form was published since this page loaded. Reload to keep editing.';

    public function handle(Request $request, Closure $next): Response
    {
        $form = $request->route('form');

        if (! $form instanceof Form) {
            return $next($request);
        }

        $sent = (string) $request->header(self::HEADER, '');

        if ($sent === '') {
            return response()->json([
                'code' => 'draft_required',
                'message' => 'This page is out of date. Reload it to keep editing.',
            ], 428);
        }

        if ($sent !== $form->draft_version_id) {
            return self::superseded($form);
        }

        return $next($request);
    }

    /**
     * The 409 a stale page reloads from: the draft to reload into, read fresh — in the race the bound model predates
     * the publish — or null when the form has no draft left to edit (the reload then shows the read-only builder).
     */
    public static function superseded(Form $form): JsonResponse
    {
        $draftId = Form::query()->whereKey($form->id)->value('draft_version_id');
        $draft = is_string($draftId) ? FormVersion::query()->whereKey($draftId)->first(['id', 'version_number']) : null;

        return response()->json([
            'code' => 'draft_superseded',
            'message' => self::SUPERSEDED_MESSAGE,
            'draft' => $draft === null ? null : ['id' => $draft->id, 'version_number' => $draft->version_number],
        ], 409);
    }
}
