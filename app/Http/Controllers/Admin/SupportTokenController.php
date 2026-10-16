<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformApiToken;
use App\Models\User;
use App\Services\Admin\PlatformSupportTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Issues and revokes platform support tokens from the console's Settings page (M175, `R-78f43f26`, `D139`;
 * ADR-0023). Thin: the rules — the prefix, the hash, the 90-day cap, the audit rows — are
 * {@see PlatformSupportTokenService}'s.
 *
 * The plaintext leaves the server exactly once, in the `newSupportToken` session flash the card reveals, and is
 * never a page prop. Behind `auth` + `superadmin` + `superadmin.mfa` + `step-up` with the rest of the console.
 */
final class SupportTokenController extends Controller
{
    public function __construct(private readonly PlatformSupportTokenService $tokens) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'expires_in_days' => ['required', 'integer', Rule::in(PlatformSupportTokenService::ALLOWED_DAYS)],
        ]);

        /** @var User $owner */
        $owner = $request->user();

        $issued = $this->tokens->issue($owner, (string) $validated['name'], (int) $validated['expires_in_days']);

        return back()
            ->with('newSupportToken', ['token' => $issued['plain'], 'name' => $issued['token']->name])
            ->with('toast', ['type' => 'success', 'message' => 'Support token created — copy it now']);
    }

    public function destroy(Request $request, string $token): RedirectResponse
    {
        /** @var PlatformApiToken|null $row */
        $row = PlatformApiToken::query()->find($token);
        abort_if($row === null, 404);

        /** @var User $actor */
        $actor = $request->user();

        $this->tokens->revoke($row, $actor);

        return back()->with('toast', ['type' => 'success', 'message' => 'Support token revoked']);
    }
}
