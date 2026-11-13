import { build } from "esbuild";
await build({
  entryPoints: ["lib/analysis-sources/extract.mjs"],
  outfile: ".next/standalone/analysis-extractor.mjs",
  bundle: true,
  platform: "node",
  target: "node24",
  format: "esm",
  external: ["exceljs", "unpdf"],
  banner: {
    js: "import { createRequire as bhwCreateRequire } from 'node:module'; const require = bhwCreateRequire(import.meta.url);",
  },
});
