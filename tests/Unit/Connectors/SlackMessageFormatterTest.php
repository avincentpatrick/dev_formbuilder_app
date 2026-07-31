<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use App\Support\Connectors\ConnectorEventContext;
use App\Support\Connectors\Providers\SlackMessageFormatter;

// Doc #26 §5's Slack `mrkdwn` row + §7's named injection vector, and the FIRST test this class has ever
// had. The only assertion on a Slack message body anywhere in the repo before H6a was
// `str_contains($body['text'], 'New submission')` in ConnectorDeliveryJobTest — a substring check on
// TRUSTED copy, so the escaping bug was entirely unpinned and this fix had to ADD coverage rather than
// update it.
//
// The class is pure (envelope + context in, arrays out), so no DB, no Http fake, no container.

/** @return array<string, mixed> */
function slackSubmissionEnvelope(): array
{
    return [
        'event_type' => DomainEventType::SubmissionCreated->value,
        'occurred_at' => '2026-07-30T09:00:00Z',
        'data' => ['form_id' => '0198-abc', 'source' => 'web_form'],
    ];
}

/** The mrkdwn text of the first (section) block. */
function slackSectionText(array $message): string
{
    return (string) $message['blocks'][0]['text']['text'];
}

/** The mrkdwn text of the context block. */
function slackContextText(array $message): string
{
    return (string) $message['blocks'][1]['elements'][0]['text'];
}

it('renders a link-shaped form title as inert text, not a live Slack link', function (): void {
    // §7's vector, verbatim. `<https://evil.example|click>` is Slack's own link syntax: unescaped, it
    // renders as a clickable link labelled "click" in the tenant's channel. The form title is only
    // validated `required|string|max:255`, so this was an accepted title before H6a.
    $message = (new SlackMessageFormatter)->build(
        slackSubmissionEnvelope(),
        new ConnectorEventContext('<https://evil.example|click>', null),
    );

    expect(slackSectionText($message))
        ->toContain('&lt;https://evil.example|click&gt;')
        ->not->toContain('<https://evil.example|click>');
});

it('escapes the title in the text fallback as well as the blocks', function (): void {
    // The THIRD mrkdwn sink. `text` is derived from the headline by stripping `*`, which is
    // emphasis-removal and not escaping — a fix applied only to the section block would have missed it.
    $message = (new SlackMessageFormatter)->build(
        slackSubmissionEnvelope(),
        new ConnectorEventContext('<https://evil.example|click>', null),
    );

    expect((string) $message['text'])->not->toContain('<https://evil.example|click>');
});

it('escapes untrusted values in the context block too', function (): void {
    // The SECOND sink. `facts()` interpolates the envelope's own strings, which come off the wire.
    $envelope = slackSubmissionEnvelope();
    $envelope['data']['source'] = '<https://evil.example|web form>';

    $message = (new SlackMessageFormatter)->build($envelope, new ConnectorEventContext('Survey', null));

    expect(slackContextText($message))
        ->toContain('&lt;https://evil.example|web form&gt;')
        ->not->toContain('<https://evil.example|web');
});

it('escapes ampersands before angle brackets so entities are not double-encoded', function (): void {
    // `&` must be first in the search array: replacing `<` first would introduce an `&` that the later
    // `&` pass would then turn into `&amp;lt;`.
    $message = (new SlackMessageFormatter)->build(
        slackSubmissionEnvelope(),
        new ConnectorEventContext('Q&A <survey>', null),
    );

    expect(slackSectionText($message))->toContain('Q&amp;A &lt;survey&gt;');
});

it('leaves the classes own mrkdwn emphasis intact', function (): void {
    // Proves the escaper is applied to the VARIABLE, not to the assembled string. The `*New submission*`
    // bold markers are deliberate mrkdwn written by this class; escaping after assembly would be the
    // wrong operation applied to trusted copy, and any later copy change adding a real `<url|label>` link
    // would be double-escaped by it.
    $message = (new SlackMessageFormatter)->build(
        slackSubmissionEnvelope(),
        new ConnectorEventContext('Household Survey', null),
    );

    expect(slackSectionText($message))->toBe('*New submission* — Household Survey');
});

it('leaves the deep-link button url unescaped', function (): void {
    // A Block Kit `url` field is a URL context, not mrkdwn — escaping it would break the button.
    $message = (new SlackMessageFormatter)->build(
        slackSubmissionEnvelope(),
        new ConnectorEventContext('Survey', 'https://acme.example/submissions/1?a=1&b=2'),
    );

    expect((string) $message['blocks'][2]['elements'][0]['url'])
        ->toBe('https://acme.example/submissions/1?a=1&b=2');
});

it('leaves the test.ping headline unchanged', function (): void {
    // `test.ping` carries no untrusted variable at all, so the escaping pass must not alter it. Also guards
    // the raw-string match the H15b tester depends on (test.ping is deliberately not a DomainEventType).
    $message = (new SlackMessageFormatter)->build(
        ['event_type' => 'test.ping', 'data' => []],
        ConnectorEventContext::empty(),
    );

    expect(slackSectionText($message))
        ->toBe('*Test message* — your form-builder workspace is connected to this channel.');
});
