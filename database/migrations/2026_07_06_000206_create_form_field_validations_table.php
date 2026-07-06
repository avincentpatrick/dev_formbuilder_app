<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structured validation rules or a free-text expression per field (data-dictionary §6), modeled on
 * XLSForm relevant/constraint. Content child of form_versions → the `draft_child` RLS shape. A DB CHECK
 * enforces the doc's explicit precedence: exactly one of `expression` / `rule_type` is populated per
 * row — a rule is fully structured or fully expression-based, never a silent hybrid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_field_validations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('form_version_id')->constrained('form_versions')->cascadeOnDelete();
            $table->foreignUuid('form_field_id')->constrained('form_fields')->cascadeOnDelete();
            $table->foreignUuid('related_form_field_id')->nullable()->constrained('form_fields')->cascadeOnDelete();
            $table->string('rule_type', 30)->nullable();  // ValidationRuleType enum
            $table->string('operator', 20)->nullable();   // ComparisonOperator enum
            $table->text('rule_value')->nullable();
            $table->text('expression')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->jsonb('error_message_translations')->nullable();
            $table->uuid('logic_group')->nullable();
            $table->string('logic_operator', 3)->nullable(); // LogicOperator enum
            $table->integer('sequence')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'form_field_id', 'sequence']);
        });

        // §6/Design Note: exactly one of expression / rule_type is populated — structured XOR expression.
        DB::statement(
            'ALTER TABLE form_field_validations ADD CONSTRAINT form_field_validations_rule_xor_chk '
            .'CHECK ((expression IS NOT NULL AND rule_type IS NULL) OR (expression IS NULL AND rule_type IS NOT NULL))'
        );

        withTenantIsolation('form_field_validations', 'draft_child');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_field_validations');
    }
};
