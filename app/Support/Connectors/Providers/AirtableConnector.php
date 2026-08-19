<?php

declare(strict_types=1);

namespace App\Support\Connectors\Providers;

use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\ConnectorOAuthException;
use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Submission;
use App\Services\Connectors\ConnectionService;
use App\Services\Submissions\SubmissionRowProjector;
use App\Support\Connectors\ConnectorDeliveryResult;
use App\Support\Connectors\ConnectorGrant;
use App\Support\Connectors\ConnectorOAuthStateService;
use App\Support\Connectors\ConnectorProvider;
use App\Support\Mapping\ColumnFingerprint;
use App\Support\Mapping\ColumnMapping;
use App\Support\Mapping\MappingDriftDetector;
use App\Support\Webhooks\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * The Airtable adapter — the third {@see ConnectorProvider} (ADR-0009, webhook-integration-design.md §4 row 5,
 * H16c). Tabular like Google Sheets, but its OAuth differs from both predecessors in three ways, and each one
 * is a thing the framework had never been asked for.
 *
 * ── 1. PKCE IS MANDATORY, AND THIS FRAMEWORK HAD NOWHERE TO PUT A VERIFIER ───────────────────────────────
 *
 * Airtable requires `code_challenge` + `code_challenge_method=S256` at the consent screen and the matching
 * `code_verifier` at the token endpoint (verified against its OAuth reference at implementation time,
 * 2026-08-13). Those two calls happen in DIFFERENT REQUESTS ON DIFFERENT HOSTS — the authorize on a tenant
 * subdomain with a session, the exchange on the central domain with none — which is §D2/§D3's design and
 * exactly what makes "stash the verifier in the session" unavailable.
 *
 * The answer is {@see ConnectorOAuthStateService::codeVerifierFor()}: derive it from the state token both
 * halves already hold, under the key that signs the state. No session, no cache row, no table. This adapter
 * therefore never generates or stores a verifier — it is handed one and publishes its S256 digest.
 *
 * ── 2. THE TOKEN ENDPOINT AUTHENTICATES WITH HTTP BASIC, NOT FORM FIELDS ─────────────────────────────────
 *
 * Slack and Google both take `client_id`/`client_secret` as form fields. Airtable requires
 * `Authorization: Basic base64(client_id:client_secret)` for a confidential client and REFUSES the header for
 * a public one. Sending the secret in the body instead is not a benign difference: it is a 401 with an error
 * code that reads like a bad code rather than a bad request shape.
 *
 * ── 3. REFRESH TOKENS ROTATE, WHICH MAKES A PREVIOUSLY DORMANT BUG PATH LIVE ─────────────────────────────
 *
 * Slack's bot tokens never expire; Google returns no new refresh token, so the stored one is reused forever.
 * Airtable returns a NEW access + refresh pair on every refresh and INVALIDATES the previous pair. Dropping
 * the returned refresh token would kill the connection at the first renewal — 60 minutes after connecting.
 * {@see ConnectionService::applyRefreshedGrant()} already writes `$grant->refreshToken ?? $stored`, so
 * rotation persists correctly with no change there; what matters here is that {@see refresh()} must pass the
 * NEW token through rather than the one it was called with.
 *
 * ── THE CLASSIFICATION RULE INHERITED FROM H16a, WHICH STILL APPLIES ─────────────────────────────────────
 *
 * A 401 from the DATA API is reported as a retryable failure, never as
 * {@see ConnectorDeliveryResult::credentialRejected()}. An access token that lived 60 minutes and just
 * expired is indistinguishable at that endpoint from a grant the tenant revoked, and mapping it the obvious
 * way would revoke a healthy connection roughly hourly. Only {@see refresh()} throwing can know.
 *
 * Every outbound call goes through {@see OutboundUrlGuard} first, per the interface's stated rules.
 */
final class AirtableConnector implements ConnectorProvider
{
    private const AUTHORIZE_URL = 'https://airtable.com/oauth2/v1/authorize';

    private const TOKEN_URL = 'https://airtable.com/oauth2/v1/token';

    private const API_BASE = 'https://api.airtable.com/v0';

    private const META_BASE = 'https://api.airtable.com/v0/meta/bases';

    /** Scope-free (verified 2026-08-13) — it returns the user id for ANY valid token, and the email only with `user.email:read`, which this connector does not request. */
    private const WHOAMI_URL = 'https://api.airtable.com/v0/meta/whoami';

    /**
     * Token-endpoint errors meaning THE GRANT IS DEAD rather than "this request failed". Airtable uses the
     * RFC 6749 vocabulary: `invalid_grant` for a refresh token that was revoked or has passed its 60-day
     * life, the other two for the app-registration cases. No retry fixes any of them.
     */
    private const CREDENTIAL_ERRORS = ['invalid_grant', 'invalid_client', 'unauthorized_client'];

    public function __construct(
        private readonly OutboundUrlGuard $guard,
        private readonly SubmissionRowProjector $projector,
        private readonly MappingDriftDetector $drift,
    ) {}

    public function key(): ConnectorProviderKey
    {
        return ConnectorProviderKey::Airtable;
    }

    public function authorizeUrl(string $state, string $redirectUri, string $codeVerifier): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => (string) config('connectors.providers.airtable.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()), // Airtable delimits with SPACES, like Google
            'state' => $state,
            'code_challenge' => self::challengeFor($codeVerifier),
            // S256 is the only method Airtable accepts; `plain` is refused outright rather than downgraded.
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): ConnectorGrant
    {
        $body = $this->postToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ], fn (string $error): ConnectorOAuthException => ConnectorOAuthException::exchangeFailed($error, $this->isTerminal($error)));

        $accessToken = self::accessTokenFrom($body);

        return $this->grantFrom($body, null, $this->accountIdFor($accessToken), 'Airtable');
    }

    public function refresh(string $refreshToken): ConnectorGrant
    {
        $body = $this->postToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], fn (string $error): ConnectorOAuthException => ConnectorOAuthException::refreshFailed($error, $this->isTerminal($error)));

        // ⚠️ The identity is NOT re-fetched here, and that is deliberate rather than lazy. `whoami` is a second
        // network call, and a blip on it during the hourly sweep would surface as a refresh FAILURE — which is
        // terminal: it clears the tokens, pauses every rule and emails the owner. Trading a field the caller
        // does not read for that risk is the H16a classification trap in a new costume.
        // `ConnectionService::applyRefreshedGrant()` writes only the tokens, the expiry and the status; these
        // two arguments reach no column on this path.
        return $this->grantFrom($body, $refreshToken, '', 'Airtable');
    }

    public function deliver(Connection $connection, ConnectionSubscription $subscription, array $envelope, bool $priorWriteUnconfirmed = false): ConnectorDeliveryResult
    {
        $config = $subscription->config;
        $baseId = is_string($config['spreadsheet_id'] ?? null) ? $config['spreadsheet_id'] : '';

        // The table ID is the identity and the name is a caption, so the id is preferred wherever both exist —
        // a tenant renaming their table then costs nothing, where a name-keyed write would 404.
        $table = is_string($config['sheet_id'] ?? null) && $config['sheet_id'] !== ''
            ? $config['sheet_id']
            : (is_string($config['sheet_name'] ?? null) ? $config['sheet_name'] : '');

        if ($baseId === '' || $table === '') {
            // Structurally undeliverable. Blocked rather than failed: no retry can supply a missing id, and
            // blocked is also what tells the tenant, which a silent seven-day walk to the dead letter is not.
            return ConnectorDeliveryResult::blocked(null, '[missing_destination] This rule has no destination table configured.');
        }

        try {
            $mapping = ColumnMapping::fromArray(is_array($config['mapping'] ?? null) ? $config['mapping'] : []);
        } catch (InvalidArgumentException) {
            return ConnectorDeliveryResult::blocked(null, '[invalid_mapping] This rule’s field mapping could not be read. Open the rule and set it up again.');
        }

        $submissionId = $envelope['data']['submission_id'] ?? null;

        if (! is_string($submissionId) || $submissionId === '') {
            // Only `submission.*` events carry answers. A form.published/opened/closed rule pointed at a table
            // has nothing to write, and saying so beats adding a record of blanks on every publish.
            return ConnectorDeliveryResult::blocked(null, '[unsupported_event] Only submission events can be written to a table.');
        }

        // Read the destination's field names FIRST. Every write is gated on the layout still matching, because
        // the cost of being wrong is asymmetric: a refused write is visible and reversible, and a record filed
        // under the wrong field names is neither.
        $fieldNames = $this->readFieldNames($connection, $baseId, $table);

        if ($fieldNames instanceof ConnectorDeliveryResult) {
            return $fieldNames;
        }

        $verdict = $this->drift->compare($mapping, $fieldNames);

        if ($verdict->hasDrifted) {
            // The `[column_drift]` prefix is what lets `ConnectionPresenter::pausedReasons()` surface this —
            // see the long note at the same line in `GoogleSheetsConnector::deliver()`, where H16c found it
            // missing and every drifted rule was pausing with a reason the presenter silently dropped.
            return ConnectorDeliveryResult::blocked(null, '[column_drift] '.$verdict->summary());
        }

        $row = $this->rowFor($submissionId, $mapping);

        if ($row === null) {
            // Deleted, or invisible under this tenant's RLS between the event and the delivery. Not an error
            // and not retryable: there is nothing to write and there never will be.
            return ConnectorDeliveryResult::blocked(null, '[submission_gone] That submission no longer exists, so nothing was written.');
        }

        // M5. The previous attempt issued a create and never learned its outcome, so this table may ALREADY
        // hold this submission. Ask before adding a second one. Placed after every refusal above so a gone
        // submission or a drifted table still answers exactly what it answered before — the probe is a cost
        // paid only on the one path that can duplicate.
        if ($priorWriteUnconfirmed) {
            $reconciled = $this->reconcile($connection, $baseId, $table, $fieldNames, $mapping, $submissionId);

            if ($reconciled instanceof ConnectorDeliveryResult) {
                return $reconciled;
            }
        }

        return $this->createRecord($connection, $baseId, $table, self::fieldsFor($row, $fieldNames));
    }

    /**
     * Whether this submission is already in the table after an unconfirmed create (M5).
     *
     * THREE ANSWERS, AND THE NULL IS THE INTERESTING ONE:
     *   - {@see ConnectorDeliveryResult::delivered()} — found it. The record exists, so writing again would be
     *     the duplicate this whole mechanism exists to prevent; the delivery succeeds without a write.
     *   - {@see ConnectorDeliveryResult::unconfirmed()} — could not ask. The ladder retries the RECONCILIATION
     *     rather than gambling on a write, because "the probe failed" says nothing about whether the record
     *     is there and a blind create is the one move that cannot be taken back. It is `unconfirmed` rather
     *     than `failed` SO THE MARK SURVIVES — a plain failure would clear `unconfirmed_write_at` and hand
     *     the attempt AFTER this one a blind write, which a test caught the first cut of this doing.
     *   - `null` — either provably absent, or **not reconcilable at all**. Both fall through to the write, and
     *     they are the same answer on purpose: the second is today's behaviour, which is never worse than
     *     today, whereas guessing would trade a visible duplicate for an invisible missing row.
     *
     * ⚠️ NOT RECONCILABLE MEANS THE TENANT DID NOT MAP `__submission_id`, AND THAT IS A PROPERTY OF THE ROW
     * RATHER THAN A HOLE IN THE FIX. Nothing we write identifies the submission unless they bound that column
     * (it is offered by `MappableColumnCatalog` and optional), so there is nothing to search on. `__reference`
     * is deliberately NOT accepted as a second key: a submission id is a UUID, which is unique, opaque and
     * free of anything a formula could misread, and adding a second identity would double the escaping surface
     * for no reach the first does not already give.
     *
     *
     * ⚠️ AND THE QUESTION IT ASKS IS "IS THIS SUBMISSION IN THE TABLE", NOT "IS THIS DELIVERY'S RECORD IN
     * THE TABLE" — found by M5's own adversarial pass, and unfixable from here rather than overlooked.
     * Nothing we write identifies the DELIVERY: the record carries the mapped columns and nothing else, so
     * there is no delivery-shaped thing to search for. The difference is only reachable when TWO rules on
     * one connection write the SAME submission to the SAME table (a `submission.created` rule and a
     * `submission.updated` one, say). That tenant gets two records by design today; if the second rule's
     * create then loses its answer, its retry finds the FIRST rule's record and settles — so the pair
     * collapses to one. Narrow, and in the safe direction (a record too few beats an unbounded ladder of
     * duplicates), but it is a behaviour change beyond the one this fix is for, so it is filed rather than
     * left to be discovered.
     *
     * @param  list<string>  $fieldNames  the destination's VERBATIM field names, index-aligned with $mapping
     */
    private function reconcile(
        Connection $connection,
        string $baseId,
        string $table,
        array $fieldNames,
        ColumnMapping $mapping,
        string $submissionId,
    ): ?ConnectorDeliveryResult {
        $index = $mapping->indexOfFieldKey(SubmissionRowProjector::META_SUBMISSION_ID);
        $field = $index === null ? null : ($fieldNames[$index] ?? null);

        // A field name containing a brace cannot be referenced as `{Name}` in a formula and Airtable offers no
        // escape for one, so the probe is skipped rather than sent malformed — a 422 here would read as the
        // rule being broken when the only thing wrong is that we cannot phrase the question.
        if (! is_string($field) || $field === '' || str_contains($field, '{') || str_contains($field, '}')) {
            return null;
        }

        $response = $this->send(self::API_BASE, fn (): Response => $this->request($connection)
            ->get(self::API_BASE.'/'.rawurlencode($baseId).'/'.rawurlencode($table), [
                'filterByFormula' => '{'.$field.'}='.self::formulaString($submissionId),
                'maxRecords' => 1,
                // ⛔ NO `fields` PROJECTION, AND THE OMISSION IS THE DECISION. Narrowing the response to the id
                // field alone would be tidier, but `fields` is Airtable's one ARRAY query parameter and this
                // client would send it as `fields[0]=`, an encoding nothing here can verify against the live
                // API. Getting it wrong returns 422 — which this method reads as "could not check", so the
                // delivery would ride the whole ladder to a dead letter for a parameter that bought nothing.
                // The property it looked like it was protecting is already held elsewhere: every arm below
                // writes OUR excerpt, so no part of this response reaches the ledger (M4).
            ]));

        if ($response instanceof ConnectorDeliveryResult || ! $response->successful()) {
            return ConnectorDeliveryResult::unconfirmed(
                $response instanceof ConnectorDeliveryResult ? null : $response->status(),
                '[unconfirmed_write] A previous attempt may already have added this record, and we could not check. Nothing was written.',
            );
        }

        $records = $response->json('records');

        return is_array($records) && $records !== []
            ? ConnectorDeliveryResult::delivered(null, 'ok (already present)')
            : null;
    }

    /**
     * One value as an Airtable formula string literal.
     *
     * The only value ever passed here is a submission UUID, so the escaping is belt-and-braces rather than
     * load-bearing — which is exactly why it is written out: the day someone reaches for a second identity
     * column, the quoting is already correct instead of being discovered to be missing.
     */
    private static function formulaString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /**
     * The mapping's positional row, keyed back onto the destination's VERBATIM field names.
     *
     * ⚠️ THIS IS THE ONE PLACE AIRTABLE GENUINELY DIFFERS FROM SHEETS, AND IT IS EASY TO GET SUBTLY WRONG.
     * A spreadsheet append is POSITIONAL — `ColumnMapping::project()` hands back a list and column 3 is column
     * 3. Airtable writes `{"fields": {"<name>": value}}`, so the positions have to be turned back into names.
     *
     * The names MUST come from `$fieldNames` — what the table actually calls its fields — and never from
     * `$mapping->fingerprint->headers`, which {@see ColumnFingerprint::normalize()}
     * casefolds and whitespace-collapses. Writing to `full name` when the field is `Full Name` is not a
     * near-miss: Airtable treats it as an unknown field and refuses the whole record. The two lists are index-
     * aligned here because the caller has already established there is NO DRIFT, which is precisely the claim
     * "the same headers, in the same order".
     *
     * An empty value is OMITTED rather than written as `''`. In a positional row a blank is load-bearing —
     * dropping it would shift every later column — but in a keyed object an absent key simply leaves the field
     * untouched, which is what "leave empty" means, and is safer than asking a Number or Date field to accept
     * an empty string.
     *
     * @param  list<string>  $row
     * @param  list<string>  $fieldNames
     * @return array<string, string>
     */
    private static function fieldsFor(array $row, array $fieldNames): array
    {
        $fields = [];

        foreach ($row as $index => $value) {
            $name = $fieldNames[$index] ?? null;

            if ($name === null || $name === '' || $value === '') {
                continue;
            }

            $fields[$name] = $value;
        }

        return $fields;
    }

    /**
     * The destination table's field names, verbatim and in order — or a terminal outcome if unreadable.
     *
     * `$table` may be an id or a name (the config prefers the id), so the match tries both. Airtable has no
     * "get one table" endpoint, so this reads the base's schema and picks; it is one call either way.
     *
     * @return list<string>|ConnectorDeliveryResult
     */
    private function readFieldNames(Connection $connection, string $baseId, string $table): array|ConnectorDeliveryResult
    {
        $response = $this->send(self::META_BASE, fn (): Response => $this->request($connection)
            ->get(self::META_BASE.'/'.rawurlencode($baseId).'/tables'));

        if ($response instanceof ConnectorDeliveryResult) {
            return $response;
        }

        if (! $response->successful()) {
            return $this->classifyFailure($response);
        }

        $tables = $response->json('tables');

        foreach (is_array($tables) ? $tables : [] as $row) {
            if (! is_array($row) || (($row['id'] ?? null) !== $table && ($row['name'] ?? null) !== $table)) {
                continue;
            }

            $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];

            return array_values(array_map(
                static fn (mixed $field): string => is_array($field) && is_string($field['name'] ?? null) ? $field['name'] : '',
                $fields,
            ));
        }

        // The base is readable and the table is not in it — renamed past its id, or deleted. Rule-level and
        // human-fixable, and no amount of retrying brings it back.
        return ConnectorDeliveryResult::blocked(null, '[not_found] That table isn’t in the base any more. Open the rule and pick it again.');
    }

    /**
     * One submission's cells, in the mapping's column order — or null if it is gone.
     *
     * Runs under the delivery job's tenant context, so the read is RLS-scoped: a submission belonging to
     * another tenant is invisible rather than forbidden, and reads as "gone", which is the correct outcome.
     *
     * Byte-for-byte the same projection Sheets uses, deliberately — this is the shared mapping engine doing its
     * job, and a second copy of the locale rule is exactly what {@see ColumnMapping} exists to prevent.
     *
     * @return list<string>|null
     */
    private function rowFor(string $submissionId, ColumnMapping $mapping): ?array
    {
        $submission = Submission::query()->with(['answers', 'respondent:id,name', 'formVersion.form'])->find($submissionId);
        $version = $submission?->formVersion;

        if ($version === null) {
            return null;
        }

        // The FORM's default locale, not the respondent's (H6b): a table is read as one document, so a column
        // of choice labels in per-respondent languages is unusable for analysis.
        $locale = $version->form->default_locale ?? 'en';

        [, $fieldMeta] = $this->projector->resolveColumns(collect([$version]), $locale);

        // Metadata second so a form field could never shadow a reserved `__`-prefixed key.
        $values = array_merge(
            $this->projector->answerValues($submission, $fieldMeta, $locale),
            $this->projector->metaValues($submission),
        );

        return $mapping->project($values);
    }

    /**
     * Add one record to the table.
     *
     * ⚠️ `typecast: true` IS A DECISION, NOT A DEFAULT (user-ratified 2026-08-13). A form answer is always
     * text, and a mapped Airtable field is very often a Number, Date or Single select. Without coercion the
     * FIRST real submission 422s and pauses the rule, which reads as a broken integration rather than as a
     * field-type mismatch. Zapier and Make both send it for the same reason.
     *
     * The cost is disclosed rather than hidden, in ADR-0009 and in the GDPR sub-processor bullet: typecast can
     * ADD an option to a single-select field when an answer does not match an existing one — a schema side
     * effect Airtable permits on the data scope alone, which is why it needed saying out loud even though
     * `schema.bases:write` was refused.
     *
     * @param  array<string, string>  $fields
     */
    private function createRecord(Connection $connection, string $baseId, string $table, array $fields): ConnectorDeliveryResult
    {
        $url = self::API_BASE.'/'.rawurlencode($baseId).'/'.rawurlencode($table);

        $response = $this->send(self::API_BASE, fn (): Response => $this->request($connection)
            ->post($url, [
                'records' => [['fields' => (object) $fields]],
                'typecast' => true,
            ]), write: true);

        if ($response instanceof ConnectorDeliveryResult) {
            return $response;
        }

        // ⚠️ `'ok'`, NOT THE BODY, AND THE SIBLING ADAPTER'S DOCBLOCK IS THE REASON (M4). Airtable's
        // create-record response ECHOES the `fields` object just written, so passing an excerpt of it put the
        // RESPONDENT'S ANSWERS into `webhook_deliveries.response_body_excerpt` -- a table with no retention
        // job, which a submission delete does not touch, so the copy outlived an erasure request.
        // {@see GoogleSheetsConnector} bullet 1 states the property this broke: the shared ledger stores only
        // the metadata envelope, and never becomes a second copy of answer content
        // (`docs/data-privacy-gdpr-compliance.md` §7 offers it as STRUCTURAL rather than promised).
        // The FAILURE paths keep their excerpt on purpose: those bodies are Airtable's error copy, an admin
        // needs them to fix a rule, and `classifyFailure()` already replaces the provider's wording with ours
        // wherever it reaches a person.
        return $response->successful()
            ? ConnectorDeliveryResult::delivered($response->status(), 'ok')
            : $this->classifyFailure($response);
    }

    private function request(Connection $connection): PendingRequest
    {
        return Http::withToken($connection->access_token)
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout((int) config('webhooks.connect_timeout', 5))
            ->timeout((int) config('webhooks.delivery_timeout', 10));
    }

    /**
     * Run one API call, converting a transport error into a retryable outcome.
     *
     * ⚠️ `$write` IS THE DIFFERENCE BETWEEN "IT FAILED" AND "WE DO NOT KNOW" (M5). A lost answer on a READ is
     * just a failure — nothing changed at Airtable and re-reading costs nothing. A lost answer on the RECORD
     * CREATE is the one case that cannot be re-driven blind: the request left, Airtable had every chance to
     * commit it, and the create endpoint accepts no idempotency token that would make a second attempt safe.
     * The SSRF refusal above is deliberately NOT affected — nothing left this process, so there is nothing to
     * be unsure about even for a write.
     *
     * @param  callable(): Response  $call
     */
    private function send(string $host, callable $call, bool $write = false): Response|ConnectorDeliveryResult
    {
        try {
            $this->guard->assertPublic($host);
        } catch (BlockedWebhookUrlException $e) {
            return ConnectorDeliveryResult::failed(null, '['.$e->reason.'] '.$e->getMessage());
        }

        try {
            return $call();
        } catch (ConnectionException $e) {
            $excerpt = $this->excerpt('[transport_error] '.$e->getMessage());

            return $write
                ? ConnectorDeliveryResult::unconfirmed(null, $excerpt)
                : ConnectorDeliveryResult::failed(null, $excerpt);
        }
    }

    /**
     * Map one unsuccessful Airtable response onto the ledger's vocabulary.
     *
     * READ THE CLASS DOCBLOCK BEFORE CHANGING THIS. The absence of a `credentialRejected` arm is the decision,
     * not an oversight — inherited from H16a and just as load-bearing here: Airtable's access tokens live 60
     * minutes, so a 401 is overwhelmingly an ordinary expiry, and only {@see refresh()} throwing can tell an
     * expiry from a revoked grant. A terminal 401 would revoke a live connection roughly hourly.
     *
     * ⚠️ AND THE EXCERPTS ARE OURS. A `blocked` excerpt is shown to the tenant on the rule page and reaches an
     * email, so echoing Airtable's own `error.message` would put unreviewed third-party text into both.
     */
    private function classifyFailure(Response $response): ConnectorDeliveryResult
    {
        $status = $response->status();
        $type = is_string($response->json('error.type')) ? (string) $response->json('error.type') : '';

        if ($status === 404 || $type === 'TABLE_NOT_FOUND' || $type === 'NOT_FOUND') {
            return ConnectorDeliveryResult::blocked($status, '[not_found] We can’t open that base or table any more. It may have been deleted, renamed, or unshared. Open the rule and pick it again.');
        }

        if ($status === 403) {
            return ConnectorDeliveryResult::blocked($status, '[permission_denied] This connection isn’t allowed to add records to that base. Check its sharing in Airtable, then try again.');
        }

        // The typecast escape hatch closing: a value Airtable will not coerce into the mapped field's type.
        // Rule-level and human-fixable — retrying sends the identical value to the identical field.
        if ($type === 'INVALID_VALUE_FOR_COLUMN' || $type === 'UNKNOWN_FIELD_NAME') {
            return ConnectorDeliveryResult::blocked($status, '[invalid_value] Airtable refused one of the values — a mapped answer doesn’t fit that field’s type. Open the rule and check the mapping.');
        }

        // 422 is otherwise a malformed request, which is OURS to fix and cannot be fixed by waiting.
        if ($status === 422) {
            return ConnectorDeliveryResult::blocked($status, '[invalid_request] Airtable refused that record. Open the rule and set the mapping up again.');
        }

        // 429 and 5xx are the genuinely retryable ones, and the ledger's ladder is what waits.
        return ConnectorDeliveryResult::failed($status, $this->excerpt($response->body()));
    }

    private function excerpt(string $body): string
    {
        return mb_substr($body, 0, (int) config('webhooks.response_excerpt_bytes', 2000));
    }

    /**
     * The PKCE challenge: base64url(SHA-256(verifier)), unpadded, per RFC 7636 §4.2.
     *
     * The digest is taken over the verifier's ASCII characters, NOT over bytes decoded from it — a verifier is
     * a string in that spec, and treating base64url output as data to decode first is the classic way to build
     * a challenge the provider computes differently and rejects with an opaque `invalid_grant`.
     */
    private static function challengeFor(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * The shared token-endpoint call. Guards the host, authenticates with HTTP Basic, POSTs form-encoded, and
     * converts a transport error or an error body into the caller's exception.
     *
     * @param  array<string, string>  $form
     * @param  callable(string): ConnectorOAuthException  $failure
     * @return array<string, mixed>
     */
    private function postToken(array $form, callable $failure): array
    {
        try {
            $this->guard->assertPublic(self::TOKEN_URL);
        } catch (BlockedWebhookUrlException $e) {
            throw $failure($e->reason);
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->withBasicAuth(
                    (string) config('connectors.providers.airtable.client_id'),
                    (string) config('connectors.providers.airtable.client_secret'),
                )
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->timeout((int) config('webhooks.delivery_timeout', 10))
                ->asForm()
                ->post(self::TOKEN_URL, $form);
        } catch (ConnectionException) {
            // Non-terminal: a timeout is not evidence that the grant is dead, and marking it so would revoke
            // a healthy connection over a dropped packet.
            throw $failure('transport_error');
        }

        $body = $response->json();

        if (! is_array($body) || ! $response->successful() || isset($body['error'])) {
            $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : 'unknown_error';

            throw $failure($error);
        }

        return $body;
    }

    /**
     * The Airtable user id behind a freshly issued token, used as `external_account_id`.
     *
     * Unlike Google — whose `grantFrom()` records a CONSTANT id because reading the real one would cost an
     * identity scope, and accepts the resulting one-connection-per-tenant narrowing — Airtable's `whoami`
     * needs no scope at all. So a tenant can hold two Airtable grants for two different Airtable accounts and
     * `connections_tenant_provider_account_unique` tells them apart, which is the behaviour that index was
     * written for.
     *
     * A failure here fails the CONNECT rather than falling back to a constant: a fallback would let two
     * genuinely different accounts collapse onto one row and silently overwrite each other's tokens, and the
     * user is standing right there and can click Connect again.
     */
    private function accountIdFor(string $accessToken): string
    {
        try {
            $this->guard->assertPublic(self::WHOAMI_URL);
        } catch (BlockedWebhookUrlException $e) {
            throw ConnectorOAuthException::exchangeFailed($e->reason);
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->withToken($accessToken)
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->timeout((int) config('webhooks.delivery_timeout', 10))
                ->get(self::WHOAMI_URL);
        } catch (ConnectionException) {
            throw ConnectorOAuthException::exchangeFailed('identity_unavailable', false);
        }

        $body = $response->json();
        $id = is_array($body) && is_string($body['id'] ?? null) ? $body['id'] : '';

        if (! $response->successful() || $id === '') {
            throw ConnectorOAuthException::exchangeFailed('identity_unavailable', false);
        }

        return $id;
    }

    /**
     * Normalize an Airtable token response into a grant.
     *
     * @param  array<string, mixed>  $body
     */
    private function grantFrom(array $body, ?string $existingRefreshToken, string $accountId, string $accountLabel): ConnectorGrant
    {
        $accessToken = self::accessTokenFrom($body);

        $refreshToken = is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : $existingRefreshToken;

        if ($refreshToken === null) {
            // Airtable issues a refresh token on every successful grant, so its absence on the FIRST exchange
            // means the integration is misconfigured rather than that the tenant did something. Refusing here
            // is what stops a connection that looks healthy for 60 minutes and then cannot be renewed — the
            // same guard `GoogleSheetsConnector` carries for the `access_type=offline` case.
            throw ConnectorOAuthException::exchangeFailed('missing_refresh_token');
        }

        $expiresIn = $body['expires_in'] ?? null;

        return new ConnectorGrant(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: is_int($expiresIn) && $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null,
            scopes: $this->grantedScopes($body),
            externalAccountId: $accountId,
            externalAccountLabel: $accountLabel,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function accessTokenFrom(array $body): string
    {
        $accessToken = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';

        if ($accessToken === '') {
            throw ConnectorOAuthException::exchangeFailed('missing_access_token');
        }

        return $accessToken;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function grantedScopes(array $body): array
    {
        $scope = is_string($body['scope'] ?? null) ? $body['scope'] : '';

        return $scope === '' ? $this->scopes() : array_values(array_filter(explode(' ', $scope)));
    }

    /** @return list<string> */
    private function scopes(): array
    {
        $scopes = config('connectors.providers.airtable.scopes', []);

        return is_array($scopes) ? array_values(array_map(strval(...), $scopes)) : [];
    }

    private function isTerminal(string $error): bool
    {
        return in_array($error, self::CREDENTIAL_ERRORS, true);
    }
}
