<?php

declare(strict_types=1);

namespace App\Exceptions\Scoping;

use App\Exceptions\Authorization\GrantException;
use App\Services\Scoping\ScopeNodeService;
use RuntimeException;

/**
 * A scoping-hierarchy business-rule violation (Increment G10b) — a move that would make a node its own
 * ancestor, or one that would push part of the subtree past the depth cap.
 *
 * Both are raised UPFRONT by {@see ScopeNodeService::move()}, inside the transaction but before the
 * re-path statement runs, so the caller gets a clean 422 rather than a Postgres CHECK violation (23514)
 * surfacing as a 500. The DB constraints (`scope_nodes_no_self_parent`, `scope_nodes_depth_max`) remain
 * the backstop — they are what makes this a defence-in-depth pair rather than a single point of trust.
 *
 * Distinct from an authorization failure (403 from ScopeNodePolicy) and from a grant-escalation refusal
 * ({@see GrantException}).
 */
final class ScopeNodeException extends RuntimeException
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    /**
     * The target parent is the node itself or one of its own descendants. Because `path` is
     * self-inclusive, one prefix test catches both cases.
     */
    public static function wouldCycle(): self
    {
        return new self(
            'A node cannot be moved beneath itself or one of its own descendants.',
            'scope_node_would_cycle',
        );
    }

    /**
     * The move would push the deepest descendant past the depth cap. Measured against the whole subtree,
     * not just the moved node — a shallow node can carry a deep subtree.
     */
    public static function depthCapExceeded(int $resultingDepth, int $maxDepth): self
    {
        return new self(
            "This move would nest part of the hierarchy {$resultingDepth} levels deep, past the limit of {$maxDepth}.",
            'scope_node_depth_exceeded',
        );
    }

    /** The stable, machine-readable error code (never the HTTP status text, never translated). */
    public function code(): string
    {
        return $this->errorCode;
    }
}
