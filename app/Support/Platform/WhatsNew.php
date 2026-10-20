<?php

declare(strict_types=1);

namespace App\Support\Platform;

use App\Models\Release;
use App\Models\User;
use App\Support\Audit\ImpersonationContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * What's new — the releases a person has not seen, and the record of what they have (M181, `R-c7247dbf`).
 *
 * The user (2026-10-14): *"lets also have a what's new prompt to users for new login so users are aware for every
 * changes"* — and *"i want the whats new to be seen right after logging in"*. The prompt is the shared `whatsNew` prop
 * (`HandleInertiaRequests`), which the app shell's `WhatsNewModal` opens on the first page loaded while it is set: for
 * someone signing in, the page they land on. Read from the `releases` table that every deploy fills (`D125` C).
 *
 * - **Who sees what.** The releases newer than `users.whats_new_seen_version`. A person who has never seen one (NULL)
 *   gets every release since their account was created, at least the newest (`D157` A) — so a tester at the first
 *   release reads all of it, and a newcomer next year is not handed the whole history.
 * - **Never in a support session.** An operator driving a member's session must not use up the member's notice, and a
 *   modal there would only stand between the operator and the banner's way out.
 * - **Never off-tenant.** The console and the landing page have no app shell to open it in.
 * - **Any close marks it seen** (`D126` A), and the mark only moves forward: a slow request carrying an older version
 *   cannot bring an already-seen release back.
 */
final class WhatsNew
{
    /**
     * Every release, newest first, as the page shows them.
     *
     * @return list<array{version: string, title: string, released_at: string, items: list<array{kind: string, text: string, href?: string}>}>
     */
    public function all(): array
    {
        return $this->releases()->map(fn (Release $release): array => self::present($release))->values()->all();
    }

    /**
     * What `$user` has not seen yet, newest first — or null when there is nothing to show them here.
     *
     * @return array{entries: list<array{version: string, title: string, released_at: string, items: list<array{kind: string, text: string, href?: string}>}>}|null
     */
    public function pending(?User $user): ?array
    {
        if ($user === null || TenantContext::currentTenantId() === null || ImpersonationContext::operatorId() !== null) {
            return null;
        }

        $releases = $this->releases();

        if ($releases->isEmpty()) {
            return null;
        }

        $seen = $user->whats_new_seen_version;
        $joined = $user->created_at;

        $unseen = $seen === null
            ? $releases->filter(fn (Release $release): bool => $joined === null || $release->released_at->greaterThanOrEqualTo($joined))
            : $releases->filter(fn (Release $release): bool => version_compare($release->version, $seen, '>'));

        if ($seen === null && $unseen->isEmpty()) {
            $unseen = $releases->take(1);
        }

        if ($unseen->isEmpty()) {
            return null;
        }

        return ['entries' => $unseen->map(fn (Release $release): array => self::present($release))->values()->all()];
    }

    /**
     * Record that `$user` closed the prompt on `$version` — only ever forward. Answers whether it moved.
     *
     * ⚠️ `users` is FORCE RLS: this must run where the request's own user GUC is set (the tenant route group), or the
     * UPDATE lands zero rows and raises nothing. `User` is `#[Fillable]`-closed, hence `forceFill`.
     */
    public function markSeen(User $user, string $version): bool
    {
        $seen = $user->whats_new_seen_version;

        if ($seen !== null && version_compare($version, $seen, '<=')) {
            return false;
        }

        $user->forceFill(['whats_new_seen_version' => $version])->save();

        return true;
    }

    /** @return Collection<int, Release> newest first, by version — never by text, where 0.10.0 sorts below 0.9.0 */
    private function releases(): Collection
    {
        return Release::query()->get()
            ->sort(fn (Release $a, Release $b): int => version_compare($b->version, $a->version))
            ->values();
    }

    /**
     * @return array{version: string, title: string, released_at: string, items: list<array{kind: string, text: string, href?: string}>}
     */
    private static function present(Release $release): array
    {
        return [
            'version' => $release->version,
            'title' => $release->title,
            'released_at' => $release->released_at->toIso8601String(),
            'items' => $release->items,
        ];
    }
}
