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

// The piping TEMPLATE grammar (Increment H6a, Doc #26 §2) — a SIBLING of the expression grammar with its
// own version constant and its own `tests/golden/templates/` corpus. `GRAMMAR_VERSION` above stays '2.0'.
// Co-located in `engine/` for packaging only: one barrel, one import path for the SPA. This barrel is also
// what makes `template.ts` reachable by vue-tsc — `*.test.ts` are excluded from tsconfig, so the golden
// runner itself is executed but never type-checked.
export { TEMPLATE_VERSION, TemplateParser, TemplateSyntaxError, makeTemplateParser } from './template';
export type { TemplateSegment } from './template';

// H6b's render side: the twins of `SchemaValueFormatter::displayValue()` and `TemplateRenderer`. Exported
// here for the same reason `template.ts` is — `*.test.ts` is excluded from tsconfig, so this barrel is the
// ONLY thing that puts either module under vue-tsc. `displayValue` is exported in its own right and not
// merely dragged in by the renderer: it is a legitimate standalone surface (an offline review screen is
// the obvious next consumer) and the barrel is the documented one import path for the SPA.
export { displayValue } from './display-value';
export { TemplateRenderer, makeTemplateRenderer } from './template-renderer';
export type { RenderSource, RenderSources } from './template-renderer';

// Increment H21a — `rendersNothing()` moved down from `lib/schema-mapping.ts` because the semantic
// validator gained a second consumer of the same set (the `min_instances` step-visibility narrowing,
// Doc #27 §4.3). `lib/` re-exports it, so every existing import path still resolves.
export { rendersNothing } from './field-roles';

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

export type {
    SchemaField,
    SchemaSection,
    ValidationRow,
    SemanticInput,
    InstanceAnswers,
    CascadeConfig,
    GridConfig,
    CompositeAnswer,
    LikertMatrixAnswer,
    MatrixAnswer,
    GeoAnswer,
    MediaAnswer,
} from './schema';
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
