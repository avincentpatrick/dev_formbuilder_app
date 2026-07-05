<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel Sanctum personal access tokens (API keys for integrations / the offline PWA). Re-dated to
 * run after `tenants` (its FK target).
 *
 * Extended per RBAC §6 / API-spec §2.6: a token is minted for EXACTLY ONE tenant, so it carries a
 * `tenant_id` and gets the standard strict-tenant RLS shape. `tokenable` is a uuid morph (our users
 * use UUIDv7 PKs). Token ISSUANCE (the mint API + ability intersection) and token-based route auth
 * land in Increment E — B1 provides the schema + isolation + fuzz coverage only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuidMorphs('tokenable'); // uuid tokenable_id — users have UUIDv7 PKs
            $table->uuid('tenant_id')->nullable();
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->index('tenant_id');
        });

        withTenantIsolation('personal_access_tokens'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
