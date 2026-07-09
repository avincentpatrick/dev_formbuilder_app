<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three kinds of authored expression (technical-architecture.md §4.3), mirroring the three storage
 * locations: `relevant` (form_fields/form_sections.relevant_expression), `constraint`
 * (form_field_validations.expression), and `calculate` (form_fields.config.calculated_formula, Phase 2).
 * One grammar and one evaluator serve all three; the kind governs authoring-time rules (e.g. `.`
 * self-reference is legal only in a constraint — see ExpressionParser::assertReferencesResolve).
 */
enum ExpressionKind: string
{
    case Relevant = 'relevant';
    case Constraint = 'constraint';
    case Calculate = 'calculate';
}
