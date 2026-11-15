<?php

declare(strict_types=1);

use App\Support\Forms\CopyKeys;

// M240 (`builder-copy-section`, `D225`) — the keys a copy takes: the number counts up when the whole copy's next number
// is free, otherwise `_copy`. Pure Unit: `$taken` stands in for the draft's keys and every published version's.

function copyKeysTaken(string ...$keys): array
{
    return array_fill_keys($keys, true);
}

it('counts the first number up, keeping its zero-padded width', function (): void {
    $block = ['a01_info', 'a01_use', 'a01_keep'];

    expect(CopyKeys::plan($block, copyKeysTaken(...$block)))->toBe([
        'a01_info' => 'a02_info', 'a01_use' => 'a02_use', 'a01_keep' => 'a02_keep',
    ])
        ->and(CopyKeys::plan(['a09_x'], copyKeysTaken('a09_x')))->toBe(['a09_x' => 'a10_x'])
        ->and(CopyKeys::plan(['a99_x'], copyKeysTaken('a99_x')))->toBe(['a99_x' => 'a100_x'])
        ->and(CopyKeys::plan(['section_3'], copyKeysTaken('section_3')))->toBe(['section_3' => 'section_4'])
        ->and(CopyKeys::plan(['q3_1'], copyKeysTaken('q3_1')))->toBe(['q3_1' => 'q4_1']);
});

it('shares one step across the copy, skipping a number any one of its keys would collide at', function (): void {
    // a02_use is free but a02_keep is not, so the whole block moves to 03.
    $taken = copyKeysTaken('a01_use', 'a01_keep', 'a02_keep');

    expect(CopyKeys::plan(['a01_use', 'a01_keep'], $taken))->toBe(['a01_use' => 'a03_use', 'a01_keep' => 'a03_keep']);
});

it('never takes a key the caller says is taken — a section key, or one a published version ever held', function (): void {
    // `q2` is a section's key in the draft, `q3` a question every published version since retired; both are in $taken.
    expect(CopyKeys::plan(['q1'], copyKeysTaken('q1', 'q2', 'q3')))->toBe(['q1' => 'q4']);
});

it('gives a key with no number, or a run too long to count, the _copy suffix', function (): void {
    expect(CopyKeys::plan(['age', 'q1'], copyKeysTaken('age', 'q1')))->toBe(['age' => 'age_copy', 'q1' => 'q2'])
        ->and(CopyKeys::plan(['age'], copyKeysTaken('age', 'age_copy', 'age_copy_2')))->toBe(['age' => 'age_copy_3']);

    $long = 'id_'.str_repeat('9', 19);
    expect(CopyKeys::plan([$long], copyKeysTaken($long)))->toBe([$long => $long.'_copy']);
});

it('falls back to _copy for the whole copy when no step up to the cap works', function (): void {
    $taken = copyKeysTaken('q1', ...array_map(static fn (int $n): string => "q{$n}", range(2, 1001)));

    expect(CopyKeys::plan(['q1'], $taken))->toBe(['q1' => 'q1_copy']);
});

it('keeps every key inside the column, shortening the key and never the suffix', function (): void {
    $key = str_repeat('k', 150);
    $plan = CopyKeys::plan([$key], copyKeysTaken($key));

    expect($plan[$key])->toBe(str_repeat('k', 145).'_copy')->and(strlen($plan[$key]))->toBe(150);

    // A count-up that would grow past 150 characters is no candidate.
    $numbered = str_repeat('k', 148).'99';
    expect(CopyKeys::plan([$numbered], copyKeysTaken($numbered))[$numbered])->toBe(str_repeat('k', 145).'_copy');
});

it('hands out distinct keys within one copy, numbered and suffixed alike', function (): void {
    $plan = CopyKeys::plan(['name', 'name_x', 'q1'], copyKeysTaken('name', 'name_x', 'q1', 'name_copy'));

    expect($plan)->toBe(['name' => 'name_copy_2', 'name_x' => 'name_x_copy', 'q1' => 'q2'])
        ->and(array_unique(array_values($plan)))->toHaveCount(3);
});
