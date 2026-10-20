<?php

declare(strict_types=1);

use App\Enums\FeedbackSource;

/*
| M179 (`R-db9dec62`, `D150`) — where a report came from. Every report sent before M179, and every report sent
| with the Feedback button since, is `button`; a comment that reached the team another way (chat, a PDF) and
| was loaded through the support API is `relayed`, and every member of its workspace can see it.
*/

it('names the two places a report comes from, the button first', function (): void {
    expect(FeedbackSource::values())->toBe(['button', 'relayed'])
        ->and(FeedbackSource::Button->label())->toBe('Feedback button')
        ->and(FeedbackSource::Relayed->label())->toBe('Testing-round comment')
        ->and(array_column(FeedbackSource::options(), 'value'))->toBe(['button', 'relayed']);
});
