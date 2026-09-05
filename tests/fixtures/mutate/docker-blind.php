<?php

declare(strict_types=1);

/*
 * A container whose suite is GREEN whatever the target says — the SURVIVED arm.
 *
 * This is the stub that proves the harness still reaches a verdict after M73's refusals were added: a
 * measured, green baseline and a measured, green mutant must print SURVIVED and exit 2, not abort.
 * Without it, a refusal that fired on every run would look identical to a working guard.
 */

$argv = $_SERVER['argv'];

if (in_array('sh', $argv, true)) {
    echo "0\n";

    exit(0);
}

echo "  Tests:    1 passed (1 assertions)\n";

exit(0);
