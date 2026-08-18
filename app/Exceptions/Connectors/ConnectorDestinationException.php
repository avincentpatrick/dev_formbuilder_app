<?php

declare(strict_types=1);

namespace App\Exceptions\Connectors;

use App\Services\Connectors\TabularDestinationDirectory;
use RuntimeException;

/**
 * A provider refused or failed a tabular-destination operation — creating a spreadsheet, or reading one's tabs
 * and header row (H16b). The twin of {@see ConnectorChannelException}, and separate from it for the reason
 * that class's own concept is separate: listing channels and provisioning a document fail in different
 * vocabularies, and one `match` over both would have arms no caller can reach.
 *
 * The machine code travels separately from the message, the {@see ConnectorOAuthException} convention, so the
 * caller maps it to copy the tenant can act on and the provider's own wording never reaches a page.
 *
 * ⚠️ CREATE IS A WRITE, AND THAT MAKES THIS EXCEPTION LESS SOFT THAN ITS TWIN.
 * {@see TabularDestinationDirectory} still reduces it to an `error` string rather than a 5xx — a failed setup
 * step must leave the tenant on the page they were filling in — but a failed `create` may have left a
 * spreadsheet in the tenant's Drive with no rule pointing at it. That is deliberately not cleaned up: deleting
 * a file we cannot prove is ours-and-empty is a worse failure than an orphan the tenant can see and remove.
 */
final class ConnectorDestinationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('The provider could not resolve the destination ('.$errorCode.').');
    }

    public static function failed(string $errorCode): self
    {
        return new self($errorCode);
    }
}
