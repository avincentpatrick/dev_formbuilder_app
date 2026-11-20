<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Models\Form;
use App\Models\User;

/**
 * A data source that reads ONE grid of a form (M247, `grid-wide-sticky-explorer`, `D231`; ADR-0025 §D9).
 *
 * A chart on it names the form and the grid. {@see \App\Services\Explorer\ExplorerCatalog::resolve()} asks for the
 * source bound to that grid ({@see forGrid()}) and hands it on, so the runner, the compiler and the page read it as
 * any other source: its fields, its rows and its notes are the bound grid's.
 */
interface GridScopedSource extends ExplorerSource
{
    /**
     * The grids of a form this reader may chart by row, newest wording first: key ⇒ label.
     *
     * @return array<string, string>
     */
    public function grids(User $user, Form $form): array;

    /** This source bound to one grid of the form, or null when the form has no such grid this reader may chart. */
    public function forGrid(User $user, Form $form, string $grid): ?ExplorerSource;
}
