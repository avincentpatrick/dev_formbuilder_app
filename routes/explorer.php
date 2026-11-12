<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\ExplorerChartController;
use App\Http\Controllers\Tenant\ExplorerController;
use App\Http\Controllers\Tenant\ExplorerQueryController;
use App\Models\ExplorerChart;
use App\Policies\ExplorerChartPolicy;
use App\Services\Explorer\ExplorerCatalog;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The data explorer (ADR-0025, M236) — a member's own charts
|--------------------------------------------------------------------------
| A separate page from /analytics, where a member picks a data source they can already read and builds
| their own charts over it.
|
| ⚠️ NOT LOADED BY bootstrap/app.php. This file is `require`d from INSIDE routes/tenant.php's authenticated
| group, so every route here carries that group's whole pipeline — tenant resolution, the session, the
| tenant database context, two-factor enforcement — exactly as the /analytics routes beside the `require`
| do. It is a file of its own for one reason: its four imports would otherwise be four lines added at the
| top of routes/tenant.php, and the documents cite that file by line number.
|
| Behind the existing `advanced_analytics` key: the explorer is the same kind of surface as /analytics, and
| a NEW plan key would not reach a workspace whose plan row was seeded before the key existed.
|
| `can:viewAny` is the gate on the PAGE and grants no data. {@see ExplorerCatalog} decides, per data
| source, whether this reader may chart it and which rows — see {@see ExplorerChartPolicy}.
|
| Three of these are JSON reads the page fetches — the builder's preview, a board tile, a form's questions
| — so changing a group-by is not a page visit. `explore.query` is a POST that changes nothing: a
| declaration is a nested document. It is the one endpoint a reader can aim, so it is throttled as well as
| bounded.
|
| Static segments precede the binding: /explore/new, /explore/fields and /explore/query are declared before
| /explore/charts/{explorerChart}, and there is no /explore/{binding} route at all.
*/

Route::get('/explore', [ExplorerController::class, 'index'])
    ->middleware(['can:viewAny,'.ExplorerChart::class, 'feature:advanced_analytics'])->name('explore.index');
Route::get('/explore/new', [ExplorerController::class, 'create'])
    ->middleware(['can:create,'.ExplorerChart::class, 'feature:advanced_analytics'])->name('explore.create');
Route::get('/explore/fields', [ExplorerQueryController::class, 'fields'])
    ->middleware(['can:viewAny,'.ExplorerChart::class, 'feature:advanced_analytics'])->name('explore.fields');
Route::post('/explore/query', [ExplorerQueryController::class, 'query'])
    ->middleware(['can:viewAny,'.ExplorerChart::class, 'feature:advanced_analytics', 'throttle:120,1'])->name('explore.query');
Route::post('/explore/charts', [ExplorerChartController::class, 'store'])
    ->middleware(['can:create,'.ExplorerChart::class, 'feature:advanced_analytics'])->name('explore.charts.store');

// view/update/delete gate on the BOUND INSTANCE: explorer_charts is `strict` RLS, which scopes to the tenant
// only, so the policy is what keeps a colleague's private chart private and a shared one read-only.
Route::get('/explore/charts/{explorerChart}', [ExplorerController::class, 'show'])
    ->middleware(['can:view,explorerChart', 'feature:advanced_analytics'])->name('explore.charts.show');
Route::get('/explore/charts/{explorerChart}/result', [ExplorerQueryController::class, 'result'])
    ->middleware(['can:view,explorerChart', 'feature:advanced_analytics'])->name('explore.charts.result');
Route::patch('/explore/charts/{explorerChart}', [ExplorerChartController::class, 'update'])
    ->middleware(['can:update,explorerChart', 'feature:advanced_analytics'])->name('explore.charts.update');
Route::delete('/explore/charts/{explorerChart}', [ExplorerChartController::class, 'destroy'])
    ->middleware(['can:delete,explorerChart', 'feature:advanced_analytics'])->name('explore.charts.destroy');
