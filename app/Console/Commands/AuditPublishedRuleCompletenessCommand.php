<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FormVersionStatus;
use App\Enums\ValidationRuleType;
use App\Models\Tenant;
use App\Support\Tenancy\ExtractionGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Find every ALREADY-PUBLISHED structured validation row that can never evaluate (Increment M118,
 * `R-43a7d121`).
 *
 * ── WHY THIS EXISTS AT ALL, GIVEN M116 ALREADY REFUSES THESE SHAPES ────────────────────────────────────
 * `StructuralValidationGate` runs at PUBLISH. It cannot reach a `form_versions` row that is already
 * `published`, so a rule authored and published before M116 merged keeps failing every submission for the
 * life of that version — and the row that filed this said the second half of the defect plainly: *nothing
 * can find those versions*. That half is not rhetorical. M118 measured the local database (zero structured
 * validation rows of any kind) and then could not measure the one database where such a row could exist,
 * because the testing box's PostgreSQL is bound to `localhost` with no firewall rule for its port
 * (`docs/deployment-infrastructure.md` §8 step 2). A census that can only be taken by hand, on a box, by
 * someone who reconstructs the query from an enum is not a census. This is that query, once.
 *
 * ── WHY A COMMAND AND NOT A ROUTE ──────────────────────────────────────────────────────────────────────
 * `ExtractTenantCommand`'s reasoning applies unchanged: this reads across EVERY tenant, so exposing it
 * over HTTP would need a new permission key and would make any session-handling defect on that route a
 * cross-tenant disclosure. Requiring shell access on the box is the authorization model, not friction.
 * It is auto-discovered from this directory, so `routes/console.php` — a hub file — stays shut.
 *
 * ── THE TWO DEFECT SHAPES, AND WHY THEY ARE NOT A LIST HERE ────────────────────────────────────────────
 * A row is unevaluable when it names no second field but its kind requires one
 * ({@see ValidationRuleType::takesRelatedField()}), or when it carries no operator, its kind requires one,
 * and the absence is NOT the legitimate "is answered" reading ({@see ValidationRuleType::takesOperator()}
 * with {@see ValidationRuleType::operatorMayBeEmpty()}). Those are the same three predicates
 * `StructuralValidationGate` dispatches on, asked here rather than restated: one definition, two consumers.
 * A fourth rule kind added to the enum is therefore audited the moment it is declared, and a literal list
 * in this file would have been the second copy that stops being true.
 *
 * ⛔ RUN IT AS THE APPLICATION ROLE, AND THE GUARD IS NOT COPIED FROM THE EXTRACTOR BY HABIT. This command
 * loops per tenant with RLS as its filter, so a SUPERUSER or BYPASSRLS connection does not merely leak —
 * it MULTIPLIES. Every policy is ignored, so each iteration returns every tenant's rows, and the total
 * comes out roughly squared in the number of tenants while every individual number still looks plausible.
 * {@see ExtractionGuard::assertRlsSubjectRole()} refuses that connection, and
 * {@see ExtractionGuard::assertContextEstablished()} then refuses the opposite failure — a GUC that
 * silently did not take, which would report a clean zero for a tenant whose rows were simply invisible.
 * ⚠️ A one-shot global count run by hand in `psql` as the privileged role is a DIFFERENT and legitimate
 * shape, because it does not loop; that is how M118 measured the local database. The multiplication is a
 * property of the loop, not of the role.
 *
 * ⛔ THE EXIT CODE IS THE ALARM, DELIBERATELY. Zero findings exits SUCCESS; any finding exits FAILURE, so
 * this can be scheduled and its failure IS the notification. An operator reading a census will find the
 * count on stdout either way, and a command whose whole purpose is to find nothing should not report
 * success when it finds something.
 */
final class AuditPublishedRuleCompletenessCommand extends Command
{
    protected $signature = 'forms:audit-published-rules';

    protected $description = 'Find published validation rules that can never evaluate (a missing compared field, or a missing operator where its absence is not "is answered")';

    public function handle(): int
    {
        ExtractionGuard::assertRlsSubjectRole();

        $missingRelatedField = self::ruleTypesNeedingRelatedField();
        $missingOperator = self::ruleTypesNeedingOperator();

        $this->line('Auditing published versions for rules that can never evaluate.');
        $this->line('  kinds needing a compared field: '.implode(', ', $missingRelatedField));
        $this->line('  kinds needing an operator:      '.implode(', ', $missingOperator));
        $this->newLine();

        $total = 0;
        $tenantsWithFindings = 0;

        // The annotation is not decoration: `Tenant::query()->get()` is typed as a collection of the base
        // `Model`, so `$tenant->id` reads as `property.notFound` to PHPStan at level 8 — the same phantom
        // fifteen other sites in this tree already carry and CI does not report. Narrowing it here keeps this
        // increment's delta at zero rather than adding a sixteenth.
        /** @var Collection<int, Tenant> $tenants */
        $tenants = Tenant::query()->orderBy('slug')->get();

        foreach ($tenants as $tenant) {
            $tenantId = (string) $tenant->getKey();

            /** @var int $found */
            $found = TenantContext::runFor(
                $tenantId,
                fn (): int => $this->auditOneTenant($tenantId, $missingRelatedField, $missingOperator)
            );

            $total += $found;

            if ($found > 0) {
                $tenantsWithFindings++;
            }
        }

        $this->newLine();

        if ($total === 0) {
            $this->info('No published rule is unevaluable. Nothing to repair.');

            return self::SUCCESS;
        }

        $this->error("{$total} unevaluable published rule(s) across {$tenantsWithFindings} tenant(s).");
        $this->line('Each one fails EVERY submission to its version, and a published version is immutable —');
        $this->line('so the disposition is a decision, not an UPDATE. See `R-43a7d121` in docs/feature-backlog.md.');

        return self::FAILURE;
    }

    /**
     * One tenant, under its own transaction-scoped context. Returns the number of unevaluable rows.
     *
     * ⚠️ THE CONTEXT ASSERTION IS INSIDE THE LOOP BODY, NOT BEFORE IT. `applyLocal()` is `SET LOCAL` and a
     * silent no-op outside a transaction; {@see TenantContext::runFor()} opens one, so the only place the
     * GUC can be proved to have taken is in here, after it was applied.
     *
     * @param  list<string>  $missingRelatedField
     * @param  list<string>  $missingOperator
     */
    private function auditOneTenant(string $tenantId, array $missingRelatedField, array $missingOperator): int
    {
        ExtractionGuard::assertContextEstablished($tenantId);

        // RLS is the tenant filter (ADR-0002), so there is deliberately no `where tenant_id = …` here.
        $rows = DB::table('form_field_validations as v')
            ->join('form_versions as fv', 'fv.id', '=', 'v.form_version_id')
            ->join('form_fields as f', 'f.id', '=', 'v.form_field_id')
            ->where('fv.status', FormVersionStatus::Published->value)
            // No `whereNotNull('v.rule_type')` here, and its ABSENCE is measured rather than assumed. An
            // explicit one was written first and `scripts/mutate.php` reported it SURVIVED: neutering it
            // changed nothing, because `whereIn` cannot match SQL NULL, so the two clauses below already
            // exclude every expression row (the XOR CHECK makes `rule_type` null exactly when `expression`
            // is set). The case asserting an expression row is ignored therefore passes on that exclusion,
            // not on a redundant predicate kept for reassurance.
            ->where(function ($q) use ($missingRelatedField, $missingOperator): void {
                $q->where(function ($q) use ($missingRelatedField): void {
                    $q->whereIn('v.rule_type', $missingRelatedField)->whereNull('v.related_form_field_id');
                })->orWhere(function ($q) use ($missingOperator): void {
                    $q->whereIn('v.rule_type', $missingOperator)->whereNull('v.operator');
                });
            })
            ->orderBy('fv.form_id')
            ->orderBy('f.key')
            ->get(['v.id', 'v.rule_type', 'v.operator', 'v.related_form_field_id', 'fv.form_id', 'fv.version_number', 'f.key']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $this->warn("  {$rows->count()} in tenant {$tenantId}");

        foreach ($rows as $row) {
            $reason = $row->related_form_field_id === null && in_array((string) $row->rule_type, $missingRelatedField, true)
                ? 'names no compared field'
                : 'carries no operator';

            $this->line("    form {$row->form_id} v{$row->version_number} field `{$row->key}` rule `{$row->rule_type}` — {$reason} (validation {$row->id})");
        }

        return $rows->count();
    }

    /**
     * The rule kinds whose evaluation needs a second field. Derived from the enum, never listed.
     *
     * @return list<string>
     */
    private static function ruleTypesNeedingRelatedField(): array
    {
        return array_values(array_map(
            static fn (ValidationRuleType $t): string => $t->value,
            array_filter(ValidationRuleType::cases(), static fn (ValidationRuleType $t): bool => $t->takesRelatedField())
        ));
    }

    /**
     * The rule kinds whose evaluation needs an operator AND for which a null operator is not a legitimate
     * "is answered" reading. `required_with` / `skip_with` are excluded here for that reason and not by
     * name: `lowerCondition()` reads their null as `isNotNull(relatedKey)`, which is probably the commonest
     * authoring choice and is not a defect.
     *
     * @return list<string>
     */
    private static function ruleTypesNeedingOperator(): array
    {
        return array_values(array_map(
            static fn (ValidationRuleType $t): string => $t->value,
            array_filter(
                ValidationRuleType::cases(),
                static fn (ValidationRuleType $t): bool => $t->takesOperator() && ! $t->operatorMayBeEmpty()
            )
        ));
    }
}
