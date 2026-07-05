<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidv7;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-user UI preferences (data-dictionary §19, PRD Feature #9). Belongs to a PERSON, not a tenant —
 * so it has NO tenant_id and uses the "belongs to me" RLS shape (keyed on app.current_user_id).
 * Deliberately does NOT use BelongsToTenant. Minimal in B1; the theme UI lands in Increment C.
 */
class UserUiPreference extends Model
{
    use HasUuidv7;

    protected $fillable = ['user_id', 'theme_mode'];
}
