<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

/**
 * The single JSON error envelope for the /api/v1 surface (api-specification.md §2.3):
 *
 *   { "error": { "code": <stable snake_case>, "message": <english>, "details": <optional object> } }
 *
 * `code` is a machine-readable identifier safe for integration-consumer branching (never the HTTP status
 * text, never a translated string); `message` is English-only for developer/support consumption;
 * `details` is optional endpoint-specific context (e.g. `fields` on a 422, `missing` abilities on a 403).
 * Centralized here so every render closure in bootstrap/app.php stays one line.
 */
final class ApiErrorResponse
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function make(int $status, string $code, string $message, ?array $details = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }
}
