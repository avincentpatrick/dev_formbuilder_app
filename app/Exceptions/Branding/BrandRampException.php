<?php

declare(strict_types=1);

namespace App\Exceptions\Branding;

use App\Support\Branding\BrandRampGenerator;
use InvalidArgumentException;

/**
 * A brand colour that could not be turned into a compliant ramp (ADR-0014 §D5).
 *
 * **Read {@see self::pairingFailed()} before assuming this is the tenant's fault — it almost certainly is
 * not.** The engine holds the tenant's hue and re-derives lightness per role against a fixed ground, so a
 * compliant ramp provably exists for EVERY hue: pushing lightness toward black or white moves the ratio
 * against white text or dark ink monotonically, and the search sweeps the whole range. That is what makes
 * this "B-snap" rather than "B-strict" — the product never tells a paying customer their brand colour is
 * unusable (ADR-0014 §D3).
 *
 * So `pairingFailed` is a guard against an ENGINE bug — a mis-typed target, a ground token that moved
 * underneath the generator, a role added without a pairing — and reaching it is a 500, deliberately. The
 * H25 precedent applies verbatim: mapping an integrity guard to a friendly error normalises exactly the
 * condition the guard exists to surface. Only {@see self::malformedInput()} is reachable from user input,
 * and the FormRequest is expected to have refused it long before.
 */
final class BrandRampException extends InvalidArgumentException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /** Not a `#RRGGBB`. Defence-in-depth behind the FormRequest's regex. */
    public static function malformedInput(string $input): self
    {
        return new self(
            sprintf('A brand colour must be a #RRGGBB hex triplet, got "%s".', $input),
            'malformed_input',
        );
    }

    /**
     * A generated ramp failed one of the design-system-reference.md §4.1 pairings.
     *
     * @param  list<string>  $failures  one human-readable line per failed pairing
     */
    public static function pairingFailed(string $input, array $failures): self
    {
        return new self(
            sprintf(
                'The brand ramp generated for "%s" (engine v%d) fails %d of the %d design-system-reference.md §4.1 pairings, which indicates a defect in %s rather than an unusable input colour: %s',
                $input,
                BrandRampGenerator::VERSION,
                count($failures),
                count(BrandRampGenerator::pairings()),
                BrandRampGenerator::class,
                implode('; ', $failures),
            ),
            'pairing_failed',
        );
    }
}
