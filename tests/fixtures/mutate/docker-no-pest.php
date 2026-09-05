<?php

declare(strict_types=1);

/*
 * ⛔ THE DISCRIMINATOR, AND THE ONLY STUB THAT SEPARATES THE TWO GUARDS.
 *
 * A container that is ALIVE — so R1's `ps | wc -l` probe answers `0` and the concurrent-suite guard
 * passes honestly — but that has no PHP, so the Pest exec never runs. This is the exact shape of a real
 * php-less container (`dev_formbuilder_app-redis-1` on the dev host: busybox `sh`, `ps` and `wc`, no
 * `php`), and it is what the `run_pest()` refusal exists for.
 *
 * Without this stub the case cannot be driven on a CI runner, where no such container exists: the run
 * would abort at R1 instead and the control would silently prove the wrong guard.
 *
 * ⚠️ Note what it does NOT do: it never prints a `Tests:` line. That absence is the whole signal — the
 * harness must not read "no summary" as "zero failures".
 */

$argv = $_SERVER['argv'];

// `exec <container> sh -c '<probe>'` — the container is up and no suite is running.
if (in_array('sh', $argv, true)) {
    echo "0\n";

    exit(0);
}

// `exec <container> php -d memory_limit=2G vendor/bin/pest <tests>` — there is no PHP in this image.
fwrite(STDERR, "OCI runtime exec failed: exec failed: unable to start container process: exec: \"php\": executable file not found in \$PATH: unknown\n");

exit(126);
