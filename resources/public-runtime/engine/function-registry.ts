/**
 * The whitelist of callable functions — the mirror of `app/Services/Expressions/FunctionRegistry.php`.
 * Grammar v2.0 opens the public surface to the function library: `selected/2` (membership), `if/3`,
 * `count/1`, `int/1`, `today/0`, `now/0`. `contains/2` is internal (constructed by structured-rule
 * lowering, never parseable). Every other name is an `unknown_function`.
 *
 * M180 (`R-da02fe03`, `D155`, `D156`) adds `date_diff/3`, `date_add/3`, `age/1`, `round/2`, `coalesce/2` and the variadic
 * `sum/1+` and `concat/1+`, so an arity is a range `[min, max]` (null max: no upper bound).
 * `tests/Unit/Expressions/FunctionRegistryMirrorTest.php` reads this list and holds it to PHP's, name for name.
 */

const PUBLIC: Record<string, [number, number | null]> = {
    selected: [2, 2],
    if: [3, 3],
    count: [1, 1],
    int: [1, 1],
    today: [0, 0],
    now: [0, 0],
    date_diff: [3, 3],
    date_add: [3, 3],
    age: [1, 1],
    round: [2, 2],
    sum: [1, null],
    coalesce: [2, 2],
    concat: [1, null],
};

export class FunctionRegistry {
    isPublic(name: string): boolean {
        return Object.prototype.hasOwnProperty.call(PUBLIC, name);
    }

    /** [min, max] arguments, or null for a name the parser does not accept. */
    arity(name: string): [number, number | null] | null {
        return this.isPublic(name) ? PUBLIC[name] : null;
    }
}
