<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\ReadsTextInput;
use App\Support\Platform\PortalRedirects;
use Illuminate\Console\Command;

/**
 * M253 (`R-43038865`, `D236`) — prints the portal vhost's redirects for links from before the switch to three
 * addresses, generated from this app's route table ({@see PortalRedirects}). Run on the box at the switch, after the
 * deploy that carries the routes, and paste the output into `iconnect.com.de`'s `*:443` vhost (runbook §8.3).
 * Writes nothing.
 */
final class PortalRedirectsCommand extends Command
{
    use ReadsTextInput;

    protected $signature = 'portal:redirects
                            {--collection=collection.iconnect.com.de : iConnect Collection\'s host}
                            {--analytics=analytics.iconnect.com.de : iConnect Analytics\' host}';

    protected $description = 'Print the portal vhost\'s Apache redirects for links from before the switch to three addresses';

    public function handle(PortalRedirects $redirects): int
    {
        $collection = $this->textOption('collection');
        $analytics = $this->textOption('analytics');

        foreach (['collection' => $collection, 'analytics' => $analytics] as $name => $host) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) !== 1) {
                $this->error("--{$name} must be a bare host name, such as {$name}.iconnect.com.de.");

                return self::FAILURE;
            }
        }

        $this->output->write($redirects->apacheBlock($collection, $analytics));

        return self::SUCCESS;
    }
}
