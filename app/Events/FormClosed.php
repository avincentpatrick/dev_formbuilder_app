<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DomainEventType;
use App\Models\Form;
use App\Services\Forms\FormScheduleSweeper;

/**
 * A scheduled form crossed its `closes_at` and is no longer accepting NEW responses (Increment H12a). Raised by
 * the state-flip sweep ({@see FormScheduleSweeper}) exactly once, when `schedule_state` advances to `closed`;
 * H13's webhook engine will attach a queued listener that fans this out to `form.closed` subscribers.
 *
 * This is the TIME close only. Hitting the `max_responses` cap does NOT emit this event in H12a — capacity is a
 * synchronous submit-time guard, not a swept state transition. A pre-close save-and-resume draft may still be
 * promoted after this fires (the grace window). Emitted inside the sweep's per-tenant transaction with a
 * scalar-only payload — see {@see FormOpened} for the at-least-once + null-GUC rationale.
 */
final class FormClosed extends DomainEvent
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
        return DomainEventType::FormClosed;
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
