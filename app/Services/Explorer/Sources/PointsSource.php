<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Enums\PointRule;
use App\Models\Form;
use App\Models\PointAward;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Database\Query\Builder;

/**
 * Points awarded to members, one row per award (ADR-0025 §D5).
 *
 * Gated as the workspace leaderboard is — `PointAwardPolicy::viewAny`, which is `dashboard.org.view` — and
 * hidden with the rest of achievements when that module is switched off.
 */
final class PointsSource extends AbstractExplorerSource
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function key(): string
    {
        return 'points';
    }

    public function label(): string
    {
        return 'Points';
    }

    public function description(): string
    {
        return 'Points members earned: who earned them, for what, and when.';
    }

    public function rowNoun(): string
    {
        return 'awards';
    }

    public function allows(User $user): bool
    {
        return $user->can('viewAny', PointAward::class)
            && ($this->entitlements->currentPlan() === null || $this->entitlements->feature('gamification'));
    }

    public function rangeField(): string
    {
        return 'awarded_at';
    }

    public function fields(User $user, ?Form $form): array
    {
        return [
            $this->enumField('rule', 'Earned for', 'point_awards.rule', PointRule::class, 'Award'),
            $this->category('member', 'Member', 'point_awards.user_id', 'Award', lookup: 'users'),
            $this->time('awarded_at', 'Awarded', 'point_awards.awarded_at', 'Dates'),
            $this->number('points', 'Points', 'point_awards.points', 'Numbers'),
        ];
    }

    public function query(User $user, ?Form $form): Builder
    {
        return PointAward::query()->toBase();
    }
}
