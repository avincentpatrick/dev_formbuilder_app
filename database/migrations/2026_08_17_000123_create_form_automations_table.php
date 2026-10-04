<?php

declare(strict_types=1);

use App\Enums\FormAutomationAction;
use App\Enums\FormAutomationTrigger;
use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A form's automations (M132, `R-b7bc5149`): "when a response is submitted, do this". One trigger and one action per
 * row; a form may have several. Run on the queue only, after the response is stored (`D62` = A).
 *
 * ── TWO ACTIONS, AND THE ROW'S SHAPE FOLLOWS THE ACTION ──────────────────────────────────────────────────────
 * - `email` (`D82`): a notice and a link, to up to five addresses in `recipients`. No `url`, no `secret`.
 * - `webhook` (`D83`): the answers, POSTed to `url` and signed with `secret`. No `recipients`.
 * `form_automations_shape_check` holds that at the database, so a half-converted row cannot exist.
 *
 * ── THE SECRET IS A CREDENTIAL ───────────────────────────────────────────────────────────────────────────
 * `text` for the `encrypted` cast's at-rest form (the `webhook_endpoints.secret` reason). It is shown to the author
 * once, at creation, and never again; it is withheld from the tenant extract (`TenantExtractColumns::WITHHELD`) and
 * redacted from the audit log (`AuditRedactor`, alias `form_automation`). A hard delete, not a soft one: there is no
 * restore path, and a soft-deleted row would keep a live secret at rest for nothing.
 *
 * Strict RLS; the form key is composite (ADR-0002 §D5), CASCADE with the form. The table joins
 * {@see TenantScopedTables::STRICT}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_automations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('form_id');
            $table->string('name', 80);
            $table->string('trigger', 40)->default(FormAutomationTrigger::SubmissionCreated->value);
            $table->string('action', 20);
            // Email only: the addresses, at most five, each validated `email:rfc` by the request.
            $table->jsonb('recipients')->nullable();
            // Web address only: where the answers go, and the secret their signature is made with.
            $table->string('url', 2048)->nullable();
            $table->text('secret')->nullable();
            $table->boolean('enabled')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // The target of `form_automation_runs`' composite key.
            $table->unique(['tenant_id', 'id'], 'form_automations_tenant_id_id_unique');

            $table->foreign(['tenant_id', 'form_id'], 'form_automations_form_fk')
                ->references(['tenant_id', 'id'])->on('forms')->cascadeOnDelete();

            $table->index(['tenant_id', 'form_id']);
        });

        $quoted = static fn (array $values): string => implode(', ', array_map(static fn (string $v): string => "'".$v."'", $values));

        DB::statement('ALTER TABLE form_automations ADD CONSTRAINT form_automations_trigger_check '
            .'CHECK ("trigger" IN ('.$quoted(FormAutomationTrigger::values()).'))');
        DB::statement('ALTER TABLE form_automations ADD CONSTRAINT form_automations_action_check '
            .'CHECK (action IN ('.$quoted(FormAutomationAction::values()).'))');
        DB::statement('ALTER TABLE form_automations ADD CONSTRAINT form_automations_shape_check CHECK ('
            ."(action = 'email' AND recipients IS NOT NULL AND url IS NULL AND secret IS NULL) OR "
            ."(action = 'webhook' AND url IS NOT NULL AND secret IS NOT NULL AND recipients IS NULL))");

        withTenantIsolation('form_automations'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('form_automations');
    }
};
