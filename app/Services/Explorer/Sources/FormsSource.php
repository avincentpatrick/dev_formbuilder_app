<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\User;
use App\Support\Explorer\ExplorerQuery;
use Illuminate\Database\Query\Builder;

/**
 * The forms themselves, one row each (ADR-0025 §D5) — how many, in what state, owned by whom.
 *
 * Opens on the longest range the explorer allows: a form list is a stock, not a flow, and "forms created in
 * the last ninety days" would be a surprising default answer to "how many forms do we have".
 */
final class FormsSource extends AbstractExplorerSource
{
    public function __construct(private readonly ExplorerFormReach $reach) {}

    public function key(): string
    {
        return 'forms';
    }

    public function label(): string
    {
        return 'Forms';
    }

    public function description(): string
    {
        return 'The forms you can open, one row each: their status, owner, folder and when they were made.';
    }

    public function rowNoun(): string
    {
        return 'forms';
    }

    public function defaultRangeDays(): int
    {
        return ExplorerQuery::MAX_RANGE_DAYS;
    }

    public function allows(User $user): bool
    {
        return true;
    }

    public function rangeField(): string
    {
        return 'created_at';
    }

    public function fields(User $user, ?Form $form): array
    {
        return [
            $this->enumField('status', 'Status', 'forms.status', FormStatus::class, 'Form'),
            $this->category('owner', 'Owner', 'forms.owner_user_id', 'Form', lookup: 'users'),
            $this->category('folder', 'Folder', 'forms.folder_id', 'Form', lookup: 'folders'),
            $this->category('language', 'Main language', 'forms.default_locale', 'Form'),
            $this->boolean('guest', 'Open to the public', 'forms.allow_guest_submissions', 'Form'),
            $this->time('created_at', 'Created', 'forms.created_at', 'Dates'),
            $this->time('published_at', 'First published', 'forms.published_at', 'Dates'),
        ];
    }

    public function query(User $user, ?Form $form): Builder
    {
        // `Form::query()`, not `withTrashed()`: a deleted form is gone from the forms list, and this source
        // is that list.
        $query = Form::query();

        if (! $this->reach->orgWide($user)) {
            $query->whereIn('forms.id', $this->reach->formIds($user));
        }

        return $query->toBase();
    }
}
