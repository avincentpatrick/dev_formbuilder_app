#!/usr/bin/env python3
"""The 2026 UUC for PHC list -> the cleaned extract ingestion/ingest_uuc_phc.py loads.

Two inputs, because the source office sends two things:

  --src   The 2026 assessment workbook (sheet "2026"): one row per listed barangay with every
          indicator, the provincial benchmarks, and the office's own Pass/Fail columns. It has
          the same 42-column layout as the 2025 "Reconciled" sheet, column for column, so the
          2025 cleaning rules apply to it unchanged — they are imported from
          clean_uuc_phc_indicators.py, not restated here.
  --list  The 2026 list as published (sheet "NATIONAL"): region, province, city/municipality,
          barangay and PSGC code, on the 2025 list's own template. This is the source for WHO is
          on the list, for every PSGC code, and for the region each barangay is filed under.

The two must agree on membership exactly: every list row has one workbook row and the reverse,
matched by province / city-municipality / barangay name. A barangay in one and not the other
fails the run — the 2025 build found six such rows only after the fact (docs/UUC_PHC_2025_PLAN.md
§3), so here it is a load-blocking error rather than a reconciliation.

**Sulu is filed under Region IX.** The workbook still files Sulu's 87 barangays under BARMM; the
list files them under Region IX (Zamboanga Peninsula), as the 2025 list did and as the app does
everywhere (20261123100000_sulu_region_ix.sql). The region comes from the list, so the extract
carries Region IX. The codes are the post-transfer '09066…' ones, which resolve through the same
crosswalk the 2025 load uses.

**Names come from the list.** Two of the workbook's spellings differ from the list's and from
each other ("ILAGAN CITY" beside "CITY OF ILAGAN" for one municipality); the list is the published
document, so its names are the ones the source_* columns record.

The cleaning rules are the 2025 rules exactly (see clean_uuc_phc_indicators.py's docstring):
coverage percentages capped at 100, rates per 1,000 capped at 1,000, negatives set to 0, the
Water and Pre-natal provincial benchmarks capped at 100, "#N/A" blanked, and the Pass/Fail
columns dropped. Each capped value is flagged per indicator in capped_indicators.

Usage:

    python ingestion/clean_uuc_phc_2026.py \\
        --src  ingestion/data/uuc_phc_2026_source.xlsx \\
        --list ingestion/data/uuc_phc_2026_list.xlsx \\
        --data-csv ingestion/data/uuc_phc_2026_cleaned.csv \\
        --ref-csv  ingestion/data/uuc_phc_2026_provincial_reference.csv

then generate the seed migrations with ingest_uuc_phc.py --edition 2026.
"""

import argparse
import csv
import re
import sys
import unicodedata
from collections import Counter
from pathlib import Path

import openpyxl

sys.path.insert(0, str(Path(__file__).resolve().parent))
from clean_uuc_phc_indicators import (  # noqa: E402  (the 2025 rules, reused verbatim)
    CAP_100,
    CAP_1000,
    DROP_COLS,
    INDICATORS,
    NEG_ACTION,
    PROV_REF,
    apply_rules,
    csv_name,
    is_na,
    provincial_reference,
)

SOURCE_SHEET = "2026"
LIST_SHEET = "NATIONAL"
EXPECTED_HEADER_PREFIX = ["Region", "province", "profile-brgy/muncity_pull",
                          "profile-brgy/barangay_pull", "Physical Factor"]
REGION_IX = "REGION IX (ZAMBOANGA PENINSULA)"


def key(value) -> str:
    """Name key for matching the workbook to the list: accents, punctuation and bracketed
    aliases dropped, "X CITY" read as "CITY OF X", and the one renamed municipality aliased."""
    s = unicodedata.normalize("NFKD", str(value or "")).encode("ascii", "ignore").decode().upper()
    s = re.sub(r"\(.*?\)", "", s)
    s = " ".join(re.sub(r"[^A-Z0-9 ]", " ", s).split())
    m = re.match(r"^(.*) CITY$", s)
    if m and not s.startswith("CITY OF"):
        s = "CITY OF " + m.group(1)
    return {"MONTALBAN": "RODRIGUEZ"}.get(s, s)


def read_list(path):
    """The published list: [(region, province, citymun, barangay, psgc)] in list order."""
    ws = openpyxl.load_workbook(path, read_only=True, data_only=True)[LIST_SHEET]
    out, region, in_blocks = [], None, False
    for r in ws.iter_rows(values_only=True):
        a, b, c, d = (list(r) + [None] * 4)[:4]
        # A region band is a row with only its first cell filled. The title rows above the
        # summary look the same, but each block's band comes after them and wins.
        if a and b is None and c is None and d is None:
            region = str(a).strip()
            continue
        if a == "PROVINCE" and d == "PSGC CODE":
            in_blocks = True
            continue
        if not in_blocks:
            continue
        if not (a and b and c and d) or a == "TOTAL":
            continue
        code = str(d).strip()
        if code.isdigit():
            code = code.zfill(10)
        out.append((region, str(a).strip(), str(b).strip(), str(c).strip(), code))
    return out


def build(src, list_path, data_csv, ref_csv):
    ws = openpyxl.load_workbook(src, read_only=True, data_only=True)[SOURCE_SHEET]
    allr = list(ws.iter_rows(values_only=True))
    HDR = list(allr[0])
    if [str(h) for h in HDR[:5]] != EXPECTED_HEADER_PREFIX or len(HDR) != 42:
        raise SystemExit(f"unexpected workbook layout: {HDR[:6]}… ({len(HDR)} columns)")
    rows = [list(r) for r in allr[1:] if any(c is not None for c in r)]

    listed = read_list(list_path)
    problems = []
    bad_codes = [x[4] for x in listed if not (len(x[4]) == 10 and x[4].isdigit())]
    if bad_codes:
        problems.append(f"{len(bad_codes)} list rows without a 10-digit PSGC, e.g. {bad_codes[:3]}")
    dup_codes = [c for c, n in Counter(x[4] for x in listed).items() if n > 1]
    if dup_codes:
        problems.append(f"{len(dup_codes)} duplicate PSGC codes on the list, e.g. {dup_codes[:3]}")

    def k3(prov, mun, brgy):
        return (key(prov), key(mun), key(brgy))

    by_key = {}
    for r in rows:
        k = k3(r[1], r[2], r[3])
        if k in by_key:
            problems.append(f"workbook carries {k} twice")
        by_key[k] = r
    list_keys = [k3(x[1], x[2], x[3]) for x in listed]
    missing = [k for k in list_keys if k not in by_key]
    extra = sorted(set(by_key) - set(list_keys))
    if missing:
        problems.append(f"{len(missing)} list barangays have no workbook row, e.g. {missing[:3]}")
    if extra:
        problems.append(f"{len(extra)} workbook rows are not on the list, e.g. {extra[:3]}")
    decisions = {str(r[41]).strip().upper() for r in rows}
    if decisions != {"UUA"}:
        problems.append(f"expected only UUA rows in the workbook, found {sorted(decisions)}")
    if problems:
        raise SystemExit("2026 inputs failed their checks:\n  - " + "\n  - ".join(problems))

    # Rows in LIST order, carrying the list's region and names. Only the four geography cells are
    # replaced; every measurement is the workbook's.
    out_rows, psgc_list = [], []
    for (region, prov, mun, brgy, code), k in zip(listed, list_keys):
        r = list(by_key[k])
        r[0], r[1], r[2], r[3] = region, prov, mun, brgy
        out_rows.append(r)
        psgc_list.append(code)

    n_na = sum(1 for r in out_rows for v in r if is_na(v))
    before = [list(r) for r in out_rows]
    actions = apply_rules(out_rows)
    prov_recs, n_prov_capped = provincial_reference(out_rows, HDR, before)
    keep_idx = [i for i in range(len(HDR)) if i not in DROP_COLS]
    n_cap100 = sum(1 for a in actions if a[1] in CAP_100 and a[4].startswith("Capped"))
    n_cap1000 = sum(1 for a in actions if a[1] in CAP_1000 and a[4].startswith("Capped"))
    n_neg = sum(1 for a in actions if a[4] == NEG_ACTION)
    n_sulu_ix = sum(1 for r in out_rows if str(r[1]).upper() == "SULU" and r[0] == REGION_IX)
    print(f"rows={len(out_rows)} cap100={n_cap100} cap1000={n_cap1000} neg={n_neg} "
          f"na_blanked={n_na} provinces={len(prov_recs)} prov_ref_capped={n_prov_capped} "
          f"sulu_in_region_ix={n_sulu_ix}")

    act_cells = {(a[0], a[1]) for a in actions}
    capped_by_row = {}
    for ri, _ci, label, _orig, action, _new in actions:
        if action.startswith("Capped"):
            capped_by_row.setdefault(ri, set()).add(csv_name(label))
    Path(data_csv).parent.mkdir(parents=True, exist_ok=True)
    with open(data_csv, "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["psgc"] + [csv_name(HDR[i]) for i in keep_idx]
                   + ["values_capped", "capped_indicators"])
        for ri, r in enumerate(out_rows):
            n = sum(1 for ci in INDICATORS if (ri, ci) in act_cells)
            w.writerow([psgc_list[ri]]
                       + ["" if r[ci] is None else r[ci] for ci in keep_idx]
                       + [n, "|".join(sorted(capped_by_row.get(ri, ())))])
    print(f"written: {data_csv} ({len(out_rows):,} barangays)")

    if ref_csv:
        labs = list(PROV_REF.values())
        with open(ref_csv, "w", newline="", encoding="utf-8") as fh:
            w = csv.writer(fh)
            w.writerow(["region", "province", "barangays_in_list"]
                       + [f'ref_{lab.lower().replace("-", "_")}' for lab in labs]
                       + ["adjusted_to_100"])
            for rec in prov_recs:
                w.writerow([rec["region"], rec["province"], rec["barangays"]]
                           + [rec["vals"].get(lab, "") for lab in labs]
                           + ["|".join(sorted(rec["capped"]))])
        print(f"written: {ref_csv} ({len(prov_recs)} provinces)")


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--src", default="ingestion/data/uuc_phc_2026_source.xlsx")
    ap.add_argument("--list", default="ingestion/data/uuc_phc_2026_list.xlsx")
    ap.add_argument("--data-csv", default="ingestion/data/uuc_phc_2026_cleaned.csv")
    ap.add_argument("--ref-csv", default="ingestion/data/uuc_phc_2026_provincial_reference.csv")
    args = ap.parse_args()
    build(args.src, args.list, args.data_csv, args.ref_csv)
