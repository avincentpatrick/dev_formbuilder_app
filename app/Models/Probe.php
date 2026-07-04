<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * Throwaway Increment-A harness for a STRICT tenant-scoped table. It carries no domain meaning — its
 * only job is to give the cross-tenant fuzz pack a real RLS-protected table to prove isolation,
 * fail-closed, and FORCE against, before the first real domain tables (forms/submissions) land in
 * later increments, at which point it is deleted. Mirrors the existing {@see SkeletonProbe} pattern.
 */
class Probe extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    protected $fillable = ['tenant_id', 'note'];
}
