<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Services\Gamification\LeaderboardEntry;
use Illuminate\Http\Request;

/**
 * One named row of the workspace ladder (K1d; ADR-0020 §D7).
 *
 * Its own resource rather than an inline `array_map` inside {@see ScoreboardResource}, for a reason that is
 * about the published contract rather than about tidiness: a closure returning an array literal gives
 * Scramble nothing to infer, so `entries` generated as `{"type": "array", "items": {}}` — an untyped bag for
 * the single most important field on the endpoint. A resource emits a named component and a `$ref`.
 *
 * ⚠️ **THIS IS THE ONLY GAMIFICATION PAYLOAD THAT NAMES SOMEBODY OTHER THAN THE CALLER**, which is what
 * makes its container the `dashboard.org.view`-gated one. A field added here widens what that permission
 * discloses about a colleague and should be read as that rather than as a convenience for a template.
 *
 * @mixin LeaderboardEntry
 */
final class LeaderboardEntryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Competition rank — ties share a place and the next one skips it.
            'rank' => $this->rank,
            'user_id' => $this->userId,
            'name' => $this->name,
            'points' => $this->points,
            'badges' => $this->badges,
        ];
    }
}
