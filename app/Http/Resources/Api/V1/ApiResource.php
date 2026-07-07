<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base for the /api/v1 JSON resources (Increment E). Its typed helpers exist so the Scramble-generated
 * OpenAPI contract declares the correct shapes — Scramble infers a field's type from the expression that
 * produces it, and a bare `$carbon?->toIso8601String()` / passthrough of a jsonb-cast array is inferred as
 * a non-nullable `string` / a JSON `array`. Routing those through these `?string` / `object` return-typed
 * helpers makes the contract mark timestamps nullable and jsonb columns as objects, matching the runtime.
 */
abstract class ApiResource extends JsonResource
{
    /**
     * An ISO-8601 timestamp, or null. Typed `?string` so the field is `["string","null"]` in the contract.
     */
    protected function iso(?CarbonInterface $at): ?string
    {
        return $at?->toIso8601String();
    }

    /**
     * A jsonb column (cast to an associative array) as a JSON object — cast to object so an empty value
     * serializes as `{}` (not `[]`) and Scramble emits `type: object`, matching the populated shape.
     */
    protected function jsonObject(mixed $value): object
    {
        return (object) (is_array($value) ? $value : []);
    }
}
