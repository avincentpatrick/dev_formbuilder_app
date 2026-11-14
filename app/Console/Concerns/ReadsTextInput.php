<?php

declare(strict_types=1);

namespace App\Console\Concerns;

/**
 * M238 — a command's argument or option, read as text.
 *
 * Laravel types `argument()` and `option()` as `array|string|float|int|bool|null`. Larastan used to narrow each one from
 * the command's own signature, by building a console application and finding the command in it. Laravel 13.38 moved the
 * commands `load()` discovers off `Artisan::starting` and into the kernel's `$kernelCommands`, which that application
 * never sees, so Larastan now falls back to the wide type and a `(string)` cast on it fails level 8 (`cast.string`).
 *
 * These read the value exactly as that cast did for every scalar and for null, and as '' for an array, which no
 * signature that uses them declares.
 */
trait ReadsTextInput
{
    protected function textArgument(string $key): string
    {
        $value = $this->argument($key);

        return is_array($value) ? '' : (string) $value;
    }

    protected function textOption(string $key): string
    {
        $value = $this->option($key);

        return is_array($value) ? '' : (string) $value;
    }
}
