import type { NextConfig } from "next";
import { normaliseBasePath } from "./lib/base-path";

const nextConfig: NextConfig = {
  // The prefix the app is served under: empty on a laptop and on Vercel, `/bhw-connect` on the
  // testing box and in CI (Meridian's ADR-0024). Next prefixes its links, redirects and assets with
  // it; everything it does not prefix goes through `withBasePath()` (lib/base-path.ts).
  basePath: normaliseBasePath(process.env.NEXT_PUBLIC_BASE_PATH),

  // A self-contained server (`.next/standalone/server.js`) that the box runs from a release
  // directory, never from the git checkout (ops/deploy-bhw.ps1).
  output: "standalone",

  // This app sits inside a repository with a second lockfile at its root (Meridian's). Without
  // these, Next infers the repository root as the workspace — measured: it does, and warns — and
  // the standalone output nests under it. Both are pinned to this directory.
  outputFileTracingRoot: __dirname,
  turbopack: { root: __dirname },

  // @resvg/resvg-js ships a native .node binding (PNG export rendering,
  // increment 1.8) that Turbopack can't bundle into an ESM chunk — keep it
  // as a real runtime require() instead.
  serverExternalPackages: ["@resvg/resvg-js"],

  // Place pages read boundary GeoJSON from public/geo at render time
  // (lib/geo/locator.ts). Regions/provinces are SSG'd at build where the
  // files exist on disk, but citymun/barangay pages are ISR'd in a
  // serverless function — without this include the tracer wouldn't bundle
  // public/ files into the function and the locator would silently vanish.
  outputFileTracingIncludes: {
    "/place/[geoLevel]/[geoCode]": ["./public/geo/**/*"],
    // Server-side PNG/PPTX exports rasterize with @resvg/resvg-js, which needs
    // real font files at runtime (lib/exports/render-png.ts) — Vercel's
    // serverless runtime has no system fonts, so without bundling the fonts
    // every text node renders blank. The tracer can't see the readFileSync,
    // so include the fonts explicitly for both export functions.
    "/api/export/png": ["./lib/exports/fonts/*.ttf"],
    "/api/export/pptx": ["./lib/exports/fonts/*.ttf"],
    "/api/export/profiling-status": ["./lib/exports/fonts/*.ttf"],
    "/api/export/facilities": ["./lib/exports/fonts/*.ttf"],
    // Missing until now: this route rasterizes with the same resvg + bundled-font path and hit
    // the identical blank-text failure on Vercel (its docblock names DejaVu Sans as the fix, but
    // the include list here never named this route).
    "/api/export/uuc-phc": ["./lib/exports/fonts/*.ttf"],
  },
};

export default nextConfig;
