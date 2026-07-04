<?php

declare(strict_types=1);

// Keeps the Unit test suite non-empty (git doesn't track empty directories) and asserts the
// PHP baseline the whole project targets. Framework-free — no application boot, no database.

it('runs the unit suite on the supported PHP baseline', function () {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80300); // PHP 8.3+
});
