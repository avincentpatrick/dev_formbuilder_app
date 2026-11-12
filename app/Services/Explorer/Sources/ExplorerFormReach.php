<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Models\Form;
use App\Models\User;
use App\Services\Analytics\AnalyticsFormSet;
use App\Services\Authorization\ResourceGrantResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which forms a reader's explorer charts may draw on (ADR-0025 §D5).
 *
 * The same split {@see AnalyticsFormSet} makes, restated rather than reused because that
 * class resolves an `AnalyticsQuery`'s selection and this surface has none: a holder of `dashboard.org.view`
 * reaches every form, and anyone else reaches the forms they hold a grant on. Never the empty-means-all
 * shape — `grantedFormIdsQuery()` returns a query matching nothing for a member with no grants.
 */
final class ExplorerFormReach
{
    public function __construct(private readonly ResourceGrantResolver $grants) {}

    public function orgWide(User $user): bool
    {
        return $user->can('dashboard.org.view');
    }

    /**
     * Ids of the forms this reader may chart, soft-deleted ones included: a deleted form's responses were
     * still collected, and `/analytics` counts them too.
     *
     * @return Builder<Form>
     */
    public function formIds(User $user): Builder
    {
        return $this->orgWide($user)
            ? Form::withTrashed()->select('forms.id')
            : $this->grants->grantedFormIdsQuery($user);
    }

    /** One form this reader may chart the ANSWERS of — a live form they can reach — or null. */
    public function form(User $user, string $formId): ?Form
    {
        return Form::query()
            ->whereKey($formId)
            ->whereIn('forms.id', $this->formIds($user))
            ->first();
    }
}
