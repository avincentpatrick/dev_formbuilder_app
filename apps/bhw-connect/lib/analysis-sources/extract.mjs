import { readFile, writeFile, mkdtemp, rm, stat, mkdir, realpath } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join, extname, isAbsolute } from "node:path";
import { pathToFileURL } from "node:url";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { parse } from "csv-parse/sync";
import { unzipSync } from "fflate";
import { DOMParser, parseHTML } from "linkedom";
import { extractText, getDocumentProxy } from "unpdf";
import ExcelJS from "exceljs";
import * as shapefile from "shapefile";
import proj4 from "proj4";
const exec = promisify(execFile);
const MAX_EXPANDED = 64 * 1024 * 1024,
  MAX_CELLS = 2_000_000,
  MAX_TEXT = 2_000_000;
export function unpack(bytes) {
  let count = 0,
    total = 0;
  return unzipSync(bytes, {
    filter: (f) => {
      count++;
      total += f.originalSize;
      if (
        count > 2000 ||
        total > MAX_EXPANDED ||
        f.originalSize > MAX_EXPANDED ||
        /(^|[/\\])\.\.([/\\]|$)|^[\\/]|^[a-z]:/i.test(f.name)
      )
        throw new Error("Archive exceeds extraction limits or has unsafe paths");
      if (/vbaProject\.bin$/i.test(f.name))
        throw new Error("Macro-bearing content is not extracted");
      return true;
    },
  });
}
function decode(bytes) {
  return new TextDecoder("utf-8", { fatal: true }).decode(bytes);
}
function xmlText(bytes, paragraph, run) {
  const doc = new DOMParser().parseFromString(decode(bytes), "text/xml");
  return [...doc.getElementsByTagName(paragraph)]
    .map((p) => [...p.getElementsByTagName(run)].map((n) => n.textContent).join(" "))
    .join("\n");
}
export function tableProfile(name, raw) {
  if (!raw.length)
    return {
      name,
      columns: [],
      rows: [],
      profile: { rowCount: 0, columns: [], duplicates: 0, warnings: ["No data rows"] },
    };
  const columns = [...new Set(raw.flatMap((r) => Object.keys(r)))];
  if (raw.length * columns.length > MAX_CELLS)
    throw new Error("Table exceeds the extraction cell limit; split this file into smaller parts");
  const warnings = [];
  let trimmed = 0;
  const rows = raw.map((row) =>
    Object.fromEntries(
      columns.map((key) => {
        let v = row[key] ?? null;
        if (typeof v === "string") {
          const t = v.trim();
          if (t !== v) trimmed++;
          v = t === "" ? null : t;
          if (
            typeof v === "string" &&
            !/(code|psgc|(^|_)id$)/i.test(key) &&
            /^-?(?:0|[1-9]\d*)(?:\.\d+)?%?$/.test(v)
          )
            v = Number(v.replace(/%$/, ""));
        }
        return [key, v];
      }),
    ),
  );
  const seen = new Set();
  let duplicates = 0;
  for (const row of rows) {
    const s = JSON.stringify(row);
    if (seen.has(s)) duplicates++;
    seen.add(s);
  }
  const profiles = columns.map((column) => {
    const values = rows.map((r) => r[column]);
    const present = values.filter((v) => v !== null);
    const types = [...new Set(present.map((v) => typeof v))];
    const ambiguousDates = present.filter(
      (v) => typeof v === "string" && /^\d{1,2}[/\-]\d{1,2}[/\-]\d{4}$/.test(v),
    ).length;
    const pctCount = raw.filter(
      (r) => typeof r[column] === "string" && r[column].trim().endsWith("%"),
    ).length;
    const nonblank = raw.filter(
      (r) => r[column] !== null && r[column] !== undefined && String(r[column]).trim() !== "",
    ).length;
    const unit =
      pctCount && pctCount < nonblank && !/(pct|percent)/i.test(column)
        ? "mixed"
        : /(pct|percent)/i.test(column) || pctCount
          ? "percent"
          : /(^n_|count|population|denominator|numerator)/i.test(column)
            ? "count"
            : "unknown";
    if (unit === "mixed")
      warnings.push(
        `${column}: mixed explicit percentages and unlabelled values; confirm units before combining`,
      );
    const spellings = new Map();
    for (const value of present)
      if (typeof value === "string") {
        const key = value.toLowerCase();
        const variants = spellings.get(key) ?? new Set();
        variants.add(value);
        spellings.set(key, variants);
      }
    if ([...spellings.values()].some((v) => v.size > 1))
      warnings.push(`${column}: category capitalization variants retained; review before grouping`);
    if (types.length > 1) warnings.push(`${column}: mixed value types`);
    if (ambiguousDates) warnings.push(`${column}: ambiguous date order; dates preserved as text`);
    if (present.some((v) => typeof v === "string" && /\d,\d/.test(v)))
      warnings.push(`${column}: comma-containing numeric text preserved; locale not assumed`);
    return {
      name: column,
      type: types.length === 1 ? types[0] : "mixed",
      missing: values.length - present.length,
      unit,
      ambiguousDates,
    };
  });
  if (duplicates)
    warnings.push("Duplicate rows were retained; their meaning needs review before removal");
  const year = columns.find((k) => /^(year|reporting_year|period)$/i.test(k));
  const years = year
    ? [...new Set(rows.map((r) => String(r[year])))].filter((v) => /^\d{4}$/.test(v))
    : [];
  return {
    name,
    columns,
    rows,
    profile: {
      rowCount: rows.length,
      columns: profiles,
      duplicates,
      trimmed,
      warnings,
      reportingPeriodHint: years.length === 1 ? years[0] : null,
    },
  };
}
function validateGeometry(g, crs) {
  if (!g) return null;
  if (g.type === "GeometryCollection")
    return { ...g, geometries: g.geometries.map((x) => validateGeometry(x, crs)) };
  const walk = (x) => {
    if (!Array.isArray(x)) throw new Error("Invalid geometry coordinates");
    if (typeof x[0] === "number") {
      if (x.length < 2 || !x.every(Number.isFinite)) throw new Error("Invalid geometry coordinate");
      const point = crs ? proj4(crs, "EPSG:4326", x.slice(0, 2)) : x.slice(0, 2);
      if (!point.every(Number.isFinite) || Math.abs(point[0]) > 180 || Math.abs(point[1]) > 90)
        throw new Error("Coordinates are outside WGS84 bounds; provide the correct CRS");
      return [...point, ...x.slice(2)];
    }
    return x.map(walk);
  };
  if (
    !["Point", "MultiPoint", "LineString", "MultiLineString", "Polygon", "MultiPolygon"].includes(
      g.type,
    )
  )
    throw new Error("Unsupported geometry type");
  const coordinates = walk(g.coordinates);
  const polygons =
    g.type === "Polygon" ? [coordinates] : g.type === "MultiPolygon" ? coordinates : [];
  for (const polygon of polygons)
    for (const ring of polygon) {
      if (ring.length < 4 || JSON.stringify(ring[0]) !== JSON.stringify(ring.at(-1)))
        throw new Error("Polygon ring is not closed");
    }
  return { ...g, coordinates };
}
async function convertLegacy(bytes, name) {
  const binary = process.env.BHW_OFFICE_CONVERTER;
  if (!binary || !isAbsolute(binary))
    throw new Error(
      "Legacy Office conversion is unavailable; configure the Office converter or upload DOCX, PPTX or XLSX",
    );
  const dir = await mkdtemp(join(tmpdir(), "bhw-convert-"));
  const extension = extname(name).toLowerCase();
  const target = { ".doc": "docx", ".ppt": "pptx", ".xls": "xlsx" }[extension];
  try {
    await mkdir(join(dir, "profile", "user"), { recursive: true });
    await writeFile(
      join(dir, "profile", "user", "registrymodifications.xcu"),
      '<oor:items xmlns:oor="http://openoffice.org/2001/registry"><item oor:path="/org.openoffice.Office.Common/Security/Scripting"><prop oor:name="MacroSecurityLevel" oor:op="fuse"><value>3</value></prop></item></oor:items>',
    );
    const input = join(dir, `source${extension}`);
    await writeFile(input, bytes);
    await exec(
      binary,
      [
        `-env:UserInstallation=${pathToFileURL(join(dir, "profile")).href}`,
        "--headless",
        "--convert-to",
        target,
        "--outdir",
        dir,
        input,
      ],
      { timeout: 60000, maxBuffer: 1024 * 1024, windowsHide: true },
    );
    const output = join(dir, `source.${target}`);
    if ((await stat(output)).size > MAX_EXPANDED)
      throw new Error("Converted file exceeds extraction limits");
    const result = await extractFile(new Uint8Array(await readFile(output)), `source.${target}`);
    result.preparations.unshift({
      action: "Legacy Office conversion",
      detail: `A derived ${target.toUpperCase()} copy was parsed using a fresh high-security macro profile. Conversion can recalculate spreadsheet formulas; review saved results before inference. The original binary file was preserved.`,
    });
    return result;
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
}
export async function extractFile(bytes, name, options = {}) {
  const ext = extname(name).toLowerCase();
  const pages = [],
    tables = [],
    preparations = [],
    warnings = [];
  let documentTitle, publicationDate;
  try {
    if ([".doc", ".ppt", ".xls"].includes(ext)) return await convertLegacy(bytes, name);
    if (ext === ".csv") {
      const matrix = parse(decode(bytes), {
        bom: true,
        skip_empty_lines: true,
        relax_column_count: false,
      });
      const headers = matrix.shift()?.map(String) ?? [];
      if (new Set(headers).size !== headers.length || headers.some((x) => !x.trim()))
        throw new Error("CSV needs unique, nonblank column headings");
      tables.push(
        tableProfile(
          "CSV",
          matrix.map((row) => Object.fromEntries(headers.map((h, i) => [h, row[i]]))),
        ),
      );
    } else if (ext === ".xlsx") {
      unpack(bytes);
      const workbook = new ExcelJS.Workbook();
      await workbook.xlsx.load(bytes);
      for (const sheet of workbook.worksheets) {
        const cellValue = (cell) => {
          const v = cell.value;
          if (v === null || v === undefined) return null;
          if (v instanceof Date) return v.toISOString();
          if (typeof v !== "object") return v;
          if ("formula" in v || "sharedFormula" in v) {
            warnings.push("Formula cells use saved results only; formulas/macros are not executed");
            return v.result ?? null;
          }
          if (v.richText) return v.richText.map((x) => x.text).join("");
          return cell.text ?? null;
        };
        const headers = sheet
          .getRow(1)
          .values.slice(1)
          .map((v, i) => String(v ?? `Column ${i + 1}`));
        if (new Set(headers).size !== headers.length)
          throw new Error("Excel sheet needs unique column headings");
        const rows = [];
        for (let i = 2; i <= sheet.rowCount; i++) {
          const row = sheet.getRow(i);
          if (row.actualCellCount)
            rows.push(
              Object.fromEntries(headers.map((h, k) => [h, cellValue(row.getCell(k + 1))])),
            );
        }
        tables.push(tableProfile(sheet.name, rows));
      }
    } else if (ext === ".docx") {
      const zip = unpack(bytes);
      if (!zip["word/document.xml"]) throw new Error("Invalid DOCX document");
      pages.push({
        page: 1,
        locator: "Document text",
        text: xmlText(zip["word/document.xml"], "w:p", "w:t"),
      });
      warnings.push("Images and embedded objects were not interpreted");
    } else if (ext === ".pptx") {
      const zip = unpack(bytes);
      const names = Object.keys(zip)
        .filter((x) => /^ppt\/slides\/slide\d+\.xml$/.test(x))
        .sort((a, b) => Number(a.match(/slide(\d+)/)[1]) - Number(b.match(/slide(\d+)/)[1]));
      if (!names.length) throw new Error("No slides found");
      for (const [i, file] of names.entries())
        pages.push({
          page: i + 1,
          locator: `Slide ${i + 1}`,
          text: xmlText(zip[file], "a:p", "a:t"),
        });
      warnings.push(
        "Slide text was extracted; images and chart graphics were not interpreted as data",
      );
    } else if (ext === ".pdf") {
      const pdf = await getDocumentProxy(bytes, { isEvalSupported: false });
      try {
        const meta = await pdf.getMetadata();
        if (typeof meta.info?.Title === "string")
          documentTitle = meta.info.Title.trim().slice(0, 500);
      } catch {
        /* Missing PDF metadata does not imply missing text. */
      }
      const result = await extractText(pdf, { mergePages: false });
      await pdf.destroy?.();
      warnings.push(
        "Text-only PDF extraction; figures, scanned text and image-based tables were not interpreted.",
      );
      result.text.forEach((text, i) =>
        pages.push({ page: i + 1, locator: `PDF page ${i + 1}`, text }),
      );
      if (pages.some((p) => p.text.trim().length < 10))
        warnings.push(
          "Some pages have little or no readable text. OCR is not configured; images were not read.",
        );
    } else if (ext === ".txt") {
      pages.push({ page: 1, locator: "Retrieved plain text", text: decode(bytes) });
    } else if (ext === ".html") {
      const { document } = parseHTML(decode(bytes));
      documentTitle = document.querySelector("title")?.textContent?.trim().slice(0, 500);
      publicationDate = (
        document
          .querySelector('meta[property="article:published_time"],meta[name="date"]')
          ?.getAttribute("content") ??
        document.querySelector("time[datetime]")?.getAttribute("datetime") ??
        ""
      ).slice(0, 100);
      document
        .querySelectorAll("script,style,nav,footer,header,noscript")
        .forEach((n) => n.remove());
      const body = document.querySelector("main,article") ?? document.body ?? document;
      pages.push({
        page: 1,
        locator: "Retrieved webpage",
        text: body.textContent.replace(/\s+/g, " ").trim(),
      });
      for (const [i, t] of [...body.querySelectorAll("table")].entries()) {
        const rows = [...t.querySelectorAll("tr")].map((r) =>
          [...r.querySelectorAll("th,td")].map((c) => c.textContent.trim()),
        );
        const head = rows.shift();
        if (head?.length && new Set(head).size === head.length)
          tables.push(
            tableProfile(
              `Web table ${i + 1}`,
              rows
                .filter((r) => r.length === head.length)
                .map((r) => Object.fromEntries(head.map((h, k) => [h, r[k]]))),
            ),
          );
      }
    } else if ([".geojson", ".json", ".zip"].includes(ext)) {
      let fc,
        crs = options.crs;
      if (ext === ".zip") {
        const zip = unpack(bytes);
        const names = Object.keys(zip);
        const shapes = names.filter((n) => /\.shp$/i.test(n));
        if (shapes.length !== 1) throw new Error("Upload one shapefile set per ZIP");
        const base = shapes[0].slice(0, -4);
        const find = (suffix) =>
          names.find((n) => n.toLowerCase() === (base + suffix).toLowerCase());
        if (!find(".dbf") || !find(".shx"))
          throw new Error("Shapefile ZIP needs SHP, SHX and DBF sidecars");
        crs ??= find(".prj") ? decode(zip[find(".prj")]) : null;
        if (!crs) throw new Error("Shapefile CRS is missing; supply a PRJ file or specify the CRS");
        const buffer = (x) => x.buffer.slice(x.byteOffset, x.byteOffset + x.byteLength);
        fc = await shapefile.read(buffer(zip[shapes[0]]), buffer(zip[find(".dbf")]));
      } else {
        fc = JSON.parse(decode(bytes));
        crs ??= fc.crs?.properties?.name;
      }
      if (fc.type !== "FeatureCollection" || !Array.isArray(fc.features))
        throw new Error("A GeoJSON FeatureCollection is required");
      const rows = fc.features.map((f, i) => ({
        ...f.properties,
        feature_id: f.id ?? i,
        geometry: validateGeometry(f.geometry, crs),
      }));
      tables.push(tableProfile("Geographic features", rows));
      preparations.push({
        action: "Geographic validation",
        detail: crs
          ? `Coordinates transformed from ${crs} to EPSG:4326; original geometries remain in the uploaded file.`
          : "GeoJSON coordinates checked as RFC 7946 WGS84.",
      });
      warnings.push(
        "Coordinate and polygon-ring checks performed; topology and boundary vintage still need review before geographic inference",
      );
    } else throw new Error("Unsupported file format");
    const textLength = pages.reduce((n, p) => n + p.text.length, 0);
    if (textLength > MAX_TEXT)
      throw new Error("Extracted text exceeds processing limits; split this document");
    if (!tables.length && !pages.some((p) => p.text.trim().length > 0))
      throw new Error(
        "No readable text was extracted. Scanned or encrypted content may require OCR or an unlocked file.",
      );
    for (const t of tables) {
      warnings.push(...t.profile.warnings);
      preparations.push({
        action: "Table preparation",
        table: t.name,
        detail:
          "Whitespace trimmed, blank cells represented as null and unambiguous numeric measurements typed. Codes/ambiguous dates and duplicate rows were retained. Original values remain in the original file.",
      });
    }
    return {
      status: "ready",
      pages,
      tables,
      profile: {
        ...(documentTitle ? { title: documentTitle } : {}),
        ...(publicationDate ? { publicationDate } : {}),
        tables: tables.map((t) => ({ name: t.name, ...t.profile })),
        warnings: [...new Set(warnings)],
      },
      preparations,
    };
  } catch (e) {
    return {
      status: "blocked",
      error: e instanceof Error ? e.message : "Extraction failed",
      pages: [],
      tables: [],
      profile: { tables: [], warnings: [] },
      preparations,
    };
  }
}
// Production runs through a current directory junction. Node resolves import.meta.url to
// the real release, while argv keeps the junction path; compare canonical paths.
const entryPath = process.argv[1] ? await realpath(process.argv[1]).catch(() => null) : null;
if (entryPath && import.meta.url === pathToFileURL(entryPath).href) {
  const [input, name, options = "{}", output] = process.argv.slice(2);
  const result = await extractFile(
    new Uint8Array(await readFile(input)),
    name,
    JSON.parse(options),
  );
  if (output) await writeFile(output, JSON.stringify(result));
  else process.stdout.write(JSON.stringify(result));
}
