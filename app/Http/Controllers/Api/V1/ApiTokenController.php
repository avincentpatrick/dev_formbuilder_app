<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreApiTokenRequest;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Api\ApiAbilities;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API-key issuance for the current tenant (Increment E) — the session-authenticated mint/list/revoke
 * surface. Backend-only for now; a styled Settings → API Keys page is a Phase-1 fast-follow. The key's
 * abilities are intersected against the issuer's RBAC ({@see ApiAbilities::intersect()}) so a key is
 * never broader than its issuer, and the custom {@see PersonalAccessToken} model auto-fills tenant_id so
 * the strict RLS on personal_access_tokens accepts the mint. The plaintext secret is returned only once.
 */
final class ApiTokenController extends Controller
{
    /**
     * Mint a new API key (a tenant-scoped Sanctum personal access token). Returns the secret exactly once.
     */
    public function store(StoreApiTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var list<string> $requested */
        $requested = $request->validated('abilities');
        $abilities = ApiAbilities::intersect($user, $requested);

        $expiresAt = $request->date('expires_at');

        $token = $user->createToken($request->string('name')->toString(), $abilities, $expiresAt);

        return response()->json([
            'id' => $token->accessToken->getKey(),
            'name' => $request->string('name')->toString(),
            'abilities' => $abilities,
            'token' => $token->plainTextToken,
            'expires_at' => $this->iso($expiresAt),
        ], 201);
    }

    /**
     * List the caller's API keys for this tenant (the secret is never returned).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Collection<int, PersonalAccessToken> $rows */
        $rows = $user->tokens()->orderByDesc('created_at')->get();

        $tokens = [];
        foreach ($rows as $token) {
            $tokens[] = [
                'id' => $token->getKey(),
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $this->iso($token->last_used_at),
                'expires_at' => $this->iso($token->expires_at),
                'created_at' => $this->iso($token->created_at),
            ];
        }

        return response()->json(['data' => $tokens]);
    }

    /**
     * Revoke one of the caller's API keys.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->tokens()->whereKey($id)->delete();

        return response()->json(null, 204);
    }

    /**
     * An ISO-8601 timestamp, or null — typed `?string` so the generated OpenAPI marks these fields nullable.
     */
    private function iso(?CarbonInterface $at): ?string
    {
        return $at?->toIso8601String();
    }
}
