/**
 * The whitelist of callable functions — the mirror of `app/Services/Expressions/FunctionRegistry.php`.
 * Only `selected/2` is PUBLIC (parser-accepted) in Phase 1; `contains/2` is internal (constructed by
 * structured-rule lowering, never parseable). Every other name (`count`, `today`, `now`, `if`, `int`, …)
 * is an `unknown_function`.
 */

const PUBLIC: Record<string, number> = { selected: 2 };

export class FunctionRegistry {
    isPublic(name: string): boolean {
        return Object.prototype.hasOwnProperty.call(PUBLIC, name);
    }

    arity(name: string): number | null {
        return this.isPublic(name) ? PUBLIC[name] : null;
    }
}
