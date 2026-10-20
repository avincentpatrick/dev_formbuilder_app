/**
 * The order a form's calculations are worked out in (M180, `R-39dbce5b`, `D149`) — the mirror of PHP
 * `App\Services\Validation\CalculationOrder`, pinned by `tests/fixtures/calculation-order.json`.
 *
 * A calculation depends on every other calculation its formula names. Sweeps run over the calculations in field
 * order, taking each one whose dependencies are all worked out — one taken earlier in the same sweep counts — until
 * a sweep takes nothing. What is never taken is never worked out and reads as blank: a self-reference, every member
 * of a cycle, and anything downstream of one. Publish refuses the first two (`calculation_cycle`); this is what a
 * version published before that refusal does. A calculation with no formula counts as worked out, with no value.
 */

export type CalculationSource = { key: string; formula: string | null };

export function calculationOrder(calculations: CalculationSource[], references: (formula: string) => string[]): string[] {
    const calculationKeys = new Set(calculations.map((calculation) => calculation.key));
    const resolved = new Set<string>();
    const pending = new Map<string, string[]>();

    for (const calculation of calculations) {
        if (calculation.formula === null) {
            resolved.add(calculation.key);
            continue;
        }

        pending.set(calculation.key, references(calculation.formula).filter((key) => calculationKeys.has(key)));
    }

    const order: string[] = [];
    let progress: boolean;
    do {
        progress = false;
        for (const [key, dependencies] of [...pending]) {
            if (!dependencies.every((dependency) => resolved.has(dependency))) {
                continue;
            }

            resolved.add(key);
            order.push(key);
            pending.delete(key);
            progress = true;
        }
    } while (progress);

    return order;
}
