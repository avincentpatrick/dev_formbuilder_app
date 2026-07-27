<?php

declare(strict_types=1);

namespace App\Support\Connectors;

/**
 * One destination a connector can deliver into — a Slack channel today, a spreadsheet or a base when H16 lands
 * (H15b). Provider-neutral by construction: {@see ListsChannels} implementations reduce a wire response to this
 * shape so no provider field name escapes the adapter, the same rule {@see ConnectorGrant} and
 * {@see ConnectorDeliveryResult} exist to enforce.
 *
 * `$available` is NOT authorization and NOT a hard block. For Slack it means "our bot is already a member of
 * this channel": `channels:read` lists every public channel including ones the app has never been invited to,
 * while `chat:write` can only post to channels it has joined. A tenant may legitimately pick an unavailable
 * channel and invite the app afterwards, so the picker warns and lets them proceed — refusing would be wrong,
 * and staying silent would ship a rule that fails on its first real event with `not_in_channel`.
 */
final readonly class ConnectorChannel
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $available = true,
        public ?string $unavailableReason = null,
    ) {}

    /**
     * @return array{id: string, label: string, available: bool, unavailable_reason: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'available' => $this->available,
            'unavailable_reason' => $this->unavailableReason,
        ];
    }
}
