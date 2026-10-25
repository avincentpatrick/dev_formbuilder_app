<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Support\Forms\FieldReadiness;

/*
|--------------------------------------------------------------------------
| M187 (`R-3e6358ed`) — Help's table of what each question type can do is the rules', never a copy of them.
|--------------------------------------------------------------------------
|
| `FieldReadiness` reads every cell from the rule that decides it; the Help page reads the committed JSON. This file
| is the pin between the two: change a rule (a type gains manual entry, a scan learns to read one) without running
| `php artisan help:readiness`, and the committed table is behind — red here, before an author is told something
| false.
*/

it('commits the table exactly as the rules derive it', function (): void {
    $committed = file_get_contents(base_path(FieldReadiness::PATH));

    expect($committed)->toBe(app(FieldReadiness::class)->json(), 'Run: php artisan help:readiness');
});

it('has one row for every question type, and every surface in every row', function (): void {
    $rows = app(FieldReadiness::class)->rows();

    expect(array_column($rows, 'type'))->toBe(array_map(fn (FieldType $t): string => $t->value, FieldType::cases()));

    foreach ($rows as $row) {
        expect(array_keys($row['cells']))->toBe(array_keys(FieldReadiness::COLUMNS), $row['type']);
    }
});

it('answers the testers\' question for the types they asked about (R1-6)', function (): void {
    $cells = collect(app(FieldReadiness::class)->rows())->keyBy('type')->map(fn (array $r): array => $r['cells']);

    // Every advanced type is answered on both channels — the question the badge used to leave open.
    foreach (FieldType::cases() as $type) {
        if ($type->isAdvanced()) {
            expect($cells[$type->value]['answering'])->toBe('Yes', $type->value);
        }
    }

    expect($cells['signature']['answering'])->toBe('Yes')
        ->and($cells['signature']['paper'])->toBe('A line to sign on')
        ->and($cells['signature']['scan'])->toBe('Form cannot be scanned')
        ->and($cells['duration']['builder'])->toBe('Retired')
        ->and($cells['duration']['answering'])->toBe('No')
        ->and($cells['note']['conditions'])->toBe('—');
});

it('writes nothing and fails the check while the committed table is behind', function (): void {
    $path = base_path(FieldReadiness::PATH);
    $original = file_get_contents($path);

    try {
        file_put_contents($path, "{}\n");
        $this->artisan('help:readiness', ['--check' => true])->assertExitCode(1);
        expect(file_get_contents($path))->toBe("{}\n");
    } finally {
        file_put_contents($path, (string) $original);
    }
});
