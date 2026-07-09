<?php

declare(strict_types=1);

namespace App\Services\Expressions;

/**
 * The internal "absent" sentinel (technical-architecture.md §4.3). A field reference to a missing key,
 * or a `.` self-reference with no self set, resolves to {@see Marker::Absent} — distinct from a stored
 * `null` only for provenance; both are EMPTY under {@see Coercion::isEmpty()}. At the public evaluate()
 * boundary Absent is normalised to `null` so the golden `expected` (and the TypeScript mirror) agree.
 * A dedicated singleton case (never a magic string) so it can never collide with a real answer value.
 */
enum Marker
{
    case Absent;
}
