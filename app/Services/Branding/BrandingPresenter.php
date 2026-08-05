<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Attachment;
use App\Models\Tenant;
use App\Support\Branding\BrandRamp;

/**
 * Shapes the tenant's branding state for the `/settings` card (Increment H23a2, ADR-0014).
 *
 * **The `snap` block is the reason this class is not an inline array.** ADR-0014 §D2 discards the
 * tenant's lightness and re-derives it per role, so the colour that renders is frequently NOT the colour
 * they typed — and the ADR requires that difference to be DISCLOSED rather than discovered. This
 * presenter is where "we changed your colour, here is what to, and here is the measurement that forced
 * it" becomes data the page can render. A UI that showed only the derived swatch would be technically
 * accurate and quietly dishonest.
 *
 * It reads {@see TenantBrandingService::isActive()} rather than {@see Tenant::hasBrandRamp()} for the
 * `is_active` flag, because STORED and ACTIVE are different questions once a tenant downgrades — see that
 * service's docblock.
 */
final class BrandingPresenter
{
    public function __construct(private readonly TenantBrandingService $branding) {}

    /**
     * @return array<string, mixed>
     */
    public function forSettings(Tenant $tenant, bool $canManage): array
    {
        $ramp = $tenant->brandRamp();

        return [
            'can_manage' => $canManage,
            // Whether the tenant HAS a brand …
            'has_brand_color' => $ramp !== null,
            // … and whether it currently renders. These differ exactly when a branded tenant has
            // downgraded below the plan that allows branding, which is the state the card must explain
            // rather than silently show as "on".
            'is_active' => $this->branding->isActive($tenant),
            'required_tier' => $this->branding->requiredTier()?->value,
            'input_color' => $tenant->primary_color,
            'tokens' => $ramp?->tokens,
            'snap' => $ramp === null ? null : $this->snap($ramp),
            'contrast' => $ramp === null ? null : $this->contrast($ramp),
            'logo' => $this->logo($tenant),
        ];
    }

    /**
     * What the engine changed, and by how much.
     *
     * `adjusted` compares the tenant's INPUT to the LIGHT FILL specifically — not to any of the other
     * eleven tokens — because that is the one a tenant thinks of as "my brand colour" when they look at a
     * button. The other eleven are always different by construction (they are hover, active, a pale tint,
     * a dark-theme ramp), so comparing against them would report an adjustment every single time and the
     * disclosure would become noise.
     *
     * @return array<string, mixed>
     */
    private function snap(BrandRamp $ramp): array
    {
        $derived = $ramp->token('light', 'bg');

        return [
            'input' => $ramp->input,
            'derived' => $derived,
            'adjusted' => $derived !== $ramp->input,
        ];
    }

    /**
     * The measured §4.1 pairings, worst first.
     *
     * Worst-first because it is the number a sceptical tenant should see WITHOUT scrolling: the claim
     * being made is "every pairing clears its minimum", and the strongest evidence for it is the one that
     * clears by the least. Sorting best-first would bury it.
     *
     * @return array<string, mixed>
     */
    private function contrast(BrandRamp $ramp): array
    {
        $measurements = $ramp->measurements;

        usort(
            $measurements,
            static fn (array $a, array $b): int => ($a['ratio'] - $a['min']) <=> ($b['ratio'] - $b['min']),
        );

        return [
            'measurements' => array_map(static fn (array $m): array => [
                'theme' => $m['theme'],
                'pairing' => $m['pairing'],
                'ratio' => round($m['ratio'], 2),
                'min' => $m['min'],
            ], $measurements),
            'count' => count($measurements),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function logo(Tenant $tenant): ?array
    {
        $attachment = $tenant->logoAttachment()->first();

        if (! $attachment instanceof Attachment) {
            return null;
        }

        return [
            'id' => $attachment->id,
            'filename' => $attachment->original_filename,
            'width' => $attachment->width,
            'height' => $attachment->height,
            // The serving gate the threat model relies on (Attachment's docblock): a file is not shown to
            // anyone until its scan status is servable. Surfacing it lets the card say "processing"
            // instead of rendering a broken image for the seconds after an upload.
            'servable' => $attachment->virus_scan_status->servable(),
        ];
    }
}
