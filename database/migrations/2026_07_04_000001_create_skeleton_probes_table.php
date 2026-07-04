<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Walking-skeleton probe table (throwaway — see App\Models\SkeletonProbe).
 * Uses a client-generated UUID string PK to mirror the project's UUIDv7 convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skeleton_probes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skeleton_probes');
    }
};
