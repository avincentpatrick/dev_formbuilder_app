<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * The notification centre's JSON sidecar (Increment I4, PRD Feature #13b) — a thin adapter, with every
 * prop decision in {@see NotificationPresenter}. See the route block in `routes/tenant.php` for why this
 * surface is JSON rather than Inertia, on both the read and the two writes.
 *
 * ⚠️ **`Notification::scopeForUser()` IS THE AUTHORIZATION ON EVERY METHOD HERE.** `notifications` is
 * `strict` RLS, which scopes to the TENANT and stops there, so an unscoped query returns every member's
 * rows and an unscoped UPDATE writes them. The read applies it inside the presenter; {@see self::readAll()}
 * applies it inline below; {@see self::read()} delegates to `NotificationPolicy::markRead` on the bound
 * instance. Removing any of the three is a cross-user leak or a cross-user write that no test outside
 * `tests/Feature/Notifications/` would notice.
 */
final class NotificationController extends Controller
{
    public function __construct(private readonly NotificationPresenter $presenter) {}

    /** The bell's polled payload: the true unread count plus the latest page of rows. */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->presenter->summary($user));
    }

    /**
     * Mark ONE row read. Authorized by `can:markRead,notification` on the route.
     *
     * The already-read guard is not decoration: the bell writes optimistically, so a double click (or a
     * click on a row a second tab has already read) arrives here twice, and without it the second call
     * would move `read_at` and churn `updated_at` on a table something polls every sixty seconds.
     */
    public function read(Notification $notification): Response
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => Carbon::now()])->save();
        }

        return response()->noContent();
    }

    /**
     * Mark every unread row of MINE read.
     *
     * No `can:` gate, and the asymmetry with {@see self::read()} is deliberate: there is no instance to
     * authorize, and a policy method that returned true for every authenticated user would authorize
     * nothing. The `forUser()` predicate in this UPDATE's WHERE clause is the whole of the authorization —
     * drop it and an Owner silently clears every member of the tenant's inbox, unaudited (`notifications`
     * is deliberately outside the audit ledger, audit-spec §1). `NotificationReadTest` pins it from both
     * sides: mine go to zero, a colleague's stay unread.
     */
    public function readAll(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        Notification::query()->forUser($user)->unread()->update(['read_at' => Carbon::now()]);

        return response()->noContent();
    }
}
