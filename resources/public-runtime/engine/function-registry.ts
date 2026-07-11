/**
 * The whitelist of callable functions — the mirror of `app/Services/Expressions/FunctionRegistry.php`.
 * Grammar v2.0 opens the public surface to the function library: `selected/2` (membership), `if/3`,
 * `count/1`, `int/1`, `today/0`, `now/0`. `contains/2` is internal (constructed by structured-rule
 * lowering, never parseable). Every other name is an `unknown_function`.
 */

const PUBLIC: Record<string, number> = { selected: 2, if: 3, count: 1, int: 1, today: 0, now: 0 };

export class FunctionRegistry {
    isPublic(name: string): boolean {
        return Object.prototype.hasOwnProperty.call(PUBLIC, name);
    }

    arity(name: string): number | null {
        return this.isPublic(name) ? PUBLIC[name] : null;
    }
}
