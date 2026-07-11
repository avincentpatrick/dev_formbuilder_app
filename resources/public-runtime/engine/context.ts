/**
 * The read-only evaluation context — the mirror of `app/Services/Expressions/EvaluationContext.php`
 * (technical-architecture.md §4.3 §7): a flat, ALREADY relevance-pruned answer map (`${key}` → value) plus
 * an optional `self` value for the `.` reference in a constraint and an optional injected clock (`now`,
 * grammar v2.0). Relevance pruning is the caller's job (the semantic validator builds the effective set);
 * an irrelevant field is simply absent here, resolving to EMPTY. The `now` clock is an ISO-8601 string the
 * caller stamps so `today()`/`now()` are deterministic and identical across the two engines.
 *
 * Frozen answer-value shape (the engines cannot diverge on it): scalar string for text/choice/date;
 * number for integer/decimal; ALWAYS a `string[]` for multi_select; null or an absent key for unanswered.
 * A repeatable section's key resolves to its instance-map array (for `count()`).
 */

import type { EngineValue } from './coercion';

const UNSET: unique symbol = Symbol('unset');

export type Answers = Record<string, EngineValue>;

export class EvaluationContext {
    readonly answers: Answers;
    readonly self: EngineValue | typeof UNSET;
    /** The injected clock (ISO-8601) that `today()`/`now()` read; null when no clock is set (grammar v2.0). */
    readonly now: string | null;

    constructor(answers: Answers, self: EngineValue | typeof UNSET = UNSET, now: string | null = null) {
        this.answers = answers;
        this.self = self;
        this.now = now;
    }

    has(key: string): boolean {
        return Object.prototype.hasOwnProperty.call(this.answers, key);
    }

    hasSelf(): boolean {
        return this.self !== UNSET;
    }

    hasNow(): boolean {
        return this.now !== null;
    }

    selfValue(): EngineValue {
        // Only read after hasSelf(); the UNSET branch is unreachable in that contract.
        return this.self === UNSET ? null : this.self;
    }

    /**
     * Golden-runner factory — mirrors EvaluationContext::fromVector.
     */
    static fromVector(vector: { answers?: Answers; self?: EngineValue; now?: string }): EvaluationContext {
        return new EvaluationContext(
            vector.answers ?? {},
            Object.prototype.hasOwnProperty.call(vector, 'self') ? (vector.self as EngineValue) : UNSET,
            typeof vector.now === 'string' ? vector.now : null,
        );
    }
}
