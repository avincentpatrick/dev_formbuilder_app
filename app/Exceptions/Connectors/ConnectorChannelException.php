<?php

declare(strict_types=1);

namespace App\Exceptions\Connectors;

use App\Services\Connectors\ConnectorChannelDirectory;
use RuntimeException;

/**
 * A provider refused or failed a channel listing (H15b). Mirrors {@see ConnectorOAuthException}: the machine
 * code travels separately from the message so the caller can map it to copy the tenant can act on, and the
 * provider's own wording never reaches a page.
 *
 * This is a READ failure, so it is a soft one — {@see ConnectorChannelDirectory}
 * converts it into an `error` string beside an empty channel list rather than letting it become a 5xx. A Slack
 * outage must degrade the picker, never the page that hosts it.
 */
final class ConnectorChannelException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('The provider could not list channels ('.$errorCode.').');
    }

    public static function failed(string $errorCode): self
    {
        return new self($errorCode);
    }
}
