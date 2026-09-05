<?php

declare(strict_types=1);

/*
 * A `docker` that cannot reach its daemon — the M71/M72 failure mode, reproduced deterministically.
 *
 * Every invocation fails the way a real outage does: a diagnostic on stderr and a non-zero status.
 * `scripts/mutate.php` must refuse at R1 rather than read the empty capture as "no suite is running".
 */

fwrite(STDERR, "Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?\n");

exit(1);
