<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Services\Gamification\MemberProgress;
use App\Services\Gamification\MemberStreak;
use Illuminate\Http\Request;

/**
 * The caller's own gamification progress (K1d; ADR-0020 §D7, gamification-design.md §10).
 *
 * The ungated half of §D7's split: points, badges, streak and position, and **no other member's name or
 * number**. `rank` is nullable because a caller who is not an active member of the workspace has no
 * position on the ladder at all — a state the type models explicitly rather than reporting as `0`, which
 * would render as a place.
 *
 * `current` and `longest` are both emitted and they are not the same measurement:
 * {@see MemberStreak} records that `current` decays to zero after a missed day
 * while `longest` is a high-water mark that only rises, so a client showing one and labelling it the other
 * tells somebody they have lost an achievement they still hold.
 *
 * @mixin MemberProgress
 */
final class MemberProgressResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'points' => $this->standing->points,
            'badges' => $this->standing->badges,
            'standing' => [
                // Competition rank: one plus the number of members strictly ahead, so a tie skips the next
                // place. Null when the caller holds no active membership here.
                'rank' => $this->standing->rank,
                // Active members, NOT the number of people who have scored — see MemberStanding.
                'of' => $this->standing->of,
            ],
            'streak' => [
                'current' => $this->streak->current,
                'longest' => $this->streak->longest,
                'last_active_on' => $this->iso($this->streak->lastActiveOn),
            ],
        ];
    }
}
