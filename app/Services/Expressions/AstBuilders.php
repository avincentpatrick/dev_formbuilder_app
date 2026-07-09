<?php

declare(strict_types=1);

namespace App\Services\Expressions;

use App\Enums\ComparisonOperator;
use App\Services\Expressions\Ast\ComparisonNode;
use App\Services\Expressions\Ast\FieldReferenceNode;
use App\Services\Expressions\Ast\FunctionCallNode;
use App\Services\Expressions\Ast\LiteralNode;

/**
 * Static AST-construction helpers (technical-architecture.md §4.3 §6.7) shared by
 * {@see StructuredRuleLowering} and, later, F3's conditional-family lowering. Producing the SAME AST a
 * parsed expression would yield means a structured rule and its `${…}` string equivalent evaluate
 * identically. F2's own lowering path uses only fieldGt/fieldLt; isNull/contains exist for F3.
 */
final class AstBuilders
{
    public static function fieldGt(string $fieldKey, string $relatedKey): ComparisonNode
    {
        return new ComparisonNode(ComparisonOperator::Gt, new FieldReferenceNode($fieldKey), new FieldReferenceNode($relatedKey));
    }

    public static function fieldLt(string $fieldKey, string $relatedKey): ComparisonNode
    {
        return new ComparisonNode(ComparisonOperator::Lt, new FieldReferenceNode($fieldKey), new FieldReferenceNode($relatedKey));
    }

    /** IsNull ≡ "field equals the empty string" (Eq rule 1/2 → isEmpty). For F3. */
    public static function isNull(string $fieldKey): ComparisonNode
    {
        return new ComparisonNode(ComparisonOperator::Eq, new FieldReferenceNode($fieldKey), new LiteralNode(LiteralKind::String, ''));
    }

    /** The internal `contains` node (array membership, else scalar substring). For F3 — never parseable. */
    public static function contains(string $fieldKey, string $value): FunctionCallNode
    {
        return new FunctionCallNode('contains', [new FieldReferenceNode($fieldKey), new LiteralNode(LiteralKind::String, $value)]);
    }
}
