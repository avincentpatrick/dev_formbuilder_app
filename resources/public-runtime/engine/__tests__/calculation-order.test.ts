/**
 * M180 (`R-39dbce5b`, `D149`) — the order calculations are worked out in, shared with the PHP twin
 * (`tests/Unit/Validation/CalculationOrderTest.php`). Both read `tests/fixtures/calculation-order.json`, so the
 * two engines agree on which calculations are worked out and in what order. References come from the real parser.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import { referencedKeys } from '../ast';
import { calculationOrder, type CalculationSource } from '../calculation-order';
import { FunctionRegistry } from '../function-registry';
import { ExpressionLexer } from '../lexer';
import { ExpressionParser } from '../parser';

type OrderCase = { name: string; calculations: CalculationSource[]; order: string[] };

const here = dirname(fileURLToPath(import.meta.url));
const cases = JSON.parse(readFileSync(join(here, '..', '..', '..', '..', 'tests', 'fixtures', 'calculation-order.json'), 'utf8')) as OrderCase[];

describe('calculationOrder', () => {
    const parser = new ExpressionParser(new ExpressionLexer(), new FunctionRegistry());
    const references = (formula: string): string[] => referencedKeys(parser.parse(formula));

    it.each(cases.map((c) => [c.name, c] as const))('%s', (_name, c) => {
        expect(calculationOrder(c.calculations, references)).toEqual(c.order);
    });
});
