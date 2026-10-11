<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-app user manual (M162, `R-f9211765`, `D114`) — how to create a form, from a blank page to an exported response.
 *
 * ⚠️ NO PROPS, AND NO GATE, BOTH ON PURPOSE. The manual is the same text for every signed-in member: its first chapter
 * says who may create a form, so a Reviewer or a Viewer who opens it learns why Forms is not in their sidebar rather than
 * being refused the page that explains it. The content lives in `resources/js/components/help/manual.ts`, where a test
 * reads every on-screen label it quotes against the file that draws that label.
 */
final class HelpController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('help/Index');
    }
}
