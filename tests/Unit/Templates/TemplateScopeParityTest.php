<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PipingEligibility;
use App\Services\Templates\TemplateScopeResolver;

/*
|--------------------------------------------------------------------------
| M178 (`R-75719ac4`) — the "Insert a question" picker offers in a text spot exactly what the publish gate accepts.
|--------------------------------------------------------------------------
| The builder's `reference-candidates.ts` mirrors `TemplateScopeResolver`'s scope (earlier only, the repeat rule, a
| section heading before its members, the thank-you message after the whole form). `tests/fixtures/template-scope.json`
| is the shared truth: this file asks the RESOLVER which questions each host may name, the same way
| `TemplateValidationGate` builds its sources; `reference-candidates.test.ts` asks the picker. Both must answer the
| fixture's `offered` set. The fixture's `pipeable` flags are held to `PipingEligibility` here, so the TypeScript half
| cannot be fed a type the server would refuse.
*/

/** @return array<string, mixed> */
function templateScopeFixture(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/template-scope.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('marks each fixture question pipeable exactly when the server says it is', function (): void {
    foreach (templateScopeFixture()['form']['fields'] as $field) {
        expect($field['pipeable'])->toBe(
            PipingEligibility::for(FieldType::from($field['field_type'])) === PipingEligibility::Pipeable,
            $field['key'],
        );
    }
});

it('offers each host exactly the questions the template scope resolver accepts', function (): void {
    $fixture = templateScopeFixture();
    $sections = collect($fixture['form']['sections'])->keyBy('key');
    $resolver = new TemplateScopeResolver;

    $sources = [];
    foreach ($fixture['form']['fields'] as $field) {
        $section = $field['section'] !== null ? $sections[$field['section']] : null;
        $sources[$field['key']] = [
            'type' => PipingEligibility::for(FieldType::from($field['field_type'])),
            'position' => TemplateScopeResolver::fieldPosition($section['sequence'] ?? null, $field['sequence']),
            'repeat' => $section !== null && $section['is_repeatable'] ? $section['key'] : null,
        ];
    }
    $sectionKeys = array_fill_keys($sections->keys()->all(), true);

    foreach ($fixture['cases'] as $case) {
        $host = $case['host'];
        [$position, $repeat] = match ($host['kind']) {
            'confirmation' => [[PHP_INT_MAX, PHP_INT_MAX], null],
            'section' => [
                TemplateScopeResolver::sectionPosition($sections[$host['key']]['sequence']),
                $sections[$host['key']]['is_repeatable'] ? $host['key'] : null,
            ],
            'field' => [$sources[$host['key']]['position'], $sources[$host['key']]['repeat']],
        };

        $offered = array_values(array_filter(
            array_keys($sources),
            fn (string $key): bool => $resolver->violationFor($key, $position, $repeat, $sources, $sectionKeys) === null,
        ));

        expect($offered)->toEqualCanonicalizing($case['offered'], $case['name']);
    }
});
