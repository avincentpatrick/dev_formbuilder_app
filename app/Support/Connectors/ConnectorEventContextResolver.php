<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Enums\DomainEventType;
use App\Events\DomainEvent;
use App\Models\Form;
use App\Services\Connectors\ConnectorRedirector;

/**
 * Turns a {@see DomainEvent} envelope into the display context a connector message needs
 * ({@see ConnectorEventContext}) — the form's name and a deep link into the tenant's own app (ADR-0009, H15a).
 *
 * The form lookup is RLS-scoped: this runs inside the delivery job's tenant transaction, so it can only ever
 * see the delivering tenant's forms. The host comes from {@see ConnectorRedirector} — the same builder the
 * post-callback return uses, reading the `domains` table rather than a request, because a queue worker has
 * no request to read one from.
 *
 * Missing pieces degrade rather than throw: a hard-deleted form yields a null name and the formatter falls
 * back to the id. A delivery must never fail because a display string could not be resolved.
 */
final class ConnectorEventContextResolver
{
    public function __construct(private readonly ConnectorRedirector $redirector) {}

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function resolve(array $envelope): ConnectorEventContext
    {
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        $formId = isset($data['form_id']) && is_string($data['form_id']) ? $data['form_id'] : null;
        $submissionId = isset($data['submission_id']) && is_string($data['submission_id']) ? $data['submission_id'] : null;

        $formName = $formId === null
            ? null
            : Form::query()->whereKey($formId)->value('title');

        return new ConnectorEventContext(
            is_string($formName) ? $formName : null,
            $this->deepLink($envelope, $formId, $submissionId),
        );
    }

    /**
     * A submission event links to the submission; every form-lifecycle event links to the form. Null when
     * the tenant has no resolvable host (a data state that should not occur, but must not break a delivery).
     *
     * `member.invited` deliberately resolves to null: the only page it could point at is the members list,
     * and an invitation is not yet a member — the row a recipient would arrive to look for is not there
     * until they accept. The Slack message says what happened and stops.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function deepLink(array $envelope, ?string $formId, ?string $submissionId): ?string
    {
        $tenantId = isset($envelope['tenant_id']) && is_string($envelope['tenant_id']) ? $envelope['tenant_id'] : null;

        if ($tenantId === null) {
            return null;
        }

        $path = match (DomainEventType::tryFrom((string) ($envelope['event_type'] ?? ''))) {
            DomainEventType::SubmissionCreated,
            DomainEventType::SubmissionApproved,
            DomainEventType::SubmissionReturned,
            // I9c — the detail page, NOT the edit page. A Slack link is followed by whoever is watching the
            // channel, and `submissions.edit.any/.own` is held by Owner/Admin/Form Editor only; pointing a
            // Reviewer at an edit URL they cannot open turns an informative message into a 403.
            DomainEventType::SubmissionUpdated => $submissionId === null ? null : "/submissions/{$submissionId}",
            DomainEventType::FormPublished,
            DomainEventType::FormOpened,
            DomainEventType::FormClosed => $formId === null ? null : "/forms/{$formId}/builder",
            default => null,
        };

        if ($path === null) {
            return null;
        }

        // One host builder for the whole framework: the post-callback return and this deep link must agree,
        // and both read `domains` rather than a request (a queue worker has no request to read).
        $host = $this->redirector->tenantHost($tenantId);

        return $host === null ? null : $this->scheme().'://'.$host.$path;
    }

    private function scheme(): string
    {
        $scheme = config('connectors.tenant_url_scheme', 'https');

        return is_string($scheme) && $scheme !== '' ? $scheme : 'https';
    }
}
