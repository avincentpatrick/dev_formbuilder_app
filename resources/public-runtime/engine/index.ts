/**
 * Public API of the Phase-1 form-runtime expression + semantic-validation engine — the TypeScript mirror of
 * the PHP authority under `app/Services/Expressions` + `app/Services/Validation`. The two engines are held
 * byte-identical by the shared golden-vector suite at `tests/golden/**` (Risk R3): the SAME files run
 * through this engine (Vitest) and the PHP engine (Pest), with a count-parity guard on both sides.
 *
 * The future public-runtime SPA (Increment F6b) consumes `makeSemanticValidator()` for hybrid on-blur /
 * on-submit validation and `makeExpressionEvaluator()` for relevance/skip-logic, while the PHP pipeline
 * stays the sole authority at submit time.
 */

export { GRAMMAR_VERSION } from './evaluator';

export { ABSENT } from './coercion';
export type { EngineValue, MaybeAbsent, Absent } from './coercion';
export * as Coercion from './coercion';

export { EvaluationContext } from './context';
export type { Answers } from './context';

export { ExpressionSyntaxError, ExpressionEvaluationError } from './errors';

export { ExpressionLexer } from './lexer';
export { ExpressionParser } from './parser';
export { FunctionRegistry } from './function-registry';
export { ExpressionEvaluator, makeExpressionEvaluator } from './evaluator';

export { StructuredRuleLowering } from './lowering';
export type { FieldKeysById } from './lowering';
export { StructuredRuleEvaluator } from './structured-rule-evaluator';
export { SemanticValidator, SemanticResult, makeSemanticValidator, errorPath } from './semantic-validator';
export type { SemanticError } from './semantic-validator';

export type { SchemaField, SchemaSection, ValidationRow, SemanticInput, InstanceAnswers, CascadeConfig } from './schema';
export type { Node } from './ast';
export type {
    ComparisonOperator,
    ArithmeticOperator,
    LogicOperator,
    ValidationRuleType,
    RequiredMode,
    LiteralKind,
    NodeKind,
} from './enums';
