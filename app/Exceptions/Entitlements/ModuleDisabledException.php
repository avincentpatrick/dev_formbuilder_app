<?php

declare(strict_types=1);

namespace App\Exceptions\Entitlements;

use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\RequireModule;
use App\Support\Entitlements\ToggleableModules;
use RuntimeException;

/**
 * A tenant reached a capability **it switched off for itself** — raised by {@see RequireModule} — Increment
 * K1d.
 *
 * ── ⚠️ WHY THIS IS NOT {@see FeatureGateException}, WHICH IS THE WHOLE POINT OF THE CLASS ───────────────
 * {@see FeatureGateException}, raised by {@see RequireFeature}, says *"Your plan doesn't include X.
 * Upgrade your plan to use it."* — and ADR-0020's
 * Consequences record it as an **inherited wart** rather than a fixed one: because `gamification` is granted
 * on every plan tier including Free (§D6), the plan half of `EntitlementService::feature()` can never be
 * what refuses it, so the only way a `feature:gamification` gate could ever fire is a tenant that turned the
 * module off in Settings — for whom "upgrade your plan" is simply the wrong sentence, and points at a
 * purchase that would change nothing. gamification-design.md §9 turns that observation into an instruction:
 * K1d gates its surfaces on the module toggle and gives the refusal its own copy. This is that copy.
 *
 * ⚠️ **AND THE STATUS IS 403, NOT 402.** The entitlement family uses 402 Payment Required for "upgrade to
 * unlock" ({@see FeatureGateException}, `QuotaExceededException`), and every part of that is wrong here:
 * there is nothing to pay, nobody is over a ceiling, and an integration that retries after a plan change
 * would retry forever. This is a workspace-level configuration refusal, so it is a plain 403 with a code an
 * integration can branch on — and, unlike the 402s, an **actionable** one, because someone inside the tenant
 * can undo it.
 *
 * The message names the module through {@see ToggleableModules::label()} rather than repeating a string,
 * so the API refusal, the Settings card and the admin console cannot come to call the same toggle three
 * different things.
 */
final class ModuleDisabledException extends RuntimeException
{
    private function __construct(string $message, private readonly string $module)
    {
        parent::__construct($message);
    }

    public static function forModule(string $module): self
    {
        return new self(
            ToggleableModules::label($module).' is switched off for this workspace. '
                .'An owner or admin can switch it back on in Settings.',
            $module,
        );
    }

    public function module(): string
    {
        return $this->module;
    }

    /**
     * The stable, machine-readable code. Deliberately distinct from `feature_not_available`: a client that
     * cannot tell "your plan lacks this" from "your workspace turned this off" cannot say anything useful
     * to the person reading its output, since only one of the two is theirs to fix.
     */
    public function code(): string
    {
        return 'module_disabled';
    }

    /** 403 Forbidden — a configuration refusal, not a billing one. See the class docblock. */
    public function status(): int
    {
        return 403;
    }

    /**
     * @return array{module: string}
     */
    public function details(): array
    {
        return ['module' => $this->module];
    }
}
