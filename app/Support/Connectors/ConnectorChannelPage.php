<?php

declare(strict_types=1);

namespace App\Support\Connectors;

/**
 * The result of one {@see ListsChannels::channels()} call (H15b): the destinations read, plus whether the
 * provider had more than the page budget allowed.
 *
 * `$truncated` is its own field rather than something the caller infers, because "the list is complete" and
 * "the list is all we were willing to read" are different claims and only the adapter knows which it is
 * returning. A UI that cannot tell them apart implies a missing channel does not exist.
 */
final readonly class ConnectorChannelPage
{
    /**
     * @param  list<ConnectorChannel>  $channels
     */
    public function __construct(
        public array $channels,
        public bool $truncated = false,
    ) {}

    public static function empty(): self
    {
        return new self([], false);
    }
}
