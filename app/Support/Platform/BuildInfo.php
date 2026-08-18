<?php

declare(strict_types=1);

namespace App\Support\Platform;

use Illuminate\Support\Carbon;

/**
 * What is actually deployed, for the About panel (PRD Feature #10: "a simple About panel showing the
 * currently deployed application version… for support and debugging purposes") — Increment I5.
 *
 * ── Server-side, and NOT the Vite `__APP_VERSION__` define ─────────────────────────────────────────────
 * `__APP_VERSION__` (vite.config.ts) is the CLIENT BUNDLE's identity — a short git sha stamped onto every
 * `submissions.app_version` so a malformed answer document can be traced to the runtime that produced it
 * (data-dictionary §7, ≤20 chars). Reusing it here would mean re-declaring the global for the Inertia
 * bundle to satisfy `vue-tsc`, would still carry no deploy timestamp, and would make the panel impossible
 * to assert in Pest. A support answer belongs on the server, where the request that needs it already is.
 *
 * ── The timestamp is derived, not configured ───────────────────────────────────────────────────────────
 * `builtAt()` reads the mtime of the Vite manifest, which is written by `npm run build` at deploy time.
 * That is a fact about this deployment rather than a value someone has to remember to set — and when the
 * manifest is absent (a `vite dev` session, a fresh checkout) it answers null rather than inventing one,
 * which the card renders as "development build".
 */
final class BuildInfo
{
    public function version(): string
    {
        $version = config('app.version');

        return is_string($version) && $version !== '' ? $version : 'dev';
    }

    public function commit(): ?string
    {
        $commit = config('app.commit');

        return is_string($commit) && $commit !== '' ? $commit : null;
    }

    /** ISO-8601 UTC, or null when there is no built manifest to date. */
    public function builtAt(): ?string
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            return null;
        }

        $mtime = filemtime($manifest);

        return $mtime === false ? null : Carbon::createFromTimestampUTC($mtime)->toIso8601String();
    }

    public function environment(): string
    {
        return (string) config('app.env', 'production');
    }

    /**
     * @return array{version: string, commit: ?string, built_at: ?string, environment: string}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version(),
            'commit' => $this->commit(),
            'built_at' => $this->builtAt(),
            'environment' => $this->environment(),
        ];
    }
}
