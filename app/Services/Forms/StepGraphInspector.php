<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\GraphNoticeKind;
use App\Enums\PrefillSource;
use App\Enums\ValidationRuleType;
use App\Exceptions\Expressions\ExpressionException;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Services\Expressions\ExpressionParser;
use App\Services\Submissions\StructuralAnswerNormalizer;
use App\Services\Templates\TemplateScopeResolver;
use App\Services\Validation\SemanticValidator;
use App\Support\Forms\GraphNotice;
use App\Support\Forms\StepProjection;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Publish-time NOTICES about a version's relevance graph (Doc #27 §6). Increment H21a.
 *
 * H21A ADDS WARNINGS, NOT REFUSALS. NO NEW STRUCTURAL REFUSAL IS ADDED BY THE WHOLE OF H21, and the reason
 * is the retro-gate shape rather than tolerance. Every check here would otherwise run at
 * `PublishService::publish()` step 1, against the DRAFT — and step 9 of the same method clones the
 * just-published structure forward into a fresh draft. So a form that is live and collecting data would
 * become UN-EDITABLE, with an error naming a rule its author wrote before the rule existed. There is no
 * grandfather mechanism for publish gates: H5c's `legacyOverrides()` seam covers entitlements only.
 *
 * The non-throwing shape to copy already exists and was built for exactly this purpose —
 * {@see TemplateValidationGate::confirmationMessageViolations()}, the returning twin H6a's amendment A3
 * added so a caller could warn instead of refuse. This class follows it, and is called from
 * `FormPublishController` AFTER `publish()` returns rather than from inside the service, which keeps
 * `PublishService`'s contract binary and leaves `FormVersionApiController` — and therefore `openapi.json` —
 * untouched.
 *
 * Everything is decided from the two `relevant_expression` columns plus `ExpressionParser::referencedKeys()`,
 * which the expression gate already calls. No new parsing machinery.
 *
 * ── H21d1: A SECOND CALLER, AND AN UNVALIDATED ONE ──────────────────────────────────────────────────
 * The logic canvas reuses these notices against a **draft**, so this class now runs over expressions
 * `ExpressionValidationGate` has never seen. Two consequences, both load-bearing:
 *
 *  1. {@see notices()} is the primary method and {@see warnings()} the flash-shaped wrapper over it. The
 *     canvas needs each notice attached to the node it names; the flash wants one joined sentence per kind.
 *  2. NOTHING HERE MAY THROW ON A HALF-TYPED EXPRESSION. `referencesIn()` has always caught (its docblock
 *     named this increment as the reason), but {@see emptyAtOpen()} reached the parser through
 *     `SemanticValidator` and did not — see its own note.
 */
final class StepGraphInspector
{
    public function __construct(
        private readonly ExpressionParser $parser,
        private readonly SemanticValidator $semantic,
        private readonly StructuralAnswerNormalizer $normalizer,
    ) {}

    /**
     * Every notice for a version, each addressable to the nodes it names (Increment H21d1).
     *
     * @return list<GraphNotice>
     */
    public function notices(FormVersion $version): array
    {
        /** @var Collection<int, FormField> $fields */
        $fields = $version->fields()->orderBy('sequence')->orderBy('key')->get();
        /** @var Collection<int, FormSection> $sections */
        $sections = $version->sections()->orderBy('sequence')->orderBy('key')->get();

        // The order is H21a's, and `warnings()` re-derives it from an explicit list rather than depending
        // on this one — two callers, neither reading the other's mind.
        return array_merge(
            $this->emptyAtOpen($version, $fields, $sections),
            $this->forwardReferences($fields, $sections),
            $this->cycles($version, $fields, $sections),
        );
    }

    /**
     * The same notices as finished sentences ready to flash — one per KIND, joining every instance of it.
     *
     * Shaping lives here rather than in the controller deliberately: `scripts/controller-gate.php` caps a
     * controller at 250 code lines and complexity 10, and message-building is all `foreach` + `??`.
     *
     * The grouping is what makes this different from `array_map(message)`: three forward references are one
     * banner line, not three, because the banner appears once on a page the author is leaving. Beside a node
     * on the canvas the opposite is true, which is why {@see GraphNotice} carries both strings.
     *
     * @return list<string>
     */
    public function warnings(FormVersion $version): array
    {
        /** @var array<string, list<string>> $byKind */
        $byKind = [];
        foreach ($this->notices($version) as $notice) {
            $byKind[$notice->kind->value][] = $notice->fragment;
        }

        // Explicit, not `GraphNoticeKind::cases()`: this is H21a's emission order, which the banner has
        // shipped with, and it must not become a function of how the enum's cases happen to be declared.
        $order = [GraphNoticeKind::EmptyAtOpen, GraphNoticeKind::ForwardReference, GraphNoticeKind::Cycle];

        $sentences = [];
        foreach ($order as $kind) {
            $fragments = $byKind[$kind->value] ?? [];
            if ($fragments === []) {
                continue;
            }
            $sentences[] = $this->flashSentence($kind, $fragments);
        }

        return $sentences;
    }

    /**
     * The flash copy for one kind. Pinned verbatim: these three sentences shipped in H21a and are asserted
     * by `StepGraphInspectorTest`, so the H21d1 restructure must reproduce them byte-for-byte.
     *
     * @param  list<string>  $fragments
     */
    private function flashSentence(GraphNoticeKind $kind, array $fragments): string
    {
        return match ($kind) {
            GraphNoticeKind::ForwardReference => 'Forward reference: '.implode('; ', $fragments)
                .'. That condition stays false until the respondent reaches the later question.',
            GraphNoticeKind::Cycle => 'Circular condition: '.implode('; ', $fragments)
                .'. These conditions depend on each other, so the result depends on the order they settle in.',
            // Form-level and singular by construction — there is exactly one graph to be empty.
            GraphNoticeKind::EmptyAtOpen => $fragments[0],
        };
    }

    /**
     * §4.1 — a form that renders NO step at all against an empty answer map.
     *
     * Emptiness in general is undecidable (it depends on the answers), and the mid-fill empty graph is
     * legitimate and specified. But the empty-AT-OPEN case is decidable, so it is worth catching: run the
     * validator over an empty answer map exactly as `SubmissionPdfPresenter` runs it over a stored one, then
     * project.
     *
     * SUPPRESSED FOR AN H7 ROUTER FORM. §4.1 concedes the false positive in the same breath as the check: a
     * form whose only entry condition is a URL-prefilled `hidden` field is LEGITIMATELY empty under an empty
     * context, because the prefill has not happened yet. That is a precise, structural suppression rather
     * than a heuristic — and without it this notice would fire on every form of the shape H7 shipped three
     * increments ago, which is the "warning nobody will read twice" §6 rejects for unreachable sections.
     *
     * The clock is pinned to the version's own `published_at`, the H17 device: a `today()`-dependent
     * `relevant_expression` must be judged against publication, not against the moment someone happened to
     * ask. On a DRAFT that column is null, which `SemanticValidator` reads as "stamp your own clock" — the
     * only honest answer for a version that has no publication instant yet.
     *
     * ── H21D1 DEFECT, FIXED HERE: THIS WAS THE ONE CHECK THAT COULD THROW ───────────────────────────
     * `evaluateRelevance()` → `ExpressionEvaluator::evaluateBoolean()` → `ExpressionParser::parse()`, which
     * raises `ExpressionSyntaxException` on malformed input. On the publish path that is unreachable —
     * `ExpressionValidationGate` runs inside `publish()` and refuses first — which is why it never fired in
     * H21a. Against a draft it is the ORDINARY case: a half-typed condition is what an author has most of
     * the time, and an uncaught throw here 500s the canvas's sidecar.
     *
     * Both settle loops can raise it (sections are swept separately from fields), so the guard wraps the
     * whole evaluation rather than one arm. A graph that cannot be evaluated yields NO empty-at-open notice:
     * "empty at open" is a claim about what a respondent would see, and nobody can see anything through an
     * expression that does not parse. The truer thing to say about that node is the syntax error itself, and
     * the canvas already says it client-side, between keystrokes, where the author is looking.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  Collection<int, FormSection>  $sections
     * @return list<GraphNotice>
     */
    private function emptyAtOpen(FormVersion $version, Collection $fields, Collection $sections): array
    {
        foreach ($fields as $field) {
            if (PrefillSource::for($field->field_type, $field->config) === PrefillSource::Url) {
                return [];
            }
        }

        try {
            $normalized = $this->normalizer->normalize($fields, $sections, []);
            $result = $this->semantic->validate($version, $normalized, null, $version->published_at?->toIso8601String());

            $empty = StepProjection::isEmpty(
                $sections,
                $fields,
                $result->sectionRelevance,
                $result->fieldRelevance,
                $result->repeatFieldRelevance,
            );
        } catch (ExpressionException) {
            return [];
        }

        if (! $empty) {
            return [];
        }

        $message = 'This form shows no questions at all until something is answered, so a respondent opening it sees an empty form.';

        // No node: emptiness is a property of the graph, not of any one section — naming one would point the
        // author at an arbitrary member of the set of conditions that are jointly responsible.
        return [new GraphNotice(GraphNoticeKind::EmptyAtOpen, [], $message, $message)];
    }

    /**
     * §3.1 — a `relevant_expression` that references something LATER in the form.
     *
     * Forward references stay LEGAL. A forward relevance reference is merely LATE, not useless: it evaluates
     * false until the respondent reaches the referenced field and true from then on, and "show the summary
     * section only when the roster below is non-empty" is a legitimate authoring shape. That is exactly why
     * the piping ban does not transfer — a forward piping hole is PERMANENTLY empty.
     *
     * The rule is the INVERSE of piping's, and the inversion is load-bearing: piping refuses when the source
     * does not precede the host, while this fires only when the host PROVABLY precedes the source. So a
     * positional TIE stays silent, which is what keeps a field referencing its own key quiet and, more
     * importantly, keeps template-materialized forms quiet — `SchemaBlueprintMaterializer` defaults a
     * section's `sequence` to 0, so every cross-section reference on such a form ties.
     *
     * A section referencing a field it CONTAINS is deliberately not reported here even though it is
     * positionally forward: it is a self-reference, not a late one, and the cycle notice below diagnoses it
     * correctly. Telling an author to "move the field earlier" would fix nothing.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  Collection<int, FormSection>  $sections
     * @return list<GraphNotice>
     */
    private function forwardReferences(Collection $fields, Collection $sections): array
    {
        [$positions, $ownerSectionKey] = $this->positions($fields, $sections);

        /** @var array<string, GraphNotice> $pairs  keyed by fragment, which dedupes as the old array_unique did */
        $pairs = [];

        foreach ($this->relevanceNodes($fields, $sections) as [$nodeKey, $expression, $hostPosition, $containedIn]) {
            foreach ($this->referencesIn($expression) as $referenced) {
                $target = $positions[$referenced] ?? null;
                if ($target === null) {
                    continue; // an unresolvable key is the expression gate's refusal, not ours
                }

                // A section's own member: the cycle notice owns this diagnosis.
                if ($containedIn === null && ($ownerSectionKey[$referenced] ?? null) === $nodeKey) {
                    continue;
                }

                if (TemplateScopeResolver::precedes($hostPosition, $target)) {
                    $fragment = '“'.$nodeKey.'” depends on “'.$referenced.'”, which comes later in the form';
                    // The HOST is named first: it is the node the canvas hangs this on, because it is the
                    // node whose condition is the late one and therefore the node the author would edit.
                    $pairs[$fragment] = new GraphNotice(
                        GraphNoticeKind::ForwardReference,
                        [$nodeKey, $referenced],
                        'This depends on “'.$referenced.'”, which comes later in the form, so it stays false until the respondent reaches that question.',
                        $fragment,
                    );
                }
            }
        }

        return array_values($pairs);
    }

    /**
     * §3.2 — mutually dependent relevance, reported as a notice and never a refusal.
     *
     * A cycle is a strongly-connected component of the "depends on" graph, found with a three-colour DFS.
     * The edges are of THREE kinds, and the last two are what make the check honest:
     *
     *  - REFERENCE: a node's `relevant_expression` names a key.
     *  - CONTAINMENT: a field depends on its owning section, because the settle loop folds section relevance
     *    into every member (`$sectionOk && $ownOk && ! $skipped`). Without this edge the commonest real cycle
     *    is invisible — a section gated on a field INSIDE itself, where iteration 0 prunes the field, the
     *    gate reads ABSENT forever, and the respondent can never answer the field because it never renders.
     *  - SKIP: a `skip_if` / `skip_with` row's `related_form_field_id`. Those units participate in the settle
     *    too, so omitting them would make the detector silently incomplete.
     *
     * A CHAIN IS NOT A CYCLE, which is the property Doc #27 §3.2 demands: `gate → a → b` — the shape
     * `chained_fixed_point` in `tests/golden/validation/relevance.json` pins as CORRECT — is a DAG and is
     * silent here. A detector that cannot tell a cycle from a chain warns on correct forms, which is worse
     * than not warning.
     *
     * Recorded honestly: a mutually-referential pair that happens to settle to a fixed point is still
     * reported. That is deliberate for a NOTICE — mutual dependency is a real design smell, the message names
     * the members so the author can judge, and nothing is blocked either way.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  Collection<int, FormSection>  $sections
     * @return list<GraphNotice>
     */
    private function cycles(FormVersion $version, Collection $fields, Collection $sections): array
    {
        $edges = $this->dependencyEdges($version, $fields, $sections);

        return array_map(
            static function (array $members): GraphNotice {
                $described = '“'.implode('” ⇄ “', $members).'”';

                // EVERY member is a node, not just the one the DFS happened to enter the cycle from: a cycle
                // is a property of the set, and an author standing on any member needs to be told.
                return new GraphNotice(
                    GraphNoticeKind::Cycle,
                    $members,
                    'Circular condition — '.$described.' depend on each other, so the result depends on the order they settle in.',
                    $described,
                );
            },
            $this->findCycles($edges),
        );
    }

    /**
     * The "X depends on Y" adjacency over section keys ∪ field keys.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  Collection<int, FormSection>  $sections
     * @return array<string, list<string>>
     */
    private function dependencyEdges(FormVersion $version, Collection $fields, Collection $sections): array
    {
        /** @var array<string, list<string>> $edges */
        $edges = [];
        $known = [];
        foreach ($sections as $section) {
            $known[$section->key] = true;
        }
        foreach ($fields as $field) {
            $known[$field->key] = true;
        }

        $add = static function (string $from, string $to) use (&$edges, $known): void {
            if (! isset($known[$to])) {
                return;
            }
            $edges[$from][] = $to;
        };

        $sectionKeyById = [];
        foreach ($sections as $section) {
            $sectionKeyById[$section->id] = $section->key;
        }

        foreach ($this->relevanceNodes($fields, $sections) as [$nodeKey, $expression, , $containedIn]) {
            foreach ($this->referencesIn($expression) as $referenced) {
                $add($nodeKey, $referenced);
            }

            // Containment: a field is relevant only if its section is.
            if ($containedIn !== null) {
                $add($nodeKey, $containedIn);
            }
        }

        $fieldKeyById = [];
        foreach ($fields as $field) {
            $fieldKeyById[$field->id] = $field->key;
        }

        foreach ($version->validations()->get() as $rule) {
            if ($rule->rule_type !== ValidationRuleType::SkipIf && $rule->rule_type !== ValidationRuleType::SkipWith) {
                continue;
            }
            $owner = $fieldKeyById[$rule->form_field_id] ?? null;
            $related = $rule->related_form_field_id === null ? null : ($fieldKeyById[$rule->related_form_field_id] ?? null);
            if ($owner !== null && $related !== null) {
                $add($owner, $related);
            }
        }

        return $edges;
    }

    /**
     * Every cycle in the adjacency, each as its member list. A three-colour DFS: grey means "on the current
     * path", so meeting a grey node closes a cycle and the stack slice from that node is its membership.
     *
     * @param  array<string, list<string>>  $edges
     * @return list<list<string>>
     */
    private function findCycles(array $edges): array
    {
        /** @var array<string, int> $colour  0 = unvisited, 1 = on the path, 2 = done */
        $colour = [];
        /** @var list<string> $path */
        $path = [];
        /** @var array<string, true> $seen  cycles already recorded, keyed by their sorted membership */
        $seen = [];
        /** @var list<list<string>> $cycles */
        $cycles = [];

        $visit = function (string $node) use (&$visit, &$colour, &$path, &$seen, &$cycles, $edges): void {
            $colour[$node] = 1;
            $path[] = $node;

            foreach ($edges[$node] ?? [] as $next) {
                $state = $colour[$next] ?? 0;

                if ($state === 1) {
                    $at = array_search($next, $path, true);
                    if ($at !== false) {
                        $members = array_slice($path, (int) $at);
                        $sorted = $members;
                        sort($sorted);
                        $signature = implode("\x1f", $sorted);
                        if (! isset($seen[$signature])) {
                            $seen[$signature] = true;
                            $cycles[] = $members;
                        }
                    }

                    continue;
                }

                if ($state === 0) {
                    $visit($next);
                }
            }

            array_pop($path);
            $colour[$node] = 2;
        };

        foreach (array_keys($edges) as $node) {
            if (($colour[$node] ?? 0) === 0) {
                $visit($node);
            }
        }

        return $cycles;
    }

    /**
     * Every node that carries a `relevant_expression`, as
     * `[key, expression, position, owningSectionKeyOrNullForASection]`.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  Collection<int, FormSection>  $sections
     * @return list<array{0: string, 1: string, 2: array{int, int}, 3: ?string}>
     */
    private function relevanceNodes(Collection $fields, Collection $sections): array
    {
        $sectionById = [];
        foreach ($sections as $section) {
            $sectionById[$section->id] = $section;
        }

        $nodes = [];

        foreach ($sections as $section) {
            $expression = $section->relevant_expression;
            if ($expression !== null && trim($expression) !== '') {
                $nodes[] = [$section->key, $expression, TemplateScopeResolver::sectionPosition($section->sequence), null];
            }
        }

        foreach ($fields as $field) {
            $owning = $field->form_section_id === null ? null : ($sectionById[$field->form_section_id] ?? null);
            $position = TemplateScopeResolver::fieldPosition($owning?->sequence, $field->sequence);
            $expression = $field->relevant_expression;

            // A field with no expression of its own still DEPENDS on its section, so it must appear as a
            // node for the containment edge — with an empty expression, which `referencesIn()` returns [] for.
            $nodes[] = [$field->key, $expression ?? '', $position, $owning?->key];
        }

        return $nodes;
    }

    /**
     * Every `${key}` an expression names, or `[]` when it is blank or unparseable.
     *
     * Unparseable is unreachable on the publish path — `ExpressionValidationGate` runs inside `publish()` and
     * refuses first, so by the time this class sees a version every expression parses. It is caught anyway
     * because H21d1's read-derived canvas is specified to reuse these notices against a DRAFT, where it very
     * much is reachable, and an uncaught `ExpressionSyntaxException` there would 500 a builder page.
     *
     * @return list<string>
     */
    private function referencesIn(string $expression): array
    {
        if (trim($expression) === '') {
            return [];
        }

        try {
            return $this->parser->referencedKeys($this->parser->parse($expression));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * `[key => position, key => owning section key]` for every field and section.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  Collection<int, FormSection>  $sections
     * @return array{0: array<string, array{int, int}>, 1: array<string, ?string>}
     */
    private function positions(Collection $fields, Collection $sections): array
    {
        $sectionById = [];
        $positions = [];
        foreach ($sections as $section) {
            $sectionById[$section->id] = $section;
            $positions[$section->key] = TemplateScopeResolver::sectionPosition($section->sequence);
        }

        $owner = [];
        foreach ($fields as $field) {
            $owning = $field->form_section_id === null ? null : ($sectionById[$field->form_section_id] ?? null);
            $positions[$field->key] = TemplateScopeResolver::fieldPosition($owning?->sequence, $field->sequence);
            $owner[$field->key] = $owning?->key;
        }

        return [$positions, $owner];
    }
}
