<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Services\Gamification\Scoreboard;
use Illuminate\Http\Request;

/**
 * The named workspace ladder plus the workspace's own totals (K1d; ADR-0020 §D7, §D11).
 *
 * The GATED half of §D7's split — this is the only gamification payload that carries another member's name,
 * which is why its route pairs `ability:read:gamification` with `can:viewAny,PointAward` and the ungated
 * `me` route does not. Nothing may be added to `entries` without re-reading what `dashboard.org.view` is
 * thereby being made to disclose about a colleague.
 *
 * ⚠️ **`team` AND `entries` ARE NOT TWO VIEWS OF ONE NUMBER.** {@see Scoreboard} enumerates the three places
 * they deliberately disagree — guest submissions, and departed members in two forms. The endpoint's own
 * description repeats it, because the reader who needs it most is an integrator who will never open this
 * file.
 *
 * @mixin Scoreboard
 */
final class ScoreboardResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'entries' => LeaderboardEntryResource::collection($this->ladder->entries),
            // The ladder's own size — the denominator in "4th of 12" — so a client rendering a standing
            // needs only this object. ACTIVE members, including those who have earned nothing and are
            // ranked last rather than omitted.
            //
            // ⚠️ IT IS `<= team.active_members`, NOT EQUAL TO IT, AND THE INEQUALITY IS THE HONEST FORM.
            // `active_members` counts `tenant_users` rows with an active status and nothing else, while
            // this counts the ones whose IDENTITY also resolves — the roster read carries no
            // `withTrashed()`, so a soft-deleted account holding a live membership is a member of the
            // workspace and not a row on the ladder. They agree on every tenant that has never
            // deactivated an account, which is exactly what would make "equal by construction" a
            // comfortable thing to write and a false thing to rely on.
            'member_count' => $this->ladder->memberCount,
            'team' => [
                // Every point in the tenant's ledger, INCLUDING members who have since left. See the note.
                'points' => $this->team->points,
                // Every finalized submission, INCLUDING guest ones that credited nobody. See the note.
                'responses' => $this->team->responses,
                'published_forms' => $this->team->publishedForms,
                'active_members' => $this->team->activeMembers,
                'badges' => $this->team->badges,
                // Members who have earned anything at all — again including departures.
                'contributors' => $this->team->contributors,
            ],
        ];
    }
}
