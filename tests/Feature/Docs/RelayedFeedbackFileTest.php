<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;

/*
|--------------------------------------------------------------------------
| M179 (`R-db9dec62`, `D150`) — the 31 relayed comments, as they are loaded into Feedback.
|--------------------------------------------------------------------------
| `database/data/feedback/relayed-2026-10.json` is what the relay sends to the testing server after the deploy:
| the words the user approved (plan V, §E) and each original comment, quoted. This pins what the support API
| would otherwise refuse only on the live box — the count, a reference per item, a status the lifecycle knows, the
| API's length limits — and the one rule a public repository needs: no email address in it (`D48`; the workspace
| and the reporter are named on the command line).
*/

/** @return list<array<string, mixed>> */
function relayedFileItems(): array
{
    $decoded = json_decode((string) file_get_contents(base_path('database/data/feedback/relayed-2026-10.json')), true, flags: JSON_THROW_ON_ERROR);

    return array_values($decoded['items']);
}

it('holds the 31 comments the user approved, each once, in the statuses they were relayed in', function (): void {
    $items = relayedFileItems();
    $references = array_column($items, 'reference');

    expect($items)->toHaveCount(31)
        ->and(array_unique($references))->toHaveCount(31)
        ->and(array_count_values(array_column($items, 'status')))->toEqual([
            FeedbackStatus::Resolved->value => 13,
            FeedbackStatus::Ongoing->value => 12,
            FeedbackStatus::Confirmed->value => 6,
        ]);
});

it('gives every item what the support API requires, within its limits', function (): void {
    foreach (relayedFileItems() as $item) {
        $ref = (string) ($item['reference'] ?? '');

        expect(preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]*$/', $ref))->toBe(1, "a reference the API accepts: {$ref}")
            ->and(mb_strlen($ref))->toBeLessThanOrEqual(30)
            ->and(mb_strlen((string) $item['title']))->toBeGreaterThan(0)->toBeLessThanOrEqual(200)
            ->and(mb_strlen((string) $item['remarks']))->toBeGreaterThan(0)->toBeLessThanOrEqual(5000)
            ->and(mb_strlen((string) $item['source_label']))->toBeGreaterThan(0)->toBeLessThanOrEqual(255)
            ->and(mb_strlen((string) $item['note']))->toBeGreaterThan(0)->toBeLessThanOrEqual(1000)
            ->and(FeedbackStatus::tryFrom((string) $item['status']))->not->toBeNull("{$ref}: a status the lifecycle knows")
            ->and(FeedbackType::tryFrom((string) $item['type']))->not->toBeNull("{$ref}: a kind")
            ->and(strtotime((string) $item['received_at']))->not->toBeFalse();

        if ($item['status'] === FeedbackStatus::Confirmed->value) {
            expect(strtotime((string) ($item['confirmed_at'] ?? '')))->not->toBeFalse("{$ref}: a confirmed item says when");
        }
    }
});

it('carries no email address, because the repository is public', function (): void {
    $raw = (string) file_get_contents(base_path('database/data/feedback/relayed-2026-10.json'));

    expect(preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $raw))->toBe(0);
});
