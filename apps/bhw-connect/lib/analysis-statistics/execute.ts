import "server-only";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { createHash } from "node:crypto";
import { mkdtemp, writeFile, readFile, rm, stat } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join, isAbsolute } from "node:path";
import { statisticSpec, type StatisticalManifest, type StatisticalResult } from "./types";
const exec = promisify(execFile);
function canonical(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(canonical);
  if (value && typeof value === "object")
    return Object.fromEntries(
      Object.entries(value)
        .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
        .map(([key, child]) => [key, canonical(child)]),
    );
  return value;
}
// PostgreSQL JSONB reorders object keys. Integrity hashes must survive that round trip.
export const hash = (value: unknown) =>
  createHash("sha256")
    .update(JSON.stringify(canonical(value)))
    .digest("hex");
export async function executeStatistic(
  manifest: StatisticalManifest,
): Promise<StatisticalResult | { kind: "unsupported-statistical-analysis"; error: string }> {
  const python = process.env.BHW_STATISTICS_PYTHON;
  if (!python || !isAbsolute(python))
    return {
      kind: "unsupported-statistical-analysis",
      error:
        "Statistical worker is not configured. A verified absolute Python executable path is required.",
    };
  manifest = { ...manifest, spec: statisticSpec.parse(manifest.spec) };
  const json = JSON.stringify(manifest);
  if (Buffer.byteLength(json) > 16 * 1024 * 1024)
    throw new Error(
      "Calculation snapshot exceeds the 16 MiB processing limit; narrow the input explicitly.",
    );
  const dir = await mkdtemp(join(tmpdir(), "bhw-statistics-"));
  try {
    const input = join(dir, "input.json"),
      output = join(dir, "output.json");
    await writeFile(input, json, { mode: 0o600 });
    // Only fixed application code runs. No shell, model code, package installation or caller paths.
    await exec(
      python,
      ["-I", join(process.cwd(), "scripts", "statistics", "engine.py"), input, output],
      {
        timeout: 120000,
        maxBuffer: 1024 * 1024,
        windowsHide: true,
        env: {
          ...Object.fromEntries(
            Object.entries(process.env).filter(([key]) =>
              [
                "PATH",
                "Path",
                "SystemRoot",
                "WINDIR",
                "TEMP",
                "TMP",
                "HOME",
                "USERPROFILE",
                "LANG",
                "LC_ALL",
              ].includes(key),
            ),
          ),
          NODE_ENV: process.env.NODE_ENV,
          OMP_NUM_THREADS: "1",
          OPENBLAS_NUM_THREADS: "1",
          MKL_NUM_THREADS: "1",
        },
      },
    );
    if ((await stat(output)).size > 4 * 1024 * 1024)
      throw new Error("Statistical output exceeds processing limits");
    const result = JSON.parse(await readFile(output, "utf8"));
    if (result.kind !== "statistical-result") return result;
    const inputHash = hash(manifest),
      resultHash = hash(result);
    return {
      ...result,
      id: inputHash.slice(0, 16),
      provenance: manifest.provenance,
      inputHash,
      resultHash,
    };
  } catch {
    return {
      kind: "unsupported-statistical-analysis",
      error:
        "Calculation runtime unavailable or processing limit exceeded. Check the configured Python environment and fixed statistical packages.",
    };
  } finally {
    await rm(dir, { force: true, recursive: true });
  }
}
