<?php

declare(strict_types=1);
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full framework against real PostgreSQL (never sqlite
| — RLS can't be validated on sqlite; see docs/testing-strategy.md §2).
| RefreshDatabase is applied per-file where a test actually touches the DB.
|
*/

pest()->extend(TestCase::class)->in('Feature');
