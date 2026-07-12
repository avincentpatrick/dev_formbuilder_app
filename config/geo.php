<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Map tile CSP origins
    |--------------------------------------------------------------------------
    |
    | The remote origins the map-bearing pages (the guest form runtime at
    | `GET /f/{slug}` and the manual-encode page) are allowed to load raster
    | map tiles from — the `img-src` allowlist emitted by
    | App\Http\Middleware\PublicRuntimeSecurityHeaders (see ADR-0006 D3).
    |
    | The default is OpenStreetMap's standard tile CDN. Changing the tile
    | source (a self-hosted proxy, a different provider) is a config edit here
    | PLUS the tile URL template in the client GeoInput control — keep the two
    | in sync, or the map will silently fail its tile requests under the CSP.
    |
    */

    'tile_csp_origins' => [
        'https://*.tile.openstreetmap.org',
        'https://tile.openstreetmap.org',
    ],

];
