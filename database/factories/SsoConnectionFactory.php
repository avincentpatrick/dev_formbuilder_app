<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SsoConnectionStatus;
use App\Enums\SsoProtocol;
use App\Models\SsoConnection;
use App\Services\Sso\SsoCertificateInspector;
use App\Services\Sso\SsoMetadataParser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @extends Factory<SsoConnection>
 *
 * `tenant_id` is filled by `BelongsToTenant`'s creating hook from the ambient context, so a test must be
 * inside `enterTenant()` — the {@see WebhookEndpointFactory} situation exactly. Under the strict FORCE RLS
 * on this table, forgetting that does not error: the INSERT matches no policy and writes zero rows.
 */
final class SsoConnectionFactory extends Factory
{
    protected $model = SsoConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $certificate = self::certificate();

        return [
            'protocol' => SsoProtocol::Saml2,
            'status' => SsoConnectionStatus::Draft,
            'idp_entity_id' => 'https://idp.'.fake()->domainName().'/saml2',
            'idp_sso_url' => 'https://idp.'.fake()->domainName().'/saml2/sso',
            'idp_certificates' => [$certificate],
            // Computed by the SAME static the parser and the service use, so a factory-built row and an
            // imported one are indistinguishable to anything that compares fingerprints.
            'idp_certificates_fingerprint' => SsoMetadataParser::fingerprint([$certificate]),
            'idp_metadata_sha256' => hash('sha256', Str::random(64)),
            'idp_metadata_imported_at' => now(),
            'name_id_format' => (string) config('saml.default_name_id_format'),
            'attribute_map' => [],
            'jit_provisioning_enabled' => true,
            'default_role_name' => 'viewer',
            'created_by' => null,
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => ['status' => SsoConnectionStatus::Active]);
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['status' => SsoConnectionStatus::Disabled]);
    }

    /**
     * Replace the certificate set, keeping the fingerprint honest.
     *
     * @param  list<string>  $certificates  bare base64 DER
     */
    public function withCertificates(array $certificates): self
    {
        return $this->state(fn (): array => [
            'idp_certificates' => $certificates,
            'idp_certificates_fingerprint' => SsoMetadataParser::fingerprint($certificates),
        ]);
    }

    /**
     * A certificate set OpenSSL cannot read — the `unreadable` arm of {@see SsoCertificateInspector}.
     *
     * Reachable in production despite the parser refusing unparsable certificates at import: an OpenSSL
     * major-version upgrade can start rejecting a key it once accepted (legacy algorithms, short moduli).
     */
    public function unreadableCertificate(): self
    {
        return $this->withCertificates([base64_encode('this is not a certificate')]);
    }

    /**
     * A real self-signed 2048-bit certificate, valid for `$days` from now.
     *
     * ⚠️ GENERATED, NEVER HARD-CODED. A fixed base64 blob would expire and turn every green test red on some
     * future date, with no code change to blame — the worst kind of flake. And ⚠️ MEMOIZED PER (process,
     * days): `openssl_pkey_new(2048)` costs ~100 ms, and the inspector suite mounts this repeatedly.
     *
     * `notBefore` is always "now" because PHP's OpenSSL API cannot set it — which is why the inspector's
     * `not_yet_valid` arm is reached by travelling the APP's clock backwards (`travelTo(now()->subDay())`)
     * rather than by minting a future certificate. OpenSSL stamps validity from the real system clock and
     * Carbon moves only Laravel's, and that asymmetry is what makes the state testable at all.
     */
    public static function certificate(int $days = 365): string
    {
        /** @var array<int, string> $memo */
        static $memo = [];

        if (isset($memo[$days])) {
            return $memo[$days];
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'idp.example.com'], $key);

        // Each of the three can return false, and level 8 says so. A RuntimeException rather than a silent
        // fallback: a fixture that quietly produced a non-certificate would fail somewhere far away, in the
        // inspector's `unreadable` arm, and read as a product bug.
        if ($key === false || is_bool($csr)) {
            throw new RuntimeException('Could not generate a test signing key.');
        }

        $signed = openssl_csr_sign($csr, null, $key, $days);

        if ($signed === false || openssl_x509_export($signed, $pem) === false) {
            throw new RuntimeException('Could not sign the test certificate.');
        }

        return $memo[$days] = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }
}
