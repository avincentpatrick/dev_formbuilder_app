<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Platform\WhatsNew;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What's new — M181 (`R-c7247dbf`, `D125` C, `D126` A).
 *
 * `index`: every release, newest first, for every signed-in member — no gate, like `/help`: what changed is the same
 * news for every role. `seen`: the prompt's any-close; it moves `users.whats_new_seen_version` forward only, and it
 * lives in the tenant group because `users` is FORCE RLS and only there is the request's own user GUC set.
 */
final class WhatsNewController extends Controller
{
    public function __construct(private readonly WhatsNew $whatsNew) {}

    public function index(): Response
    {
        return Inertia::render('whats-new/Index', [
            'releases' => $this->whatsNew->all(),
        ]);
    }

    public function seen(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:32', Rule::exists('releases', 'version')],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->whatsNew->markSeen($user, $validated['version']);

        return back();
    }
}
