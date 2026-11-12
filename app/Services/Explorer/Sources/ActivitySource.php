<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Enums\AuditEvent;
use App\Enums\ExplorerFieldType;
use App\Models\Audit;
use App\Models\Form;
use App\Models\User;
use App\Support\Audit\AuditableTypes;
use App\Support\Explorer\ExplorerField;
use Illuminate\Database\Query\Builder;

/**
 * The audit trail, one row per recorded action (ADR-0025 §D5).
 *
 * Gated on `audit_log.view`, as `/audit` is. Only what an entry IS can be charted — the action, what it was
 * done to, who did it, when. `old_values` and `new_values` are not fields here: they hold the changed data
 * itself, and a chart has no use for them that the audit page does not serve better.
 */
final class ActivitySource extends AbstractExplorerSource
{
    public function key(): string
    {
        return 'activity';
    }

    public function label(): string
    {
        return 'Activity';
    }

    public function description(): string
    {
        return 'The audit trail: every recorded action, what it was done to and who did it.';
    }

    public function rowNoun(): string
    {
        return 'actions';
    }

    public function allows(User $user): bool
    {
        return $user->can('audit_log.view');
    }

    public function rangeField(): string
    {
        return 'happened_at';
    }

    public function fields(User $user, ?Form $form): array
    {
        return [
            $this->enumField('event', 'Action', 'audits.event', AuditEvent::class, 'Action'),
            new ExplorerField(
                'subject',
                'Done to',
                ExplorerFieldType::Category,
                'audits.auditable_type',
                'Action',
                options: array_column(AuditableTypes::options(), 'label', 'value'),
                headline: true,
            ),
            $this->category('actor', 'Done by', 'audits.user_id', 'Action', lookup: 'users'),
            $this->time('happened_at', 'When', 'audits.created_at', 'Dates'),
        ];
    }

    public function query(User $user, ?Form $form): Builder
    {
        return Audit::query()->toBase();
    }
}
