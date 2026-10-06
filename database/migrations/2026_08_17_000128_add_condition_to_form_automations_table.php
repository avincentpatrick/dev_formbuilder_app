<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A condition on each form automation (M142, `R-b65bafca`'s Filter, `D93` = A step 1, `D94`): the automation runs only
 * for a response that matches it — an alert on a danger sign alone.
 *
 * The condition is written in the form's own condition grammar (a question's show-if: `${age} > 60 and
 * selected(${symptoms}, 'fever')`) and checked on save against the form's published version (or its draft, before the
 * first publish). Null means "every response", which is every automation before M142. Text, because the grammar's own
 * limit is 2,000 characters (`ExpressionLexer::MAX_EXPRESSION_LENGTH`), enforced by the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_automations', function (Blueprint $table): void {
            $table->text('condition')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('form_automations', function (Blueprint $table): void {
            $table->dropColumn('condition');
        });
    }
};
