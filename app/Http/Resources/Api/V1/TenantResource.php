<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public API representation of the current tenant profile (Increment E). The tenant is the RLS-exempt
 * discriminator record; only non-sensitive profile fields are exposed.
 *
 * @mixin Tenant
 */
final class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'default_locale' => $this->default_locale,
            'status' => $this->status,
        ];
    }
}
