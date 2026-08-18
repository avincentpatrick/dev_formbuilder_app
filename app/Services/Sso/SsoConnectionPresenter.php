<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\SsoFailureReason;
use App\Http\Controllers\Tenant\Sso\SsoMetadataController;
use App\Models\SsoAuthFailure;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\DomainPresenter;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Authorization\AssignableRoles;

/**
 * Read model for the SSO settings screen (P1a — ADR-0016).
 *
 * ⚠️ `idp_certificates` IS NEVER IN THE OUTPUT, AND THAT IS THIS CLASS'S FIRST JOB. The model marks it
 * `$hidden`, which covers `toArray()`/`toJson()` and nothing else — `$connection->only([...])` and
 * `getOriginal()` both walk straight past it and both DECRYPT. So this presenter names every field it emits
 * explicitly and never spreads the model. What travels instead is the set fingerprint plus, from
 * {@see SsoCertificateInspector}, facts ABOUT each key: subject, issuer, validity window, state.
 *
 * ⚠️ `entitled` IS A DISCLOSURE, NOT A GATE — the {@see DomainPresenter} rule, and it
 * applies here for the same reason. ADR-0016 §D5 leaves the read, the status toggle and the delete ungated,
 * so this flag only hides the affordances that would bounce. It is deliberately not paired with an upgrade
 * CTA: ADR-0008 §D6 seeds Enterprise `is_active = false`, and a call to action pointing at a plan nobody can
 * buy is worse than silence.
 *
 * ⚠️ THE SP URLS ARE READ FROM {@see SsoMetadataController}, NEVER RE-DERIVED. That controller's docblock
 * makes the SP entity id and the ACS location its own single composition point, because the ACS URL
 * published in metadata is the same string a later assertion's `Destination` is checked against — two
 * derivations means eventually checking a string against itself and calling it a match.
 *
 * Tenant-request-only: `$user->can()` and `EntitlementService::feature()` both resolve false off-tenant, so
 * a central-domain caller would silently be told it has no permissions rather than erroring.
 */
final class SsoConnectionPresenter
{
    /**
     * How many refusals the panel shows. Well under `saml.failure_log_max_rows` on purpose: the store's cap
     * is a safety bound against a grinder, and showing all of it would turn a diagnostic list into a wall.
     */
    private const RECENT_FAILURES = 20;

    public function __construct(
        private readonly SsoConnectionService $connections,
        private readonly SsoCertificateInspector $inspector,
        private readonly SsoGate $gate,
    ) {}

    /**
     * The `/settings/sso` props.
     *
     * @return array<string, mixed>
     */
    public function page(User $user, Tenant $tenant): array
    {
        $connection = $this->connections->current();
        $manage = $user->can('tenant.settings.manage');
        $entitled = $this->gate->isEntitled();

        return [
            'data' => $connection === null ? null : $this->row($connection),
            // P1c. Discharges §D19's owed work: until now a member whose IdP clock had drifted saw a bare
            // 404 and their admin had no in-app view of why.
            'failures' => $connection === null ? [] : $this->failures(),
            'sp' => [
                'entity_id' => SsoMetadataController::entityId($tenant),
                'acs_url' => SsoMetadataController::assertionConsumerServiceUrl($tenant),
                // The same URL as the entity id, by construction — the document is served from the
                // identifier that names it. Emitted separately anyway because an admin pasting into an IdP
                // console is filling two differently-labelled boxes and should not have to know that.
                'metadata_url' => SsoMetadataController::entityId($tenant),
                // P1b. Unlike the three above, this one is for the tenant's own PEOPLE rather than for
                // their IdP: it is the address a member visits to start a sign-in. It is published here
                // because it is currently the only way in — the sign-in page's own "continue with SSO"
                // button belongs to the auth vertical, which another lane is rebuilding, and shipping a
                // button into a file being rewritten underneath us is how two lanes lose an afternoon.
                'login_url' => SsoMetadataController::loginUrl($tenant),
            ],
            'roles' => AssignableRoles::options(),
            'nameIdFormats' => $this->nameIdFormats(),
            'attributeKeys' => SsoConnectionService::ATTRIBUTE_KEYS,
            'can' => [
                // `configure` and `manage` differ ONLY by the plan feature, and splitting them is what lets a
                // downgraded tenant keep Disable and Remove while losing Import and the policy controls.
                'configure' => $manage && $entitled,
                'manage' => $manage,
            ],
            'entitled' => $entitled,
        ];
    }

    /**
     * The `/settings` signpost prop.
     *
     * ⚠️ `visible` IS `entitled OR configured`, WHICH IS STRICTLY WIDER THAN THE CUSTOM-DOMAINS CARD'S
     * `count > 0`, and the difference is the whole point. That card can be narrow because `/domains` also
     * has a sidebar entry for the entitled-but-empty case; SSO has no nav entry at all (§D6), so if this
     * card waited for a connection to exist, an entitled tenant would have no first way in. The
     * `configured` half is then the downgrade escape hatch, exactly as the domains card is.
     *
     * Deliberately does NOT run the certificate inspector: this is three booleans and a label, and putting
     * an `openssl_x509_parse` on every `/settings` render to surface an expiry warning one page early is a
     * real cost for one line of text.
     *
     * @return array<string, mixed>
     */
    public function settingsCard(User $user, Tenant $tenant): array
    {
        $manage = $user->can('tenant.settings.manage');

        if (! $manage) {
            return ['visible' => false, 'configured' => false, 'entitled' => false, 'status' => null, 'status_label' => null];
        }

        $connection = $this->connections->current();
        $entitled = $this->gate->isEntitled();

        return [
            'visible' => $entitled || $connection !== null,
            'configured' => $connection !== null,
            'entitled' => $entitled,
            'status' => $connection?->status->value,
            'status_label' => $connection?->status->label(),
        ];
    }

    /**
     * The recent refusals this workspace's admin may act on (P1c — ADR-0016 §D26).
     *
     * ⚠️ THIS IS NOT THE ORACLE §D19 REFUSES TO BE, AND THE DIFFERENCE IS THE AUDIENCE. That posture makes
     * every ACS response an indistinguishable 404 because an UNAUTHENTICATED caller who learns "wrong
     * audience" has learned that the signature verified. The reader here is authenticated, holds
     * `tenant.settings.manage` on this workspace, and is asking why their own colleague cannot sign in.
     * Telling them discloses nothing an attacker could reach without already being an admin of the
     * workspace they are attacking.
     *
     * ⚠️ THE ROWS ARE TRIMMED AT THE WRITE, so "twenty most recent" is a page, not an archive. Nothing here
     * paginates deliberately: a panel that invited an admin to walk backwards through a bounded, silently
     * shortening list would be promising history the store does not keep.
     *
     * `label` and `hint` are composed server-side from {@see SsoFailureReason} rather than mapped in the
     * page, so the vocabulary the database CHECK pins, the thing the log prints and the sentence an admin
     * reads all come from one enum.
     *
     * @return list<array<string, mixed>>
     */
    private function failures(): array
    {
        $failures = SsoAuthFailure::query()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_FAILURES)
            ->get();

        // Appended to a plain array rather than mapped over the collection, matching
        // {@see TenantMembershipService::listMembers()}. `map()->all()` PRESERVES KEYS, and a prop that
        // reached Inertia as an object with numeric keys instead of an array would render nothing while
        // every server-side assertion still passed.
        $rows = [];

        foreach ($failures as $failure) {
            $rows[] = [
                'id' => (string) $failure->getKey(),
                'reason' => $failure->reason->value,
                'reason_label' => $failure->reason->label(),
                'hint' => $failure->reason->hint(),
                'subject_email' => $failure->subject_email,
                'request_id' => $failure->request_id,
                'ip_address' => $failure->ip_address,
                'occurred_at' => $failure->occurred_at->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SsoConnection $connection): array
    {
        $certificates = $this->inspector->inspect($connection->idp_certificates);
        $rollup = $this->inspector->rollup($certificates);
        $emailFormat = (string) config('saml.default_name_id_format');

        return [
            'status' => $connection->status->value,
            'status_label' => $connection->status->label(),
            'protocol_label' => $connection->protocol->label(),
            // The row's own answer to "may the protocol endpoints serve?", stated as a field rather than
            // left to the page's arithmetic — it is `status` AND a non-empty key set, and the second half
            // is invisible from the props.
            'serves_protocol' => $connection->servesProtocol(),
            'idp_entity_id' => $connection->idp_entity_id,
            'idp_sso_url' => $connection->idp_sso_url,
            'name_id_format' => $connection->name_id_format,
            // Whether the picker can represent what is stored. An import can legitimately write a urn the
            // four-option list does not carry, and silently re-selecting the nearest one would rewrite the
            // tenant's IdP contract on their next unrelated save.
            'name_id_format_known' => array_key_exists($connection->name_id_format, SsoConnectionService::NAME_ID_FORMATS),
            'name_id_format_is_email' => $connection->name_id_format === $emailFormat,
            'jit_provisioning_enabled' => $connection->jit_provisioning_enabled,
            'default_role_name' => $connection->default_role_name,
            'default_role_label' => AssignableRoles::label($connection->default_role_name),
            'attribute_map' => $connection->attribute_map,
            'fingerprint_short' => $connection->certificateFingerprintShort(),
            'certificate_count' => count($connection->idp_certificates),
            'certificates' => $certificates,
            'certificates_state' => $rollup['state'],
            'certificate_warning' => $rollup['warning'],
            'metadata_imported_at' => $connection->idp_metadata_imported_at?->toIso8601String(),
            'last_login_at' => $connection->last_login_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function nameIdFormats(): array
    {
        $formats = [];

        foreach (SsoConnectionService::NAME_ID_FORMATS as $value => $label) {
            $formats[] = ['value' => $value, 'label' => $label];
        }

        return $formats;
    }
}
