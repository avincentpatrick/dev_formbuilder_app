<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DomainEventType;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\PublishService;

/**
 * A form version was published (technical-architecture.md §7.4 names `FormPublished` as a first-class
 * domain event alongside `SubmissionCreated`). Raised post-commit by {@see PublishService};
 * H13's webhook engine will attach a queued listener that fans this out to `form.published` subscribers.
 *
 * The audit trail for a publish is written separately and IN-transaction (an `AuditEvent::Published` row on
 * the `form_version`) — the ledger must be atomic with the publish, this announcement must not fire for a
 * publish that rolled back. See PublishService for the two-record pattern.
 */
final class FormPublished extends DomainEvent
{
    private function __construct(
        public readonly string $tenantId,
        public readonly string $formId,
        public readonly string $formVersionId,
        public readonly int $versionNumber,
        public readonly ?string $publishedByUserId,
    ) {
        parent::__construct();
    }

    public static function for(FormVersion $version, User $publisher): self
    {
        return new self(
            tenantId: (string) $version->tenant_id,
            formId: (string) $version->form_id,
            formVersionId: (string) $version->id,
            versionNumber: $version->version_number,
            publishedByUserId: (string) $publisher->getKey(),
        );
    }

    public function eventType(): DomainEventType
    {
        return DomainEventType::FormPublished;
    }

    /** A publish always belongs to a tenant — narrower than the base's nullable contract (covariant). */
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
            'form_version_id' => $this->formVersionId,
            'version_number' => $this->versionNumber,
            'published_by' => $this->publishedByUserId,
        ];
    }
}
