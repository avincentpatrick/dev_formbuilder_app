"""Synthetic-only local budget probe; not the production extraction pipeline.
Run: python ingestion/measure_document_limits.py
Requires PyMuPDF and local Tesseract (eng). Creates no persistent PDF or text.
"""
import json
import platform
import subprocess
import tempfile
import time
from pathlib import Path

import fitz


def run():
    # Rasterize a synthetic page so image-only PDFs really need OCR.
    template = fitz.open()
    page = template.new_page()
    page.insert_text((72, 100), "SYNTHETIC POLICY ALPHA", fontsize=20)
    page.insert_text((72, 150), "Every clinic must retain a signed referral record.", fontsize=16)
    raster = page.get_pixmap(dpi=200).tobytes("png")
    report = {"python": platform.python_version(), "pymupdf": fitz.__version__,
              "tesseract": subprocess.check_output(["tesseract", "--version"], text=True).splitlines()[0],
              "languages": subprocess.check_output(["tesseract", "--list-langs"], text=True).splitlines(),
              "samples": []}
    with tempfile.TemporaryDirectory(prefix="bhw-policy-probe-") as folder:
        root = Path(folder)
        for kind in ["selectable", "scan", "mixed_page", "mixed_document"]:
            doc = fitz.open()
            page = doc.new_page()
            if kind != "selectable":
                page.insert_image(page.rect, stream=raster)
            if kind in ["selectable", "mixed_page"]:
                page.insert_text((72, 40), "SELECTABLE HEADER BETA", fontsize=16)
            if kind == "mixed_document":
                page = doc.new_page()
                page.insert_text((72, 100), "SELECTABLE ANNEX GAMMA", fontsize=16)
            pdf = doc.tobytes(deflate=True)
            started = time.monotonic()
            loaded = fitz.open(stream=pdf, filetype="pdf")
            text = "".join(p.get_text() for p in loaded)
            native_seconds = time.monotonic() - started
            ocr = []
            for index, page in enumerate(loaded):
                if not page.get_images():
                    continue
                image = root / "page.png"
                page.get_pixmap(dpi=200).save(image)
                started = time.monotonic()
                result = subprocess.run(["tesseract", str(image), "stdout", "-l", "eng", "--psm", "3"],
                                        capture_output=True, text=True, timeout=60, check=True)
                ocr.append({"page": index+1, "seconds": round(time.monotonic()-started,3),
                            "found_scan_clause": "signed referral record" in result.stdout.lower()})
            if kind != "selectable":
                assert ocr and all(p["found_scan_clause"] for p in ocr)
            report["samples"].append({"kind": kind, "bytes": len(pdf), "pages":len(loaded),
                                      "native_characters": len(text), "native_seconds": round(native_seconds,3),
                                      "ocr":ocr})
        # Measure repeated image pages; a linear estimate is only a planning estimate.
        doc = fitz.open()
        for _ in range(10):
            page = doc.new_page()
            page.insert_image(page.rect, stream=raster)
        report["ten_scan_pages_bytes"] = len(doc.tobytes(deflate=True))
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    run()
