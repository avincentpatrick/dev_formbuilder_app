<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidv7;
use Illuminate\Database\Eloquent\Model;

/**
 * The central tenant record (ADR-0002 §D1). This table is on the explicit RLS-exemption list — it
 * is the discriminator every other tenant-scoped table points at, and it is read/written outside any
 * one tenant's context (signup, the central app, super-admin console), so it carries no RLS policy.
 *
 * Increment A defines the minimum needed to key isolation off: id + a human name + a unique slug.
 * Owner/billing/status columns and the FK to users arrive with Increment B (auth), and stancl/tenancy
 * subdomain identification is wired onto this model there too.
 */
class Tenant extends Model
{
    use HasUuidv7;

    protected $fillable = ['name', 'slug'];
}
