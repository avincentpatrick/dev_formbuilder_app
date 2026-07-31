<?php

declare(strict_types=1);

namespace App\Support\Connectors\Providers;

use App\Enums\DomainEventType;
use App\Services\Authorization\ResourceGrantResolver;
use App\Support\Connectors\ConnectorEventContext;

/**
 * Builds the Slack Block Kit message for one domain event (H15a). PURE — envelope + context in, arrays out,
 * no I/O — so every message shape is testable without a database or an HTTP fake.
 *
 * The layout is fixed and built-in. Today's envelopes carry ids and metadata rather than answer content
 * (webhook-integration-design.md §3's default-exclude-sensitive-content principle), so there is nothing to
 * pipe INTO a template that this layout does not already say; an author-editable template on the
 * subscription is still the intended eventual shape, and {@see build()} is where it would land. H6a did NOT
 * take that step — it fixed the escaping this class was missing (below) rather than replacing the layout.
 *
 * ── Output encoding (Increment H6a; Doc #26 §5) ─────────────────────────────────────────────────────
 * Every untrusted value is escaped for `mrkdwn` via {@see mrkdwn()} BEFORE it is interpolated, never after
 * assembly. This class's own copy contains deliberate `*` bold markers, so escaping an assembled string
 * would be the wrong operation applied to the wrong thing — and any later copy change that adds a real
 * `<url|label>` link would be double-escaped by it. The contract binds every untrusted value on this
 * surface, not only piped ones (§5): the form title is tenant-authored and validated as
 * `required|string|max:255`, so `<https://evil.example|click>` was an accepted title that rendered as a
 * LIVE LINK in the tenant's channel before this fix — a live defect §5 names, not a hypothetical.
 *
 * Every message carries a `text` fallback alongside the blocks: Slack uses it for notifications and
 * accessibility surfaces where blocks are not rendered, and omitting it degrades the notification to
 * "This content can't be displayed." The fallback is derived from the already-escaped headline, so it
 * inherits the escaping rather than needing its own pass — it is the third `mrkdwn` sink in this class and
 * a fix applied only to the section block would have missed it.
 */
final class SlackMessageFormatter
{
    /**
     * @param  array<string, mixed>  $envelope
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    public function build(array $envelope, ConnectorEventContext $context): array
    {
        $rawType = (string) ($envelope['event_type'] ?? '');
        $eventType = DomainEventType::tryFrom($rawType);
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        // Escaped HERE, at the variable, before it reaches any of the three mrkdwn sinks below.
        $formLabel = self::mrkdwn(
            $context->formName
            ?? (is_string($data['form_id'] ?? null) ? 'form '.$data['form_id'] : 'a form')
        );

        // The H15b test send (ConnectorTester) is matched on the RAW string, not the enum: `test.ping` is
        // deliberately not a DomainEventType, so tryFrom() returns null and it would otherwise land in the
        // default arm and read as "*Update* — a form" — indistinguishable from a real event about a form the
        // recipient cannot find.
        $headline = $rawType === 'test.ping'
            ? '*Test message* — your form-builder workspace is connected to this channel.'
            : match ($eventType) {
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
            $facts[] = self::mrkdwn($envelope['occurred_at']);
        }

        if ($eventType === DomainEventType::SubmissionCreated && is_string($data['source'] ?? null)) {
            $facts[] = 'via '.self::mrkdwn(str_replace('_', ' ', $data['source']));
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

    /**
     * Escape one untrusted value for Slack `mrkdwn` (Increment H6a; Doc #26 §5). Slack's own rule is the
     * three characters below and only those — `&` FIRST, so the `&` it introduces for `<` and `>` is not
     * re-scanned into `&amp;lt;`.
     *
     * Escaping the three characters is what neutralises Slack's link syntax `<url|label>` and its
     * `<@user>`/`<#channel>` mentions; it deliberately does NOT touch `*`, `_` or backticks, because
     * emphasis in a respondent's answer is cosmetic noise rather than a spoofing vector and Slack offers no
     * escape for them. That boundary is the same one Slack's own API documents.
     *
     * One copy, one call site per untrusted value — the discipline
     * {@see ResourceGrantResolver::escapeLike()} states for the repo's only
     * other escaping helper: two copies of an escaping rule is how they drift.
     *
     * Note this is NOT applied to `$context->deepLink`: that value is placed in a Block Kit `url` field,
     * which is a URL context and not mrkdwn — escaping it would break the button.
     */
    private static function mrkdwn(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }
}
