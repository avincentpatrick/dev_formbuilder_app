# Piping & Output-Encoding Design Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** **v1.2 — AS-BUILT by H6a + H6b.** H6a amended §2.1, §3.3, §6, §6.2 and §7 where the drafted text turned out not to be implementable as written (**A1–A5**); H6b amended §3.2, §4, §3.3 and §6.2 where building the TypeScript half exposed a rule that was under-specified rather than merely unimplemented (**A7–A10**). Each amendment is recorded inline beside the clause it corrects, in the H8 "amend rather than implement the wrong thing" style. (**A6** is H6a's `engines: ["php"]` note in §7, discharged by H6b.) The four locked decisions are unchanged. Pins the **template grammar** for answer piping and the **output-encoding contract** every surface that renders a template owes. It does not re-derive the expression grammar (`docs/architecture/technical-architecture.md` §4.3, frozen at v2.0), the publish transaction (`docs/form-versioning-schema-migration.md` §3.2/§4), or the multi-language UX (`docs/ux/form-filling-ux-flow.md` §6) — it states how piping composes with each.
**Phase**: 3, per `docs/PRD.md`'s roadmap — the design entry for H6a → H6b/H7/H17/H21c.

---

## 1. What's Already Decided (not re-derived here)

- **The expression grammar is frozen at v2.0** and reference syntax is `${key}`: `ExpressionLexer::lexReference()` (`app/Services/Expressions/ExpressionLexer.php:161-189`) requires `${`, then `[A-Za-z_][A-Za-z0-9_]*` (`ctype_alpha`/`ctype_alnum` at `:172`/`:177`), then `}`; anything else is `malformedReference`. A bare `$` not followed by `{` is an `unexpectedToken` error. `docs/data-dictionary.md` §5 already documents `form_fields.key` as "referenced in expressions as `${key}`", and ADR-0004's criterion C1 scored `${field}` round-trip as a first-class XLSForm-interop requirement.
- **Two engines, held identical by golden vectors under Risk R3** (`docs/architecture/technical-architecture.md` §9). `ExpressionEvaluator::GRAMMAR_VERSION` (`app/Services/Expressions/ExpressionEvaluator.php:34`) and its TS twin (`resources/public-runtime/engine/evaluator.ts:21`) are both `'2.0'`, and both runners assert the version **per vector** (`tests/Unit/Expressions/GoldenVectorsTest.php:74`, `resources/public-runtime/engine/__tests__/golden-expressions.test.ts:59`) against `tests/golden/expressions/` (180 vectors) and `tests/golden/validation/` (106 vectors).
- **Publish-time reference resolution exists, for expressions only.** `ExpressionParser::assertReferencesResolve()` (`app/Services/Expressions/ExpressionParser.php:76`) walks a parsed AST and rejects any `${key}` not in a flat known-key set; `referencedKeys()` (`:128`) returns every distinct reference in first-seen order. Both are reached from `PublishService` step 1 (`app/Services/Forms/PublishService.php:56-57`) via `ExpressionValidationGate::assertExpressionsResolve()`. Neither reads `label`, `hint`, `description`, `placeholder`, or any `*_translations` column.
- **Answer → display string is already defined exactly once**, in `SchemaValueFormatter::displayValue()` (`app/Services/Submissions/SchemaValueFormatter.php:38`), shared by the submission inbox and the streamed export so choice-label resolution, `yes_no`, multi-select joining and geo formatting cannot drift between them.
- **Locale resolution is already defined**, and is never-blank: `resolveText()` (`resources/public-runtime/lib/schema-mapping.ts:113-117`) returns the locale's variant, or the base value when the variant is missing or empty. `docs/ux/form-filling-ux-flow.md` §6 fixes the respondent-facing semantics.
- **CSP is not a defence for this threat.** `PublicRuntimeSecurityHeaders` deliberately sets no `default-src`, `script-src` or `connect-src` (`app/Http/Middleware/PublicRuntimeSecurityHeaders.php`) and applies to four routes only. Output encoding is therefore the *sole* control against an injected template — not a layer of several. **Increment I1 does not change this.** It added `frame-ancestors *` to that middleware (an explicit statement of the embedding PRD Feature #3 requires) and a new `AppSecurityHeaders` on the authenticated groups carrying `frame-ancestors 'none'` — but `frame-ancestors` governs *who may frame the page*, not what script may run inside it, and it has no `default-src` fallback, so neither header narrows script execution by even one directive. The sentence above stands exactly as written.

---

## 2. The Template Grammar (v1.0)

**A template is not an expression, and template mode is not a mode of the expression grammar.** An expression yields a scalar and participates in relevance/constraint/calculate evaluation; a template yields **text** and participates in nothing. They share one lexeme — `${key}` — and nothing else.

Consequently piping ships a **sibling grammar** with its own version constant, `TEMPLATE_VERSION = '1.0'`, mirrored in PHP and TypeScript, with its own `tests/golden/templates/` vector set, its own manifest, and its own PHP⇄TS parity runner beside the two that exist. `ExpressionEvaluator::GRAMMAR_VERSION` **stays `'2.0'`**.

> **Why not a mode of the expression grammar.** Both golden runners assert `grammar_version` per vector, and the string `grammar_version` appears at **288 sites** across `tests/golden/` — 286 vectors plus two manifests. Bumping to `'2.1'` would rewrite every one of them in files whose semantics did not change, and would announce to Risk R3 that relevance/constraint evaluation moved when it did not. It would also falsify the corpus's own "**grammar stays v2.0**" marker (`docs/data-dictionary.md` §5 Design Notes, twice), which exists precisely so a reader can tell at a glance whether an increment touched the evaluator. A sibling version keeps that marker honest and makes the parity obligation additive rather than disruptive.

### 2.1 Production rules

A template is a sequence of **literal text** and **holes**:

```
template  ::= ( literal | escape | hole )*
hole      ::= '${' key '}'
key       ::= [A-Za-z_] [A-Za-z0-9_]*
escape    ::= '$${'                     -- yields the literal text "${"
literal   ::= any character sequence containing no unescaped '${'
```

- **`$${` is the only escape sequence, and it yields a literal `${`.** A `$` in any other position is ordinary literal text — so `"Amount in $"`, `"$5"` and `"a$b"` need no escaping at all. This is the one place template mode deliberately *diverges* from the expression lexer, which throws `unexpectedToken` on a bare `$`: inside prose a lone `$` is overwhelmingly a currency sign, and refusing it would make the escape mandatory in the common case rather than the rare one.
- The rule is total and unambiguous under leftmost-longest scanning, with **no escape-of-escape regress**: `$$${` scans as a literal `$` (because position 0 is not `$$` followed by `{`) followed by the escape `$${`, yielding `` $${ ``. There is no sequence a author cannot express.
- **A malformed hole is a publish-time error, never silently literal.** `${`, `${1abc}`, `${a-b}` and `${unterminated` are all rejected, reusing the `malformedReference` slug the expression lexer already emits. Fail-closed at publish; fail-open at render (§3.4).
- **Caps**, mirroring `ExpressionLexer.php:23-25`: a template may not exceed **2000 bytes** (the `MAX_EXPRESSION_LENGTH` figure) and may not contain more than **20 holes**, bounding render cost per field on a page that may hold hundreds. Both are measured on a **hole-bearing** value only — see the amendment below. The hole cap is "no *more than* 20", so exactly 20 passes and the 21st is refused, mirroring `ExpressionLexer`'s strict `>` after append. Two distinct slugs, `template_too_long` and `too_many_holes`: `ExpressionLexer` overloads one slug for both of its caps and passes the byte length in either case, so a short token-heavy expression reports a misleading number; the template grammar does not replicate that.

> **Amendment A1 (H6a, as-built): the caps bind only a value that contains at least one `${`.** As originally drafted this clause read on every template-bearing value, and the parenthetical claimed the column types bind more tightly anyway. **That claim is false for four of the nine columns**: `form_fields.hint` and `form_sections.description` are `TEXT`, every `*_translations` value is an unbounded `jsonb` string, and `forms.confirmation_message` is `TEXT`. Their only bound is the FormRequest's `max:2000`, which counts **characters** rather than bytes — and which `XlsformImporter` and `SchemaBlueprintMaterializer` bypass entirely, writing through `Model::create` with no request in the path. Applying the cap unconditionally would therefore refuse to publish a form for an over-long hint that predates piping, breaking §6.2's "additive for every existing form" posture over a value piping never touches. Since the caps exist to bound *render* cost and a hole-free template has none (it is returned unchanged), the exemption costs nothing. Implemented as a `str_contains($t, '${')` early return, which still cap-checks an escape-only template because `$${` contains `${`. Pinned by `literals.json`'s `lit_hole_free_over_byte_cap_passes` vector.

### 2.2 What a template is *not*, in v1.0

No operators, no function calls, no nested holes, no filters or formatters (`${total|currency}`), no inline defaults (`${name ?? 'there'}`), no conditionals. A hole is a bare key reference and nothing more. Each of those is a v1.1 revisit trigger, and each would be a real grammar change requiring its own golden vectors — not a quiet addition.

**Revisit trigger:** the first genuine authoring request for a default value ("Hello ${name}" reading "Hello ," for an unanswered optional field). §3.4's empty-string rule is the deliberate v1.0 answer.

> **Note for implementers.** PHP 8.3 removed `${var}` string interpolation, so every grammar snippet in a PHP test must be **single-quoted** or the literal will interpolate away before the lexer sees it (`PROGRESS.md`'s standing gotcha list). A vector that silently loses its `${`s tests nothing.

---

## 3. Reference Resolution

### 3.1 Which field types may be a piping source

The classification is **total over all 31 `FieldType` cases** and, like `App\Enums\OcrFieldEligibility`, is expressed as a `match` with **no `default` arm** — so adding a 32nd field type without classifying it is a PHPStan-level-8 error ("Match expression does not handle remaining values"), merge-blocking in the `static-analysis` CI job.

**This must be its own classification, not a reuse of an existing predicate.** Every general `FieldType` predicate carries a `default =>` arm — `isAdvanced()` (`app/Enums/FieldType.php:129`), `hasOptions()` (`:138`), `configEditor()` (`:161`), `isMedia()` (`:200`) — so a rule composed from them absorbs a new type as pipeable, silently. That is exactly the defect H8 removed from `ocr_compatible`, and it must not be reintroduced one increment later.

`OcrFieldEligibility` is also **not** reusable, because piping's predicate genuinely differs from OCR's in two places. Piping asks: *is this answer a scalar that reads sensibly inside a sentence, and does a value exist for it before the consuming text renders?*

| Verdict | Types | Why |
|---|---|---|
| **pipeable** (19) | `short_text`, `long_text`, `email`, `phone`, `url`, `integer`, `decimal`, `date`, `time`, `datetime`, `duration`, `single_select`, `multi_select`, `dropdown`, `yes_no`, `cascading_select`, `likert_scale` | Respondent-supplied scalars — exactly `OcrFieldEligibility::Extractable`. |
| | `calculated` | **Differs from OCR, deliberately.** OCR excludes it because a scanned value could only contradict the formula; piping is the opposite case — a running total is one of the most valuable things to pipe, and it is a scalar the engine has already computed. |
| | `hidden` | **Differs from OCR, deliberately.** OCR calls it `Neutral` (the paper never carries it); piping is its primary consumer — a URL-prefilled first name (H7) exists *before* the first label renders. It arrives as **server-constrained untrusted input** (H7), which raises the stakes on §5, not lowers them. **As-built (H7):** the constraint is `App\Enums\PrefillSource` — a `fixed` field is written server-side from `default_value` and the client value is never inspected; a `url` field is the only client-writable one, scalar-only under a 2000-byte cap, and opt-in per field. So a piped hidden value is untrusted exactly when the author said it could be, and §5 owns its escaping at the point of render. |
| **no answer** (2) | `note`, `page_break` | Hold no answer at all — exactly `SchemaValueFormatter::NON_DATA`. Instructional prose and pagination have nothing to contribute. |
| **excluded** (10) | `matrix`, `likert_matrix` | Object-valued (`{row:{col:cell}}` / `{row:score}`); already banned as expression operands by `ExpressionValidationGate`. One rule now governs both reference kinds. |
| | `geopoint`, `geotrace`, `geoshape` | Object-valued GeoJSON envelopes, already banned as expression operands. `displayValue()` *can* format them (`"lat, lon (±m)"`), so this is a deliberate scope boundary rather than an impossibility — **revisit trigger:** a stated need to pipe a captured location into prose. |
| | `file_upload`, `image_capture`, `audio_capture`, `video_capture`, `signature` | The stored answer is a list of attachment-reference envelopes. `displayValue()` would fall through to its `json_encode` scalar fallback and render machine noise into a question. |

> **A gap this classification must not inherit.** `isMedia()` types are **not** currently banned as expression operands — `ExpressionValidationGate` bans only `isComposite()` and `isGeo()` (`app/Services/Forms/ExpressionValidationGate.php:47-54`). Piping excludes media explicitly rather than by inheriting a ban that does not exist. Whether the *expression* gate should also ban them is a separate question, recorded in §8.

### 3.2 What a hole renders to

**The rendered value of a hole is exactly `SchemaValueFormatter::displayValue($type, $answer, $config)`.** Reused, never reimplemented — so a choice code renders as its author-defined label, `yes_no` as `Yes`/`No`, and a multi-select as its `'; '`-joined labels, identically in a piped label and in an export cell.

**This is the real R3 hazard in H6a/H6b, and it is not the parser.** A hole parser is trivially mirrorable; `displayValue()` has **no TypeScript twin today**, so H6b must build one, and every formatting choice in it becomes a new drift surface. The template golden vectors must therefore cover **value formatting**, not merely parsing. Two specific traps:

- `Coercion::toStr()` explicitly does **not** pin cross-engine byte-parity for a non-integral float (`app/Services/Expressions/Coercion.php`, the documented checksum caveat). It must **not** be the primitive used to render a `decimal` or `calculated` hole — `displayValue()` must be, with its decimal rendering pinned by vectors on both sides.
- `displayValue()` resolves choice labels from `config.options`, which is per-field state a naive TS mirror will forget to thread through.

> **Amendment A7 (H6b, as-built): `displayValue()` itself was not mirrorable, and had to be corrected rather than merely copied.** This clause said "reused, never reimplemented", which is right in spirit and impossible in fact — the TypeScript side cannot reuse a PHP method, only mirror it, and the method as written ended in a bare `(string)` cast whose output JavaScript **cannot reproduce**. Two branches diverge, and both are reachable rather than theoretical: `ExpressionEvaluator::normalize()` passes a native bool straight through, so a `calculated` field holding `${age} >= 18` stores one — PHP casts it to `'1'`/`''` where `String()` gives `'true'`/`'false'`; and any `calculated` field doing division holds a non-integral float — PHP's `precision=14` gives `'0.3'` for `0.1 + 0.2` where JavaScript gives `'0.30000000000000004'`.
>
> H6a's only float vector was `3.5`, exactly representable in binary, which **concealed** the whole problem while appearing to pin it. So H6b added a private `SchemaValueFormatter::scalarString()` and mirrored it in `display-value.ts`: `bool` → `'1'`/`''`; a non-finite float → `''`; an integral float → no fractional part; otherwise fixed notation to ten decimals with trailing zeros trimmed (`sprintf('%.10F')` / `toFixed(10)`). The rule deliberately avoids PHP's `precision`/`serialize_precision` ini — runtime-configurable, so the old cast was not even stable across deployments — and avoids E-notation on both sides. Verified equal across every probed value in both containers.
>
> **This does not contradict the `Coercion::toStr()` bar; it is what the bar was pointing at.** `toStr` is barred *because* its float branch is unpinned. `displayValue()` is now the pinned primitive the clause always intended.
>
> **One accepted divergence, with a precise boundary:** at |v| >= 1e21 JavaScript's `toFixed` falls back to exponential notation per spec where `%.10F` does not. Hence the `< 1e21` guard — it documents where the mirror stops rather than optimising anything. No form arithmetic reaches it, and **no vector may pin a value there**. A second, unfixable one is recorded in `display-value.ts`: a `yes_no` answer of JSON `1.0` renders `No` in PHP (`1.0 === 1` is false) and `Yes` in TypeScript (JSON `1` and `1.0` are the same number). Never author a `1.0` yes_no vector.

### 3.3 Scope rules — ordering and repeats

Four predicates, all decidable at publish from the frozen snapshot (`SchemaSnapshotSerializer` emits `key`, `section_key`, `sequence` and `section_sequence` for every field):

1. **Backward reference only.** A hole may reference only a field that *precedes* its own host in document order. A forward reference is permanently empty, which is an authoring bug the gate can see. Applied uniformly, including to `hidden` and `calculated` — both belong ahead of their consumers anyway (a prefill field at the top, a running total after its operands). **Revisit trigger:** if authors find placing a `hidden` prefill field first unnatural, exempt the position-independent sources rather than dropping the rule.

   > **Amendment A2 (H6a, as-built): the ordering tuple.** This clause originally named `(section_sequence, sequence)`. That is not implementable as written. `form_fields.section_sequence` is `integer NULLABLE`, documented in `docs/data-dictionary.md` as "order **within** its section", and is null for nearly every row in practice — `FieldLibrary`, `SchemaBlueprintMaterializer` and the test fixtures all write null, and only the reorder endpoint sets it. A section's own position lives on `form_sections.sequence`. So the literal tuple inverts the hierarchy *and* sorts mostly-nulls. The implementable form of the same intent is **(the owning section's `form_sections.sequence`, the field's own `form_fields.sequence`)** — both `integer default 0` NOT NULL, and the order every existing consumer already renders in (`EncodeFormPresenter`, `SubmissionInboxPresenter` and `SubmissionExporter` each group by section and sort by `sequence`). Positions are compared strictly and lexicographically: a section-less field is `(-1, sequence)` (ungrouped fields lead), a sectioned field is `(section.sequence, sequence)`, and a section's own label/description is `(section.sequence, -1)` — a heading precedes its members. A **tie is a rejection**: two fields at the same position have no defined render order, so a reference between them is not provably backward. Real forms are unaffected (`FormBuilderService` mints `max(sequence) + 1`, the reorder endpoint writes a dense index, XLSForm import uses row order), but a *test fixture* that leaves `addFormField()`'s `sequence` at its `0` default will trip it.
2. **Same-instance repeats.** A hole inside repeatable section *S* referencing a field in *S* resolves against the **current instance**. Referencing a field outside every repeatable section is fine (one value).
3. **No cross-repeat and no repeat-to-flat.** A hole inside repeat *S* referencing a field inside a *different* repeat *T* is **rejected at publish** — there is no instance to pick. So is a hole in non-repeat context referencing a repeat-scoped field: it names N values, not one.
4. **A section key is not a template hole.** `${roster}` is legal in an *expression* (it resolves to a `list<instanceMap>` for `count()`) and is illegal in a template, which has no text rendering for a list of instance maps.

> **Why template validation cannot simply call `assertReferencesResolve()` unchanged.** Its known-key set is a **flat union of every field key and every section key, carrying no scope information** (`ExpressionValidationGate.php:47-61`, where the fields loop and the sections loop both write into one `$knownKeys` map). Passing a template's references through it would accept `${roster}` in a label (rule 4) and accept a cross-repeat reference (rule 3), because neither is expressible in that set. H6a reuses the *walk* and the exception envelope; the predicate is template-specific.

### 3.4 Unresolved at render time

**A hole whose source has no answer renders as the empty string** — never the raw `${key}` token, never `undefined`, never a placeholder glyph. This mirrors the evaluator's existing submission-time contract (an unknown key evaluates to EMPTY and never throws), and it keeps the two failure modes cleanly separated: **publish** is where an unresolvable *reference* is refused, **render** is where an unanswered *value* is tolerated.

Rendering must never fail. A template that reached a published snapshot was validated; if a renderer nonetheless meets a malformed template (a hand-edited row, a snapshot from a newer `TEMPLATE_VERSION`), it emits the literal text with holes dropped rather than throwing — a question with a gap in it is recoverable, a 500 on a respondent's form is not.

---

## 4. Multi-Locale Templates

Every respondent-facing text column has a `{column}_translations` sibling (`docs/data-dictionary.md` §4/§5), so a piped label is really *n* templates — one per locale, plus the base value.

- **Each locale variant is independently a template, and each is independently validated at publish.** All of §2 and §3 apply per variant.
- **Reference-set parity across locales is NOT required.** Different grammars legitimately need different references — a locale may repeat a name, or omit it where the sentence reads better without. The decidable rule is weaker and sufficient: *every hole in every variant must resolve*.
- **Order is normative: resolve the locale, then render the template.** `resolveText()` picks the variant (or falls back to the base, never blank — `schema-mapping.ts:113-117`); only then are that string's holes filled. Rendering before resolving would fill holes into a string the respondent is not going to see. This is the composition rule `docs/ux/form-filling-ux-flow.md` §6 needs in order to stay true once labels contain holes.
- A fallback that carries a hole the selected locale's variant does not is therefore **not** an error — it is the base template, rendered normally.

> **Amendment A8 (H6b, as-built): the order clause bound the sentence but not the VALUE dropped into it.** "Resolve the locale, then render" was stated for the template, and `displayValue()` had **no `$locale` parameter at all** — it read `config.options[].label` raw and ignored `label_translations` entirely. So a Filipino respondent read a Filipino sentence with an **English choice label** injected mid-clause, and worse, the tenant's inbox and the respondent's own screen disagreed about the same answer, because each resolved a different half of it.
>
> `displayValue()`, `options()` and `TemplateRenderer::render()` now take an optional `?string $locale`. The inbox passes the submission's own locale, the exporter passes the export's (one spreadsheet, one language — a column of per-respondent languages would be unusable for analysis, and the respondent's locale is already its own column), and the SPA passes the active one. **Null keeps every pre-H6b caller byte-identical**, which is what made this safe to add to two shipped read surfaces.
>
> **Two fallbacks at two levels, deliberately different**, and the difference is vector-pinned on both sides: the LOCALE variant falls back when missing, non-string **or blank** — `resolveText()`'s "never blank" rule, which this clause already made normative — while the BASE label falls back to the option's value on **null only**, so an author's explicit `"label": ""` still wins. Collapsing them into one rule breaks one case or the other.
>
> Note the scope: this binds a piped choice label, which §6's closed column list still **excludes** as a template-bearing column in its own right. Resolving a translation is not the same as rendering a template into it, and §8.1's exclusion stands.

> **A validation gap, and how much of it H6a actually closed.** No `*_translations` column had a validation rule anywhere: a repo-wide search of `app/Http/Requests/` for `translations` returned nothing, and the only writers are XLSForm import, the schema-blueprint materializer and the field library — the builder UI cannot author translations at all today.
>
> **As built:** every one of the nine template-bearing columns is now validated **at publish**, base value and each locale variant independently, by `TemplateValidationGate`. At **request** time only `forms.confirmation_message[_translations]` gains a rule — `UpdateConfirmationMessageRequest`, the first FormRequest in the repo to mention `translations` at all. The other four `*_translations` columns deliberately get none, because they have **no FormRequest ingress to attach one to**: `BuilderPresenter` emits no `*_translations` key and `ConfigPanel.vue` has no translation input, so the builder can neither author nor display them, and their three writers all go through `Model::create`. Adding rules to requests that never see the data would be theatre; the publish gate is their real guarantee. Locale codes remain free-form `varchar(10)` with no closed enum — noted, still not fixed here.

---

## 5. The Output-Encoding Contract

**A respondent's answer is fully untrusted text.** Piping is what carries it out of the answer store and into a *question*, an email, a PDF and a chat message — surfaces whose escaping was previously irrelevant because nothing untrusted reached them.

**The contract binds every untrusted value on these surfaces, not only piped ones.** An escaper cannot tell where a string came from, and a rule scoped to piped values would license H6a to escape the piped answer while leaving the form title raw beside it in the same Slack message. Tenant-authored strings (form title, field label, endpoint name) and third-party strings (a Slack workspace name) are in scope on exactly the same terms.

**Escaping happens at the point of render, in the context being rendered — never at store, and never once "centrally".** Storing escaped text corrupts the data for every other consumer, and one canonical escape is wrong for at least three of the contexts below.

| Surface | Context | Required escaper | Owner | Required test |
|---|---|---|---|---|
| Guest SPA + encode page | DOM text | Vue text interpolation / prop binding — already correct throughout | ✅ **H6b (holds)** | ✅ `components.test.ts` — a piped answer of `<script>alert(1)</script>` renders as visible text, adds no `<script>` node, and appears HTML-escaped in the markup |
| Blade shells (`app.blade.php`, `public-runtime.blade.php`) | HTML text + attribute | `{{ }}` only. Zero `{!!` exists in application code today; attribute values follow `app.blade.php`'s **whitelist** precedent rather than blind interpolation | — (holds) | existing header/shell tests |
| Inertia `data-page` | `<script type="application/json">` | **A dependency to preserve, not a defect.** Inertia's directive emits `{!! json_encode($page) !!}`, and it is safe *because* `json_encode` escapes forward slashes by default, so `</script>` serialises as `<\/script>`. Any future `JSON_UNESCAPED_SLASHES` on that path opens a breakout | — (holds) | H6a: assert a `</script>`-bearing answer cannot close the block |
| Queued PDF (H17) | HTML text + attribute | `htmlspecialchars(ENT_QUOTES, 'UTF-8')` at every interpolation — delivered as Blade `{{ }}`, which *is* that call. `isRemoteEnabled = false` + `isPhpEnabled = false` as pinned, plus `isJavascriptEnabled = false` and a `chroot` to the view directory | ✅ **H17 (as-built)** | ✅ `tests/Feature/Submissions/SubmissionPdfRendererTest.php` — a markup answer renders as visible text; the form title, section heading and a **piped** label are each asserted separately; an attribute-breaking `" onload="` payload covers the `ENT_QUOTES` half; the engine flags are asserted through dompdf's own getters; and a structural guard scans every `resources/views/pdf/*.blade.php` for a raw-output directive |
| Queued mail (H3/H23) | Markdown → HTML | Enable `Markdown::withSecuredEncoding()`. Script execution is currently blocked only *incidentally*, by `EncodedHtmlString`'s `htmlspecialchars`; secured encoding is what neutralises **markdown syntax** | ✅ **CLOSED by H23a4** — `AppServiceProvider::boot()` | Pinned by `BrandedMailRenderTest`: a tenant named `[Reset your password](https://evil.example)` renders as literal text, no anchor and no `<img>`. **Residual, stated rather than implied:** secured encoding escapes `[`, `<` and `>` only — `*`, `_` and backticks stay live, so an adversarial name can still render italic or as a code span. That is typography; both vectors this row names need `[`. |
| Slack Block Kit (H6a) | `mrkdwn` | Escape `&`, `<`, `>` per Slack's own rules before interpolation | **H6a** | H6a: a form titled `<https://evil.example\|click>` is not a live link |
| CSV / XLSX export | spreadsheet cell | Prefix a cell whose first character is `=`, `+`, `-`, `@`, TAB or CR | **its own row** | that increment: `=HYPERLINK(…)` is inert on open |
| Webhook body | JSON | JSON encoding, which is already the only transform applied. Payloads carry ids and metadata, never answers; `include_answers` stays deferred | — (holds) | existing signature/payload tests |
| Plain-text mail · a11y announcer | plain text | None — and no HTML either. Recorded so a later increment does not silently "upgrade" one of them to an HTML context | — (holds) | — |

**As-built note (H17) — the two pinned dompdf flags turned out to be the whole mitigation.** When
this table was written the engine had not been chosen and the flags were defence-in-depth. Choosing
it made them load-bearing: of the **20 published advisories against `dompdf/dompdf`**, essentially
every one is an SVG parse, a remote font fetch, a remote file inclusion or an image-handling path,
and all of them require remote loading. `isRemoteEnabled = false` closes that entire class. Two
supporting facts recorded so a later increment does not undo them by accident:

- **The version floor is a gate, not a preference.** `composer.json` pins `^3.1.6` because 3.1.6 is
  the first release above every published advisory bound (the highest is `<3.1.6`), and CI runs
  `composer audit` **full-tree, with no severity floor and no `--omit`** — so loosening the
  constraint is a hard merge block rather than a silent downgrade. Its sub-dependencies are the
  *renamed* `dompdf/php-font-lib` and `dompdf/php-svg-lib`, which carry **zero** advisories; the
  legacy `phenx/*` packages of the same name do carry them and are **not** what 3.1.6 installs.
- **`ext-gd` is absent from the application container**, so raster image embedding is unreachable
  regardless of configuration. The "no images in the PDF" narrowing is therefore an environment
  fact, not only a design choice — and adding gd later re-opens the image-handling advisory class
  that `isRemoteEnabled = false` currently makes moot.

**Three of those rows described live defects, not hypotheticals** — the Slack `mrkdwn`, mail-markdown and spreadsheet rows were unescaped, for the form title and tenant name, before piping added a single character. **The mail-markdown one is now CLOSED (H23a4, the increment that built the mail template layer this row was waiting on); the spreadsheet row remains open.** Piping raises their severity from "a tenant can garble their own notification" to "a respondent can inject into a tenant's channel", which is why each carries a named owner here rather than an observation.

**The one permitted raw-HTML sink in the entire codebase** is `resources/js/components/settings/TwoFactorSetup.vue:110`, whose `v-html` renders a Fortify-generated 2FA QR SVG. It is the sole `v-html`/`innerHTML` in `resources/`, it is server-generated and same-origin, and **it must never be given form content**. Any second raw sink is a contract change, not an implementation detail.

### 5.1 How this is enforced, and what that does not cover

Enforcement is **per-surface: one escaping test, owned by the increment that owns the surface**, listed in the table above and registered in `docs/testing-strategy.md` §3 so the obligation lives in the gate document rather than only in this one.

Stated plainly: this is a **convention with test coverage, not a compiler error.** A surface added after this document is written escapes correctly only if its author reads the table. The stronger alternative was considered and not taken — a renderer returning a value object with no `__toString()` and one method per context (`forHtml()`, `forSlackMrkdwn()`, …), which would make a forgotten escape a PHPStan-level-8 failure the way `OcrFieldEligibility`'s `default`-less match makes an unclassified field type one. That option remains available and is the natural response if a *second* surface is ever found unescaped after this contract lands. The residual is recorded in `docs/security-threat-model.md` §9 item 9 rather than left implicit.

---

## 6. Publish-Time Validation

Template validation is a **third gate** in `PublishService` step 1, alongside the two that already run there (`app/Services/Forms/PublishService.php:56-57`), running **before the step 3+4 snapshot freeze**. Shipped as `App\Services\Forms\TemplateValidationGate`.

> **Amendment A5 (H6a, as-built): what the ordering actually buys.** This paragraph originally said the gate "must run before the freeze, so a rejected template can never reach `schema_snapshot`". The *conclusion* is right but the *reason* was wrong, and the difference matters to anyone maintaining the gate. The whole publish is one `DB::transaction`, so a throw anywhere inside it rolls the snapshot write back — **the transaction, not the ordering, is what guarantees no rejected template reaches a committed `schema_snapshot`.** This was proven by mutation during H6a: moving the gate call below the freeze leaves every gate test green, including the one asserting the version is still a draft with an empty snapshot. What the ordering genuinely buys is that step 1 stays the single validation phase and a doomed publish is never serialized. It is therefore a code-structure convention rather than a tested invariant, and no honest test can pin it: the ordering has no observable effect inside the transaction, and `SchemaSnapshotSerializer` is `final` with `PublishService` type-hinting it, so it cannot be spied on either. Recorded rather than papered over with a test that would pass for the wrong reason.

New clauses, added to `docs/form-versioning-schema-migration.md` §4's gate:

- Every template-bearing value parses under `TEMPLATE_VERSION` (§2), in the base value **and** every locale variant.
- Every hole's key resolves to a field in the version being published, and that field's type is **pipeable** (§3.1).
- Every hole satisfies the ordering and repeat-scope predicates (§3.3).

**Template-bearing columns** (the closed list for v1.0): `form_fields.label`/`label_translations`, `form_fields.hint`/`hint_translations`, `form_fields.placeholder`, `form_sections.label`/`label_translations`, `form_sections.description`/`description_translations`, and the net-new `forms.confirmation_message`/`confirmation_message_translations` (§6.2). `form_fields.default_value` is **not** template-bearing — it already has an expression mode (`default_value_is_expression`), and giving one column two sub-grammars is how ambiguity gets built in.

Violations reuse the `App\Exceptions\Forms\PublishValidationException` static-factory envelope, whose contract is already "the publish is refused and the *specific* violation is surfaced so the builder UI can point at the offending field" — via a new `templateInvalid($ownerKey, $column, $detail)` factory. `$column` names *which* text (`label`, `hint`, `placeholder`, `description`, `confirmation_message`, or a locale variant such as `label[fil]`), because one field can carry several templates.

> **Amendment A4 (H6a, as-built): where slugs are asserted.** The original sentence promised "stable snake_case slugs, so golden vectors match slugs and never wording". `PublishValidationException` is **message-only** — fourteen static factories, no `slug()`/`code()`/`fieldKey()` accessor — so that is not satisfiable at the publish envelope. It *is* satisfiable one level in: `TemplateSyntaxException::slug()` and `TemplateScopeResolver`'s violation slugs are both stable snake_case, and the factory interpolates the slug into the message so a test can match on it. Only **grammar** errors become golden vectors; eligibility and scope violations need a version, a field graph and a repeat topology that a language-neutral JSON vector cannot carry — and the TypeScript side has no twin of `assertReferencesResolve()` at all — so those are pinned by PHP-only publish-gate tests instead. Vectors cover what has two implementations; that is what R3 is for.

### 6.1 The change-classification knock-on

`docs/form-versioning-schema-migration.md` §5 classifies "label/hint/placeholder text changes" as **Non-breaking (recorded, never warned)**. That stops being true the moment a label can contain a hole: editing a label from `"Age"` to `"Age of ${child_name}"` introduces a reference that can dangle, and deleting the field a *published* label pipes from is a change to how that question reads. The classification stays Non-breaking for text without holes; a text change that **adds, removes or repoints a hole** is classified with the reference change, not with the prose.

### 6.2 The confirmation screen (a net-new surface)

`docs/PRD.md`'s Phase-3 scope credits piping with confirmation-screen coverage, but **there is no author-editable confirmation message anywhere in the product**: the copy is three hardcoded constants in `resources/public-runtime/App.vue:29-31`, and no `confirmation_message` column exists in any migration. Piping the respondent's own answers into a thank-you screen — the single most-wanted piping use case — therefore needs storage first.

**Assigned to H6a**: `forms.confirmation_message` (`text`, nullable) + `forms.confirmation_message_translations` (`jsonb`, nullable), template-bearing per §6, with the builder input and the presenter plumbing. When null, the existing hardcoded default stands, so the change is additive for every existing form. Note the consequence: it is a `forms` column, not a version column, so it is **not** frozen per version.

> **Amendment A3 (H6a, as-built): which version it is validated against.** The original clause said "the *currently published* version". That is backwards at the only moment the check runs: inside `PublishService`, the draft **is about to become** the currently published version, so validating against the outgoing one would reject a hole naming a field the new version *adds* and accept a hole naming a field it *deletes*. Validated against **the version being published**.
>
> **And the split it forces.** Because the column lives on `forms`, it is editable at any moment through `PATCH /forms/{form}/confirmation` without a publish — and a form whose only version is a draft has no published key set to resolve against at all. So the check is split by decidability: the request rule (`App\Rules\ValidTemplate`) validates **grammar only**, which is context-free and always decidable; the publish gate resolves references, eligibility and scope. The residue is honest and small: an edit made *after* the last publish can introduce a reference that dangles until the next publish refuses it. A dangling hole renders as the empty string and never throws (§3.4), so the failure is cosmetic rather than an outage — but the surprise is real (publishing v4 can fail because of a message edited weeks earlier, with the error naming `confirmation_message`). Closing it belongs to **H6b**, which owns the confirmation surface; the marker is on `FormService::setConfirmationMessage()`.

---

## 7. Required Test Vectors

Two distinct obligations, both merge-blocking.

**Grammar parity (R3).** `tests/golden/templates/` with its own `manifest.json` carrying `template_version` and a total, mirroring `tests/golden/expressions/manifest.json`'s shape and its count-parity guard, run through the PHP template parser (Pest) and the TS twin (Vitest). Coverage must include: the `$${` escape and `$$${`; a bare `$` as literal; every malformed-hole form; multiple holes in one template; the hole cap; **and value formatting per §3.2** — choice-label resolution, `yes_no`, multi-select joining, and a pinned `decimal` rendering.

> **As built (H6a): 53 vectors across five files**, plus **amendment A6 on how the formatting half runs.** `literals.json` (9) · `holes.json` (12) · `escapes.json` (8) · `parse-errors.json` (11) · `formatting.json` (13). Runners: `tests/Unit/Templates/TemplateGoldenVectorsTest.php` and `resources/public-runtime/engine/__tests__/golden-templates.test.ts`, each re-implementing the three manifest guards inline (the house convention — the two existing runner pairs duplicate rather than share a loader, and a shared one is worth extracting at a fifth corpus, not a third).
>
> The formatting vectors carry `"mode": "render"` and **`"engines": ["php"]`**, and the TypeScript runner skips their assertion while still counting them in the guard. This is a real tension in the drafted text: §7 requires the corpus to cover value formatting, but §3.2 assigns `displayValue()`'s TypeScript twin to **H6b**, so a dual-engine formatting vector would red the Vitest job today. Running them PHP-only now is strictly better than deferring them, because `SchemaValueFormatter` has **no direct unit test at all** — choice-label resolution, `yes_no`, the `'; '` join and every numeric rendering were entirely unpinned before H6a. H6b drops the `engines` key and they become dual-engine unchanged. Two traps the vectors pin deliberately: `boolLabel()`'s truthy set is a **case-sensitive closed list** (so `'yes'` is Yes and `'Yes'` is No), and the **same field type holds two runtime types** — a respondent's `decimal` persists as the submitted string (`'3.50'` keeps its trailing zero) while a `calculated` value is a native float, so a vector must state which it pins.
>
> > **A6 discharged (H6b, as-built): the corpus is DUAL-ENGINE and `engines` is gone from the tree.** All 13 render vectors passed the new TypeScript twin unchanged on the first run, which is the R3 contract holding. The corpus grew **53 → 76**: `formatting.json` 13 → 36, every other file byte-untouched, and both sibling corpora untouched with `grammar_version` still at exactly 288 sites. The new vectors pin A7's scalar rule, A8's locale resolution, and five gaps H6a left unpinned — `cascading_select` label resolution (pipeable and previously uncovered), `likert_scale` with an integer option value, an empty-string option label, an unanswered `yes_no` (branch ORDER: blank, not `No`), and a `calculated` zero. The render vector shape gained an optional `locale` key, read by both runners.
> >
> > Two runner corrections: the TypeScript side had **no render branch at all** — its `expected` was cast to a segment array, so simply deleting the `engines` key would have failed all 13 on a shape mismatch, not run them — and it was missing the fourth guard below, which is itself runner drift since the runners *are* the enforcement mechanism. It also gained a fifth: a render vector must carry the `sources`/`answers` it renders from, because one whose inputs were forgotten renders every hole empty and can still match a mostly-literal `expected`.
> >
> > **One vector was found green for the wrong reason, by mutation.** `fmt_hole_named_constructor_renders_empty` exists to pin that a hole key like `${constructor}` cannot reach `Object.prototype` through a bare `sources[key]` lookup — a hazard PHP structurally cannot have. With no answer for that key, the empty guard short-circuited before the unsafe lookup mattered, so the vector passed against a prototype-unsafe mirror too. It now carries an answer. Worth generalising: **a vector whose expectation is reachable by more than one path is not pinning what its name claims.**
>
> A fourth guard beyond the manifest three: **every vector must declare exactly one expected outcome.** The existing runners default optional keys with `?? []`, which lets a vector asserting nothing pass vacuously; this corpus refuses that explicitly.

**Injection vectors, one per output context** (§5), each asserted by the increment that owns its surface:

| Answer value | Context | Asserted outcome |
|---|---|---|
| `<script>alert(1)</script>` | HTML (PDF, Blade, DOM) | rendered as visible text; no element created |
| `[click](https://evil.example)` | markdown mail | literal text; no anchor |
| `<https://evil.example\|click>` | Slack `mrkdwn` | literal text; no link |
| `=HYPERLINK("http://evil","x")` | CSV / XLSX cell | inert on open |
| `</script>` | Inertia `data-page` | block not closed |

> **PR note.** `gitleaks` scans the whole tree on every CI run, so a vector must not resemble a credential — keep them markup and formulae, never token-shaped strings.

---

## 8. Out of Scope / Deferred

- **Template filters, formatters, defaults and conditionals** (§2.2) — each is a v1.1 grammar change with its own vectors.
- **Piping geo and media answers** (§3.1) — `displayValue()` can format geo today; the exclusion is a scope boundary with a stated revisit trigger, not an impossibility.
- **Banning `isMedia()` types as *expression* operands.** Today `ExpressionValidationGate` bans only composite and geo, so a media key is a legal expression operand while being an illegal template hole. That asymmetry is pre-existing and is not resolved here; it belongs with whichever increment next touches the expression gate.
- **`${…}` references inside an XLSForm `label` cell on import/export.** `docs/xlsform-interop-spec.md` §2 maps `label` to `label_translations` without saying anything about references inside the cell, and XLSForm's own idiom for piping is exactly `${}`. Related and worse: `XlsformImportParser::sanitizeKeys()` renames imported keys **without rewriting `${…}` references inside expressions** — a pre-existing dangling-reference hazard that templates now inherit. Both belong to Doc #16's next revision.
- **CSP `script-src`/`default-src` hardening.** The strongest available defence-in-depth for this threat class and deliberately not bundled here — introducing `default-src` forces every other directive to be enumerated at once, and `PublicRuntimeSecurityHeadersTest` asserts the *absence* of `font-src` specifically so that a future hardening PR is forced to add it in the same change (`docs/ux/exceptions-log.md`). **Still deferred after I1**, and the tripwire is intact: I1's `frame-ancestors` addition was chosen partly *because* it is the one directive that can be stated alone without triggering the enumeration cascade, and the four negative assertions (`default-src`, `script-src`, `connect-src`, `font-src`) are unchanged.
- **A rich-text / media content block** (`docs/feature-backlog.md` §3) would be a *second* HTML-in-labels sink with a genuinely different contract — permitting a safe subset of markup rather than escaping all of it. This document's escape-everything rule is not a precedent for it.
- **Piping into webhook payloads.** Envelopes carry ids and metadata by design; the per-endpoint `include_answers` opt-in stays deferred, with `config/webhooks.php`'s payload-archive threshold as its seam.
- **A printed OCR form has no answers at print time**, so a template hole on paper renders empty by §3.4. H18a/H19 should treat a piped label as prose with a gap rather than attempting substitution — recorded so it is not rediscovered mid-increment.
- ~~**H7's hidden-field contract**~~ — **STATED by H7**, where this document said it belonged. It lives in `docs/data-dictionary.md` §5's H7 Design Note (the `config` schema + source authority + the byte cap) and `docs/form-versioning-schema-migration.md` §4 (the four publish refusals), not here. Two consequences land back on this document and are recorded in §3.1's `hidden` row: a piped hidden value is client-writable **only** when the author opted the field into the `url` source, and a prefilled value reaches the DOM on the **first paint** rather than after an interaction — which is why H7 added its own §5 DOM-row test at that entry point instead of leaning on H6b's typed-answer one.

### 8.1 Deferred by H6a specifically (as-built)

- ~~**The TypeScript `displayValue()` twin, and with it the guest-runtime + confirmation-screen RENDER.**~~ **CLOSED by H6b:** `engine/display-value.ts` + `engine/template-renderer.ts`, wired through the store so `labelFor`/`hintFor`/`placeholderFor`/`sectionTitleFor`/`sectionDescriptionFor` all resolve the locale then fill holes. `placeholder` is worth naming: it is in §6's closed list and the gate validates it, but the SPA passed it through **raw**, so a published placeholder with a hole was putting `${key}` on a respondent's screen — the one live §3.4 violation the audit found in the runtime.
- ~~**Post-publish-edit reference re-validation for `forms.confirmation_message`**~~ → **CLOSED by H6b as a WARNING — see amendment A10.**

> **Amendment A10 (H6b, as-built): A3's residue closes as a warning, not a rejection.** A3 left an edit able to dangle a reference until the next publish refused it, naming a message edited weeks earlier. The obvious closure — validate references at request time and 422 — is **wrong**, and A3's own text contains the reason: the publish gate resolves against the version being PUBLISHED, while a request can only resolve against the CURRENTLY PUBLISHED one. Those are different answer sets. An author who adds a field to their draft and then writes the message referencing it is *correct*, and a 422 would block them for being early.
>
> So `FormConfirmationMessageController` resolves the saved message against `currentPublishedVersion` and, when a hole will not fill, replaces its success toast with an `info` one that still says the save happened — because it did — and names the offending key and why. A form with no published version gets no warning at all: there is nothing to resolve against, and inventing an answer would be dishonest. The walk is shared with the publish gate through `TemplateValidationGate::confirmationMessageViolations()`, so the two can never disagree about what resolves.
>
> **The residue that remains is narrower and belongs elsewhere:** deleting a FIELD after the message was saved still dangles it silently, because nothing re-validates this column on a schema change. That is `SchemaChangeClassifier` hole-diffing's job (already deferred below), and the marker now says so on `FormService::setConfirmationMessage()`.
- **`SchemaChangeClassifier` hole-diffing** (§6.1) → the diff-UI increment. Safe to defer: the classification is display-only today, so a mis-classified label change has no behavioural effect.
- ~~**Repeat-answer display in the inbox and export.**~~ **CLOSED for the inbox by H6b — see amendment A9 below.** The export remains flat-column by design (its header renders against an empty answer map, so a repeat hole there is structurally unfillable regardless), and that is a linelist-shape question for H19 rather than a piping one.

> **Amendment A9 (H6b, as-built): §3.3 rule 2 is now implemented on both sides, and closing it required fixing a bug that was not about piping.** H6a recorded, correctly, that no PHP surface could render a same-instance repeat hole. What the H6b audit found is that the gap was **wider than piping**: `SubmissionInboxPresenter` was repeat-BLIND altogether. It emitted one row per member field and read `$answers[$field->key]`, but a repeat member's answers live nested under the SECTION key — so an answered roster displayed as a column of em-dashes, piping or not. That is a live correctness defect on a shipped read surface, not a cosmetic gap.
>
> It also made "labels only" incoherent as a fix: there is no honest way to render N differently-filled labels into one row. So a repeatable section now emits **one block per stored instance**, each resolved against `array_merge($base, $instance)` — the same context `SemanticValidator` builds for an instance and the merge `TemplateRenderer`'s docblock prescribes — and the value column fills from the instance too. Instances are numbered as `RepeatGroup.vue`'s legend numbers them (`{label} {n+1}`), so the reviewer and the respondent agree about which entry they are looking at. A repeat the respondent added nothing to renders its heading with **no rows** rather than blank ones: it was not answered blank, it was not answered.
>
> Implementing it on the SPA side alone was considered and rejected: it would have meant the respondent and the reviewer reading **different question text for the same submission**, which is worse than both being blank because nothing errors and nothing logs.
>
> One honesty note from the mutation pass: the merge *direction* is written to match `SemanticValidator` but is **not observable**, because `form_fields.key` is unique per version so a flat key and an instance key can never collide. Reversing the arguments is a green mutation. Recorded rather than papered over with a test that would pass for the wrong reason — what is pinned, and what reddens, is that the instance map is consulted at all.
- **A builder preview of a rendered template.** No builder preview page exists at all; `BuilderCanvas` shows the raw authored text, which is right for an author but means nobody sees a filled hole before publishing.
- **Two respondent-facing text surfaces explicitly EXCLUDED from §6's closed column list**, so a later reader does not assume an oversight: choice-option `label_translations` nested inside `form_fields.config.options[]` (written by `XlsformImportParser` and `CascadeResolver`, and **read by `displayValue()`** — so a piped `single_select` already renders a string from an unvalidated, undocumented `jsonb` path), and `form_field_validations.error_message`/`error_message_translations`.
- **`SubmissionExporter::header()`'s locale-resolution divergence.** It is the only PHP locale resolution of a label in the codebase, and its `$translations[$locale] ?? $field['label'] ?? $key` accepts an **empty-string** variant as a winner, where the runtime's `resolveText()` (pinned by a test) falls back to the base. H6a renders the template *after* that resolution and does not touch the resolution itself; unifying the two belongs to whichever increment next touches locale resolution.
- **No `bootstrap/app.php` render callback for `TemplateSyntaxException`.** `parse()` — the only method that throws — has exactly one production caller, the publish gate, whose catch is total; every render path uses `parseLenient()`, which cannot throw. The exception is structurally unable to reach HTTP, so a callback would be dead code. The first increment that calls `parse()` outside a gate owes one. (Note the trap avoided: the exception deliberately does **not** extend `ExpressionException`, because `bootstrap/app.php` matches the two expression *leaf* types by name — a third leaf under that base would match no callback and 500 without an envelope.)
- **A shared golden-corpus loader.** Four runners now duplicate discovery and the manifest guards. Extracting a shared one would touch four green files to save ~25 lines and would deviate from the convention the two existing pairs set; the threshold is a fifth corpus.
