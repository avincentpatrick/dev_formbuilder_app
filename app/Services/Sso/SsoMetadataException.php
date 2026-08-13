<?php

declare(strict_types=1);

namespace App\Services\Sso;

use RuntimeException;

/**
 * A metadata document this application will not accept (Phase 4, P1a).
 *
 * Every message this carries is written to be shown VERBATIM to a tenant admin pasting metadata from their
 * identity provider, so each one names what was wrong and what to do about it rather than restating the
 * failure. The audience is a competent administrator who is not a SAML expert, and the difference between
 * "invalid metadata" and "this looks like service-provider metadata rather than identity-provider metadata"
 * is the difference between a support ticket and a fix.
 *
 * It carries no untrusted input back to the user — the messages are all constants — so there is no escaping
 * obligation at the render site.
 */
final class SsoMetadataException extends RuntimeException {}
