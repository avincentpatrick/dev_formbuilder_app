<?php

declare(strict_types=1);

use App\Services\Submissions\AnswersContentChecksum;

/**
 * The canonical answers-content hash (Increment G8c) the Submission Pipeline uses to tell an idempotent
 * replay apart from a genuine concurrent-edit conflict. Pure — no container, no DB.
 */
it('is stable across object key ordering', function (): void {
    $a = AnswersContentChecksum::of(['first' => 'Ada', 'last' => 'Lovelace']);
    $b = AnswersContentChecksum::of(['last' => 'Lovelace', 'first' => 'Ada']);

    expect($a)->toBe($b)->and($a)->toHaveLength(64);
});

it('is stable across nested (repeat-group) key ordering', function (): void {
    $a = AnswersContentChecksum::of(['hh' => [['name' => 'Bob', 'age' => '20'], ['name' => 'Cleo', 'age' => '30']]]);
    $b = AnswersContentChecksum::of(['hh' => [['age' => '20', 'name' => 'Bob'], ['age' => '30', 'name' => 'Cleo']]]);

    expect($a)->toBe($b);
});

it('changes when a value changes', function (): void {
    expect(AnswersContentChecksum::of(['name' => 'Ada']))
        ->not->toBe(AnswersContentChecksum::of(['name' => 'Grace']));
});

it('is order-sensitive for list items (repeat-instance order is meaningful)', function (): void {
    $a = AnswersContentChecksum::of(['hh' => [['name' => 'Bob'], ['name' => 'Cleo']]]);
    $b = AnswersContentChecksum::of(['hh' => [['name' => 'Cleo'], ['name' => 'Bob']]]);

    expect($a)->not->toBe($b);
});
