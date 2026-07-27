<?php

declare(strict_types=1);

namespace App\Support\Connectors\Providers;

use App\Enums\DomainEventType;
use App\Support\Connectors\ConnectorEventContext;

/**
 * Builds the Slack Block Kit message for one domain event (H15a). PURE — envelope + context in, arrays out,
 * no I/O — so every message shape is testable without a database or an HTTP fake.
 *
 * THIS CLASS IS THE SEAM H15b/H6a REPLACES. The message is a fixed, built-in layout because answer piping
 * (H6a) does not exist yet and today's envelopes carry ids and metadata rather than answer content
 * (webhook-integration-design.md §3's default-exclude-sensitive-content principle) — so there is nothing to
 * pipe INTO a template that this layout does not already say. When H6a lands, an author-editable template on
 * the subscription replaces {@see blocks()}; the adapter, the ledger and the delivery job do not change.
 *
 * Every message carries a `text` fallback alongside the blocks: Slack uses it for notifications and
 * accessibility surfaces where blocks are not rendered, and omitting it degrades the notification to
 * "This content can't be displayed."
 */
final class SlackMessageFormatter
{
    /**
     * @param  array<string, mixed>  $envelope
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    public function build(array $envelope, ConnectorEventContext $context): array
    {
        $eventType = DomainEventType::tryFrom((string) ($envelope['event_type'] ?? ''));
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        $formLabel = $context->formName
            ?? (is_string($data['form_id'] ?? null) ? 'form '.$data['form_id'] : 'a form');

        $headline = match ($eventType) {
            DomainEventType::SubmissionCreated => "*New submission* — {$formLabel}",
            DomainEventType::FormPublished => "*Form published* — {$formLabel}",
            DomainEventType::FormOpened => "*Form opened for responses* — {$formLabel}",
            DomainEventType::FormClosed => "*Form closed* — {$formLabel}",
            default => "*Update* — {$formLabel}",
        };

        $blocks = [[
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $headline],
        ]];

        $facts = $this->facts($eventType, $envelope, $data);

        if ($facts !== []) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => implode('  ·  ', $facts)]],
            ];
        }

        if ($context->deepLink !== null) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [[
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => $this->linkLabel($eventType)],
                    'url' => $context->deepLink,
                ]],
            ];
        }

        return [
            // The fallback strips mrkdwn emphasis — it is read as plain text in notifications.
            'text' => str_replace('*', '', $headline),
            'blocks' => $blocks,
        ];
    }

    /**
     * The one-line context row. Deliberately metadata only (occurrence time, version, source) — never an
     * answer value, which today's envelope does not carry and which a chat channel is the wrong place for.
     *
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function facts(?DomainEventType $eventType, array $envelope, array $data): array
    {
        $facts = [];

        if (is_string($envelope['occurred_at'] ?? null)) {
            $facts[] = $envelope['occurred_at'];
        }

        if ($eventType === DomainEventType::SubmissionCreated && is_string($data['source'] ?? null)) {
            $facts[] = 'via '.str_replace('_', ' ', $data['source']);
        }

        if ($eventType === DomainEventType::FormPublished && is_int($data['version_number'] ?? null)) {
            $facts[] = 'version '.$data['version_number'];
        }

        return $facts;
    }

    private function linkLabel(?DomainEventType $eventType): string
    {
        return $eventType === DomainEventType::SubmissionCreated ? 'View submission' : 'Open form';
    }
}
