// Shared shapes for the scoping hierarchy page (Increment G10b2). Extracted because ScopeTree, the detail
// panel and both modals all consume them — the repo's rule is to co-locate a types.ts only once more than
// one component needs it (cf. components/builder/types.ts).

export type ScopeNodeRow = {
    id: string;
    parent_id: string | null;
    name: string;
    code: string | null;
    node_type: string | null;
    depth: number;
    is_active: boolean;
    has_children: boolean;
    // Per-PARENT sibling set, computed server-side. NEVER the index in the flattened visible list — that
    // confusion is the classic flat-tree defect and no automated tool catches it.
    setsize: number;
    posinset: number;
    form_count: number;
    grant_count: number;
    can: { update: boolean; delete: boolean };
};

export type Recipient = { id: string; name: string; email: string };

export type CapacityOption = {
    value: string;
    label: string;
    allowed: boolean;
    reason: string | null;
};

export type NodeGrant = {
    id: string;
    user_id: string;
    user_name: string;
    capacity: string;
    includes_descendants: boolean;
    granted_by_name: string | null;
    created_at: string | null;
};

/** GET /scopes/{node}/impact — a bare payload, no `data` envelope (mirrors the builder's library-items sidecar). */
export type NodeImpact = {
    reach: { direct: number; descendant: number };
    deletion: { forms: number; nodes: number; grants: number };
};
