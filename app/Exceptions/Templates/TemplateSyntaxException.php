<?php

declare(strict_types=1);

namespace App\Exceptions\Templates;

use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Services\Forms\TemplateValidationGate;
use RuntimeException;

/**
 * A parse-time TEMPLATE error (`docs/piping-output-encoding-design.md` §2) — an authoring mistake surfaced
 * at publish time, never mid-render. Each factory pins a stable snake_case `slug()` the
 * `tests/golden/templates/` `expected_error` vectors assert against, so the PHP and TypeScript parsers must
 * agree on WHICH error a malformed template produces, not merely that it failed. The publish consumer
 * ({@see TemplateValidationGate}) re-wraps these into a `PublishValidationException` so
 * the existing web-toast / api-422 surfacings fire.
 *
 * ── Why this does NOT extend App\Exceptions\Expressions\ExpressionException ──────────────────────────
 * Doc #26 §2 is explicit that a template is a SIBLING grammar, not a mode of the expression grammar, and
 * the inheritance would be a trap rather than reuse: `bootstrap/app.php` renders the two LEAF expression
 * types by name (`ExpressionSyntaxException|ExpressionEvaluationException`), so a third leaf under the same
 * base would match NO callback — a raw 500 on web and an envelope-less 500 on the API. A separate base
 * makes that non-matching obvious instead of hiding it behind a familiar parent.
 *
 * No render callback is registered for this class, deliberately. {@see TemplateParser::parse()} — the only
 * method that throws — has exactly one production caller, the publish gate, whose catch is total; every
 * render path uses {@see TemplateParser::parseLenient()}, which cannot throw (§3.4's never-fail render
 * contract). The exception is therefore structurally unable to reach HTTP, and a callback would be dead
 * code. The first increment that calls `parse()` outside a gate owes one.
 */
final class TemplateSyntaxException extends RuntimeException
{
    public function __construct(string $message, private readonly string $slug)
    {
        parent::__construct($message);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /**
     * A hole that opened but never resolved to `${key}`. Doc #26 §2.1 says to reuse the expression lexer's
     * slug verbatim ({@see ExpressionSyntaxException::malformedReference()}),
     * so the two corpora share one vocabulary for the one lexeme the two grammars share.
     *
     * `$raw` is a 24-byte window from the offending `$`, mirroring `ExpressionLexer::rawReference()`, so the
     * two engines' messages stay aligned.
     */
    public static function malformedReference(string $raw): self
    {
        return new self("Malformed field reference “{$raw}”.", 'malformed_reference');
    }

    /**
     * Over the byte cap (Doc #26 §2.1). Deliberately NOT the expression lexer's `expression_too_long`: a
     * template is not an expression, and that slug is additionally overloaded there — `ExpressionLexer`
     * raises it for the TOKEN cap too, passing the byte length instead of the token count, so its message
     * can read "too long (40)" for a 40-byte, 600-token expression. The two caps get two slugs here.
     */
    public static function templateTooLong(int $bytes): self
    {
        return new self("The template is too long ({$bytes} bytes).", 'template_too_long');
    }

    /** Over the hole cap (Doc #26 §2.1) — bounds render cost on a page that may hold hundreds of fields. */
    public static function tooManyHoles(int $count): self
    {
        return new self("The template has too many references ({$count}).", 'too_many_holes');
    }
}
