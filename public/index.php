<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Answer the requests that stub hands onwards — JSON and Inertia — while the window is open (M99).
//
// ⛔ ABOVE THE AUTOLOADER, AND THAT IS THE WHOLE POINT. The stub above returns early for a request that
// expects JSON or carries the bypass cookie, so those boot the framework while deploy.ps1 is resetting
// the checkout hard, renaming vendor/ and public/build, and running migrate --force. The guard must
// therefore use nothing from vendor/ — half the failure mode IS vendor/ being renamed out from under
// the request. It returns without output whenever the site is up, so the ordinary path is unchanged.
if (file_exists($guard = __DIR__.'/maintenance-guard.php')) {
    require $guard;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
