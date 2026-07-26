<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\FormClosed;
use App\Services\Webhooks\WebhookEventDispatcher;

/**
 * Auto-discovered synchronous listener for `form.closed` (the TIME close only — capacity does not emit it in
 * H12a). Like {@see DispatchWebhooksForFormOpened}, it runs inside the sweep's per-tenant transaction.
 */
final class DispatchWebhooksForFormClosed
{
    public function __construct(private readonly WebhookEventDispatcher $dispatcher) {}

    public function handle(FormClosed $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
