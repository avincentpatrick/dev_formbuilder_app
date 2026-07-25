<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DomainEventType;
use App\Jobs\TenantAwareJob;
use App\Models\Form;
use App\Services\Forms\FormScheduleSweeper;

/**
 * A scheduled form crossed its `opens_at` and is now accepting responses (Increment H12a). Raised by the
 * state-flip sweep ({@see FormScheduleSweeper}) exactly once, when `schedule_state` advances `scheduled → open`;
 * H13's webhook engine will attach a queued listener that fans this out to `form.opened` subscribers.
 *
 * Emitted inside the sweep's per-tenant transaction (the base {@see TenantAwareJob} owns and can't
 * expose a post-commit hook), so — unlike the pipeline's post-commit events — a rare COMMIT failure after emit
 * re-emits next tick with a fresh `event_id` (at-least-once; H13 dedupes on delivery). Scalar-only payload so a
 * queued listener never restores a model under a null tenant GUC (ADR-0007 §D5).
 */
final class FormOpened extends DomainEvent
{
    private function __construct(
        public readonly string $tenantId,
        public readonly string $formId,
        public readonly ?string $opensAt,
        public readonly ?string $closesAt,
        public readonly string $timezone,
    ) {
        parent::__construct();
    }

    public static function for(Form $form): self
    {
        return new self(
            tenantId: (string) $form->tenant_id,
            formId: (string) $form->id,
            opensAt: $form->opens_at?->toIso8601String(),
            closesAt: $form->closes_at?->toIso8601String(),
            timezone: $form->timezone,
        );
    }

    public function eventType(): DomainEventType
    {
        return DomainEventType::FormOpened;
    }

    /** A form always belongs to a tenant — narrower than the base's nullable contract (covariant). */
    public function tenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function data(): array
    {
        return [
            'form_id' => $this->formId,
            'opens_at' => $this->opensAt,
            'closes_at' => $this->closesAt,
            'timezone' => $this->timezone,
        ];
    }
}
