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
    /**
     * A standing caveat about the provider's own configuration (H16b) — today, that Google expires a
     * connection after 7 days while its OAuth app is in `testing`. Null when there is nothing to say, which
     * is what publishing the app produces without a template edit.
     */
    notice: string | null;
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
    /**
     * The shape of this grant's destination (H16c), resolved server-side.
     *
     * It replaced the front end's ONLY provider literal — `props.provider === 'google_sheets'` in
     * `RuleFormModal`, which would have handed an Airtable grant Slack's channel picker, Slack's event list
     * and Slack's `config` shape.
     */
    destination_kind: DestinationKind;
};

export type DestinationKind = 'channel' | 'tabular';

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
    /** Google Sheets (H16b). Projected key by key, like the Slack pair above — never the whole config blob. */
    spreadsheet_id: string | null;
    sheet_name: string | null;
    spreadsheet_url: string | null;
    mapping: SheetMapping | null;
    /**
     * The destination, resolved server-side into ONE string whatever the provider is, so a table cell does not
     * need a `provider === 'x'` branch. Null when the rule has no destination configured.
     */
    destination_label: string | null;
    /**
     * Why a paused rule is paused, as the `[code] sentence` the adapter wrote. Null for an active rule, and
     * null when the last failure carried only the provider's own response body rather than our explanation.
     */
    paused_reason: string | null;
    status: string;
    consecutive_failure_count: number;
    last_success_at: string | null;
    last_failure_at: string | null;
    created_at: string;
};

export type RuleDetail = RuleRow & { updated_at: string | null };

export type ConnectionWithRules = ConnectionCard & { rules: RuleRow[] };

/**
 * One spreadsheet column and what fills it. `header` is stored NORMALIZED by `ColumnMapping::author()` — the
 * editor renders the RAW header from `SheetInspection` instead, or it would show the tenant a column name
 * their sheet does not contain.
 */
export type MappingColumn = { header: string; field_key: string | null };

/** The stored `config.mapping`. The fingerprint is the server's; the client never computes one (H16b). */
export type SheetMapping = { fingerprint: string; columns: MappingColumn[] };

/**
 * A destination document as the editor sees it: what it is called, what tabs it has, and what row 1 says.
 *
 * The key names are Sheets-flavoured and the CONCEPTS are not, matching `TabularDestination` on the server:
 * `spreadsheet_id` is a Google spreadsheet id OR an Airtable base id, and `tabs`/`sheet_name` are its tabs or
 * its tables. Renaming the keys would ripple through the stored rule `config` to buy vocabulary; renaming the
 * TYPE cost nothing, so H16c did that much.
 */
export type TabularDestination = {
    spreadsheet_id: string;
    title: string;
    url: string;
    tabs: string[];
    sheet_name: string;
    /** Row 1, VERBATIM and positional — an interior blank is a real column and is preserved. */
    header_row: string[];
    /**
     * The chosen tab's STABLE id, when the provider has one (H16c). Airtable does, and delivery keys on it so
     * a tenant renaming their table is invisible rather than a 404. Null for Google Sheets.
     */
    sheet_id: string | null;
};

/** The always-200 payload from both destination sidecars: exactly one of the two is non-null. */
export type TabularDestinationPayload = { destination: TabularDestination | null; error: string | null };

/** One thing a spreadsheet column can be bound to. `group` is the optgroup the select renders it under. */
export type MappableColumn = { key: string; label: string; group: string };

/** `scoped` is false for an all-forms rule, where only submission metadata is offerable (H16b). */
export type MappableColumnsPayload = { columns: MappableColumn[]; scoped: boolean };

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
