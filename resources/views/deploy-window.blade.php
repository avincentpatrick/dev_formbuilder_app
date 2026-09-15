{{-- The page served during deploy.ps1's maintenance window (M96, R-8a4c39fb).

     ── IT IS PRERENDERED, SO IT MUST RENDER FROM NOTHING ──────────────────────────────────────────────
     deploy.ps1 runs `php artisan down --retry=15 --render=deploy-window`. DownCommand renders this view
     once and stores the HTML in storage/framework/down, and public/index.php echoes it before Composer's
     autoloader loads, so a browser that navigates here during the window touches neither vendor/ nor
     public/build while the script swaps both. That makes three demands:

      - NO VIEW DATA. `down` passes only `retryAfter`, so the message is fixed here.
      - NO /build/ URL. A link into public/build could 404 mid-swap, so the built stylesheet is INLINED
        through Vite::content(), and the page stays on the design system's tokens.
      - NO BUILD AT ALL MUST STILL RENDER. A fresh clone's first priming run has no public/build (it is
        gitignored), and DownCommand turns any render exception into exit 1, which would stop that deploy
        before its window opened. So the manifest is looked for first, any failure to read it is caught,
        and the page falls back to a minimal style in the browser's own system colours rather than a
        second copy of the design tokens.

     ⚠️ Only browser navigations get this page. A request that expects JSON, or one carrying the bypass
     cookie, still falls through the maintenance stub into the framework during the window.

     tests/Feature/Deploy/DeployWindowViewTest.php renders it with an empty public path and with a fixture
     build, and deliberately without withoutVite(), which would make Vite::content() return ''. --}}
@php
    $deployWindowCss = '';

    if (is_file(public_path('build/manifest.json'))) {
        try {
            $deployWindowCss = \Illuminate\Support\Facades\Vite::content('resources/css/app.css');
        } catch (\Throwable) {
            $deployWindowCss = '';
        }
    }

    // A literal "</style" inside the CSS would close the element early; CSS reads "<\/style" the same way.
    $deployWindowStyles = new \Illuminate\Support\HtmlString($deployWindowCss !== ''
        ? '<style>'.str_ireplace('</style', '<\/style', $deployWindowCss).'</style>'
        : '<style>:root{color-scheme:light dark}body{margin:0;font-family:system-ui,sans-serif;background-color:Canvas;color:CanvasText}</style>');
@endphp
@include('partials.maintenance-page', [
    'styles' => $deployWindowStyles,
    'brand' => null,
    'message' => 'We are updating the site. This usually takes a moment, so please try again shortly.',
])
