<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\AuditEvent;
use App\Enums\SsoConnectionStatus;
use App\Models\SsoConnection;
use App\Models\User;
use App\Services\Tenancy\CustomDomainService;
use App\Services\Tenancy\TenantSettingsService;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every write to a tenant's federated-identity trust anchor (P1a — ADR-0016).
 *
 * ── THE CONNECTION IS A SINGLETON, AND RLS IS THE SCOPING ────────────────────────────────────────────
 * `sso_connections` is UNIQUE on `tenant_id` (§D2), so there is exactly one row to find and no id to take.
 * And unlike {@see CustomDomainService} — whose table is RLS-EXEMPT and which
 * therefore filters `where('tenant_id', …)` by hand in every method — this table is strict FORCE RLS, so
 * `SsoConnection::query()->first()` IS the tenant scoping. Do not add a redundant `where`: it would read as
 * though the isolation lived in PHP, which here it does not.
 *
 * ⚠️ THE AUDIT PAYLOAD IS HAND-BUILT, AND `getOriginal()` IS THE TRAP IT AVOIDS.
 * `Model::getOriginal()` rebuilds the model with `setRawAttributes()` and maps every attribute through
 * `transformModelValue()`, which APPLIES THE CAST — so on `idp_certificates` (`encrypted:array`) it returns
 * the DECRYPTED certificate list. `$hidden` does not help: it is consulted only by `attributesToArray()`.
 * The sibling methods are no better in the other direction — `getDirty()`/`getChanges()` read
 * `$this->attributes`, which holds the multi-KB ciphertext envelope. So the repo's ordinary snapshot idiom,
 * `Arr::only($model->getOriginal(), array_keys($columns))`
 * ({@see TenantSettingsService}), would write either plaintext IdP signing keys or a
 * wall of base64 into `audits` — a table that is append-only by RLS policy and NEVER pruned.
 * {@see snapshot()} is therefore explicit, and `idp_certificates` is structurally absent from it. The
 * `AuditRedactor::SECRETS` registration under the `sso_connection` alias is the backstop for a future
 * refactor, not the mechanism.
 *
 * ⚠️ AND NEVER ASK ELOQUENT WHETHER THE ANCHOR CHANGED. With `APP_PREVIOUS_KEYS` set — i.e. during any
 * APP_KEY rotation window — `originalIsEquivalent()` short-circuits to `false` for every `encrypted:` cast,
 * so `idp_certificates` is PERMANENTLY dirty and every unrelated save would file a "the trust anchor
 * rotated" row in an immutable ledger. That is precisely the alert this feature must not train an admin to
 * ignore. Compare `idp_certificates_fingerprint`, which is plaintext and deterministic and exists for this.
 */
final class SsoConnectionService
{
    /**
     * The audit-log alias, in one place.
     *
     * A literal at each call site is how the singular/plural slip happens, and that slip is silent: an
     * `sso_connections` row would still write and still look valid while `AuditRedactor` — keyed on the
     * SINGULAR — quietly stopped redacting. Verbatim the `feedback_reports` trap the audit spec records.
     */
    public const string AUDIT_TYPE = 'sso_connection';

    /** The assertion attributes a tenant may remap. Anything else is ignored rather than stored. */
    public const array ATTRIBUTE_KEYS = ['email', 'name', 'first_name', 'last_name'];

    /** NameID formats the picker can represent. A stored value outside this set renders read-only. */
    public const array NAME_ID_FORMATS = [
        'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress' => 'Email address',
        'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent' => 'Persistent',
        'urn:oasis:names:tc:SAML:2.0:nameid-format:transient' => 'Transient',
        'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified' => 'Unspecified',
    ];

    public function __construct(
        private readonly SsoMetadataParser $parser,
        private readonly SsoGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    /** The tenant's connection, whatever its status. RLS is the scoping; see the class docblock. */
    public function current(): ?SsoConnection
    {
        return SsoConnection::query()->first();
    }

    /**
     * Replace the whole identity-provider half from a metadata document.
     *
     * A PUT, never a merge — {@see SsoMetadataParser}'s docblock: "a partial import is precisely how a stale
     * trust anchor survives a key rotation". All six parsed fields land together or none do.
     *
     * ⚠️ `status` IS NEVER WRITTEN HERE, AND THIS IS THE MOST IMPORTANT LINE IN THE CLASS. Re-importing is
     * how a tenant picks up a rotated signing key, and a rotation that silently took SSO offline would be an
     * outage caused by doing the right thing. A NEW row gets `draft` from the column default (which is what
     * {@see SsoConnectionStatus::Draft} is for); an existing row keeps whatever it had.
     *
     * @throws SsoMetadataException carrying a message written to be shown to a tenant admin verbatim
     */
    public function importMetadata(string $xml, ?User $actor = null): SsoConnection
    {
        $parsed = $this->parser->parse($xml);

        return DB::transaction(function () use ($parsed, $actor): SsoConnection {
            // lockForUpdate, because updateOrCreate is check-then-insert and this table is UNIQUE on
            // tenant_id: two concurrent imports (a double-clicked button is enough) both miss the SELECT,
            // both INSERT, and one dies on the unique index — and `bootstrap/app.php` has no QueryException
            // arm, so that surfaces as a 500 rather than as anything a tenant can act on.
            $connection = SsoConnection::query()->lockForUpdate()->first();
            $isNew = $connection === null;

            $attributes = [
                'idp_entity_id' => $parsed['idp_entity_id'],
                'idp_sso_url' => $parsed['idp_sso_url'],
                'idp_certificates' => $parsed['idp_certificates'],
                'idp_certificates_fingerprint' => $parsed['idp_certificates_fingerprint'],
                'idp_metadata_sha256' => $parsed['idp_metadata_sha256'],
                // The parser reports what the document SAYS and may say nothing — <md:NameIDFormat> is
                // minOccurs="0" and ADFS routinely omits it — while the column is NOT NULL. The DB default
                // does not cover it: an explicit null is still an explicit null, and on the UPDATE path a
                // column default is irrelevant entirely. Coerced here, at the one seam that knows both.
                'name_id_format' => $parsed['name_id_format'] ?? (string) config('saml.default_name_id_format'),
                // Stamped by the service; the parser has no clock and no opinion about persistence.
                'idp_metadata_imported_at' => Carbon::now(),
            ];

            if ($isNew) {
                $attributes['created_by'] = $actor?->getKey() === null ? null : (string) $actor->getKey();
            }

            $old = $isNew ? null : $this->snapshot($connection);

            if ($connection === null) {
                $connection = new SsoConnection;
            }

            $connection->fill($attributes)->save();

            // REFRESH BEFORE SNAPSHOTTING. A freshly created model carries only what was assigned, so
            // `status`, `attribute_map`, `jit_provisioning_enabled` and `default_role_name` — all of which
            // came from column DEFAULTS — would be absent from the `new` side, and the next policy edit
            // would then read as `(absent) → active`. The TenantSettingsService lesson, same shape.
            $connection->refresh();

            $this->record($isNew ? AuditEvent::Created : AuditEvent::Updated, $connection, $old, $actor);

            return $connection;
        });
    }

    /**
     * Amend the policy half — everything that is not the trust anchor.
     *
     * Returns null when there is no connection to amend, which the controller turns into a refusal rather
     * than creating one: policy without a trust anchor is not a representable state.
     *
     * @param  array<string, mixed>  $columns  only the keys the caller actually sent
     */
    public function updatePolicy(array $columns, ?User $actor = null): ?SsoConnection
    {
        if ($columns === []) {
            return $this->current();
        }

        return DB::transaction(function () use ($columns, $actor): ?SsoConnection {
            $connection = SsoConnection::query()->lockForUpdate()->first();

            if ($connection === null) {
                return null;
            }

            $old = $this->snapshot($connection);
            $connection->fill($columns)->save();
            $connection->refresh();

            $this->record(AuditEvent::Updated, $connection, $old, $actor);

            return $connection;
        });
    }

    /**
     * Move the connection between `active` and `disabled`.
     *
     * ⚠️ SEPARATE FROM {@see updatePolicy()} BECAUSE IT SITS ON THE OTHER SIDE OF THE ENTITLEMENT GATE, AND
     * THE FIRST DESIGN GOT THIS BACKWARDS. Its route carries `can:tenant.settings.manage` alone, so a tenant
     * downgraded off Enterprise can still DISABLE — ADR-0012 §D9's escape hatch. With `status` on the gated
     * write, the only action left to a downgraded tenant was to DELETE the trust anchor: "destroy or
     * nothing", which is the opposite of an escape hatch and against {@see SsoConnectionStatus::Disabled}'s
     * own contract ("retained rather than deleted so the trust anchor and its audit history survive").
     *
     * Enabling is the redo, so it still needs the plan: a tenant may always undo, never redo.
     */
    public function changeStatus(SsoConnectionStatus $status, ?User $actor = null): ?SsoConnection
    {
        if ($status === SsoConnectionStatus::Active && ! $this->gate->isEntitled()) {
            return null;
        }

        return $this->updatePolicy(['status' => $status], $actor);
    }

    /**
     * Remove the trust anchor entirely.
     *
     * Ungated by the plan feature, deliberately (§D5). `sso_auth_requests` goes with it through the
     * composite FK's cascade — tenant-confined by construction, which is why that FK is composite at all
     * (ADR-0002 §D5: PostgreSQL runs referential actions bypassing RLS).
     */
    public function delete(?User $actor = null): bool
    {
        return DB::transaction(function () use ($actor): bool {
            $connection = SsoConnection::query()->lockForUpdate()->first();

            if ($connection === null) {
                return false;
            }

            $old = $this->snapshot($connection);
            $deleted = (bool) $connection->delete();

            if ($deleted) {
                // Snapshot taken BEFORE the delete and recorded after it: hard-deleted, so this row is the
                // only surviving record that the tenant ever trusted this identity provider.
                $this->record(AuditEvent::Deleted, $connection, $old, $actor, deleted: true);
            }

            return $deleted;
        });
    }

    /**
     * The audit snapshot — explicit, never model-derived. See the class docblock for why.
     *
     * `idp_certificates_fingerprint` is what carries the trust anchor's identity into the ledger: 64
     * characters that answer "did the signing keys change?" completely, without the ledger ever holding a
     * key. That is the `tenants.brand_ramp` argument (record the input, not the derived blob), not the
     * `connections.access_token` one (include it so the redactor is seen to strip it) — a certificate is not
     * a credential, so there is nothing to be seen stripping.
     *
     * @return array<string, mixed>
     */
    private function snapshot(SsoConnection $connection): array
    {
        return [
            'status' => $connection->status->value,
            'idp_entity_id' => $connection->idp_entity_id,
            'idp_sso_url' => $connection->idp_sso_url,
            'idp_certificates_fingerprint' => $connection->idp_certificates_fingerprint,
            'idp_certificate_count' => count($connection->idp_certificates),
            'idp_metadata_sha256' => $connection->idp_metadata_sha256,
            'name_id_format' => $connection->name_id_format,
            'jit_provisioning_enabled' => $connection->jit_provisioning_enabled,
            'default_role_name' => $connection->default_role_name,
            'attribute_map' => $connection->attribute_map,
        ];
    }

    /**
     * Emit one `sso_connection` row, or nothing at all — the guard is the point.
     *
     * Both arms are kept even though the second is structurally unreachable here (strict RLS means this
     * service can only ever hold the ambient tenant's row, unlike `CustomDomainService` whose table is
     * exempt). A guard that diverges between two services is how the arm that matters goes missing in the
     * third, and the cost of keeping it is one comparison.
     *
     * @param  ?array<string, mixed>  $old
     */
    private function record(
        AuditEvent $event,
        SsoConnection $connection,
        ?array $old,
        ?User $actor,
        bool $deleted = false,
    ): void {
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId === null || $tenantId !== (string) $connection->tenant_id) {
            return;
        }

        $this->audit->record(
            $event,
            self::AUDIT_TYPE,
            // The TENANT's id, not the connection's — and not for the `domain` row's reason, since this id
            // IS a uuid. The connection is a singleton a tenant may delete and re-create, so keying on the
            // row would split one workspace's SSO history across two auditable_ids. Audit spec §1.
            $tenantId,
            old: $old,
            new: $deleted ? null : $this->snapshot($connection),
            actorId: $actor?->getKey() === null ? null : (string) $actor->getKey(),
        );
    }
}
