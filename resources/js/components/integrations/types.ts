// Wire types for the Integrations surface (H15b) — the shapes ConnectionPresenter emits.
//
// ONE module rather than a copy in each SFC: H14 re-declared its row types in both Index.vue and Show.vue,
// which is where two views of the same record start to drift. Importing the real types also lets MdsDataTable
// resolve its generic `Row`, so the templates need none of the `(row as EndpointRow)` casts H14 carries.
//
// Note what is absent and always will be: there is no token field of any kind, masked or otherwise. A
// connector credential has no user-facing consumer (ADR-0009 §D1), so it never leaves the server.

export type Option = { value: string; label: string };

export type Meta = {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
};

export type ProviderCard = {
    key: string;
    label: string;
    description: string;
    scopes: string[];
    /** False when this deployment has no app credentials for the provider — the connect button is disabled. */
    configured: boolean;
    connect_url: string;
    connected: boolean;
};

export type ConnectionCard = {
    id: string;
    provider: string;
    provider_label: string;
    external_account_id: string;
    external_account_label: string;
    scopes: string[];
    status: string;
    /** The grant was disconnected (soft-deleted). Its rules survive, paused, and can still be opened. */
    disconnected: boolean;
    token_expires_at: string | null;
    last_refreshed_at: string | null;
    last_error: string | null;
    last_error_at: string | null;
    connected_by_name: string | null;
    created_at: string;
    can: { update: boolean; delete: boolean };
};

export type RuleRow = {
    id: string;
    connection_id: string;
    name: string;
    event_types: string[];
    form_id: string | null;
    form_title: string | null;
    /** The form's hub path, server-resolved (J2d); null when the reader cannot open it or it is gone. */
    form_url: string | null;
    channel_id: string | null;
    channel_name: string | null;
    status: string;
    consecutive_failure_count: number;
    last_success_at: string | null;
    last_failure_at: string | null;
    created_at: string;
};

export type RuleDetail = RuleRow & { updated_at: string | null };

export type ConnectionWithRules = ConnectionCard & { rules: RuleRow[] };

export type DeliveryRow = {
    id: string;
    connection_subscription_id: string | null;
    event_id: string;
    event_type: string;
    status: string;
    attempt_count: number;
    max_attempts: number;
    next_retry_at: string | null;
    last_attempted_at: string | null;
    response_status_code: number | null;
    response_body_excerpt: string | null;
    response_time_ms: number | null;
    created_at: string;
    updated_at: string | null;
};

/** One destination from the provider. `available` false = the app must still be invited to it. */
export type Channel = {
    id: string;
    label: string;
    available: boolean;
    unavailable_reason: string | null;
};

export type ChannelsPayload = {
    channels: Channel[];
    /** The provider had more destinations than the page budget allowed us to read. */
    truncated: boolean;
    /** Human copy when the listing failed; the picker degrades to a manual id rather than blocking. */
    error: string | null;
};
