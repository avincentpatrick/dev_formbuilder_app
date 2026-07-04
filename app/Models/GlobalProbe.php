<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidv7;
use Illuminate\Database\Eloquent\Model;

/**
 * Throwaway Increment-A harness for the NULLABLE-GLOBAL tenant shape (ADR-0002 §D2 named exception:
 * a NULL tenant_id means "platform-provided, visible to every tenant"). It lets the fuzz pack prove
 * that a tenant sees its own rows PLUS global rows, yet cannot itself author a global (NULL) row.
 *
 * Deliberately does NOT use BelongsToTenant: the ORM strict-equality scope would hide the global
 * (NULL) rows the SELECT policy is specifically widened to reveal. Reads here rely on RLS directly,
 * which is the honest model for this shape. Removed when the real adopters (field_library,
 * form_templates, settings) land.
 */
class GlobalProbe extends Model
{
    use HasUuidv7;

    protected $fillable = ['tenant_id', 'note'];
}
